import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { AddRecurringChargeDialog, type Category } from '@/components/recurring-charge-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents, parsePesos } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarCheck, Plus, Receipt, Repeat, Search, Zap } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Recurring {
    id: number;
    tenant: string;
    room: string;
    category_id: number;
    category: string;
    label: string;
    amount_cents: number;
    amount: string;
    start_date: string;
    end_date: string | null;
    state: 'running' | 'scheduled' | 'ended' | 'paused';
}
interface OneTime {
    id: number;
    tenant: string;
    account_no: string;
    category: string;
    label: string;
    kind: 'charge' | 'credit';
    amount: string;
    bill_month: string;
    billed: boolean;
    reason: string | null;
}
interface Options {
    agreements: { id: number; label: string }[];
    accounts: { id: number; label: string; agreements: { id: number; label: string }[] }[];
}
interface Props {
    recurring: Recurring[];
    oneTime: OneTime[];
    categories: Category[];
    canManage: boolean;
    options: Options | null;
    today: string;
}

const stateLabels: Record<Recurring['state'], string> = { running: 'Active', scheduled: 'Starts later', ended: 'Stopped', paused: 'Paused' };
const kindLabels: Record<string, string> = { rent: 'Rent', appliance: 'Appliance', utility: 'Utility', service: 'Service', other: 'Other' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Extra charges', href: '/charges' }];
const monthLabel = (ym: string) => new Date(ym + '-01T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long' });

function Empty({ children }: { children: string }) {
    return <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">{children}</p>;
}

/** Edit amount or name of an add-on; affects future bills only. */
function EditRecurringDialog({
    charge,
    categories,
    open,
    onOpenChange,
}: {
    charge: Recurring;
    categories: Category[];
    open: boolean;
    onOpenChange: (o: boolean) => void;
}) {
    const form = useForm({ charge_category_id: String(charge.category_id), label: charge.label, amount: (charge.amount_cents / 100).toFixed(2) });
    const errors = form.errors as Record<string, string | undefined>;
    const cents = parsePesos(form.data.amount);
    useEffect(() => {
        if (open)
            form.setData({ charge_category_id: String(charge.category_id), label: charge.label, amount: (charge.amount_cents / 100).toFixed(2) });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, charge.id]);
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('recurring-charges.update', charge.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Edit {charge.label}</DialogTitle>
                        <DialogDescription>Applies to bills made from now on. Bills already issued keep their amounts.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="er-category">Charge type</Label>
                        <SearchSelect
                            id="er-category"
                            value={String(form.data.charge_category_id)}
                            onChange={(v) => form.setData('charge_category_id', v)}
                            options={categories.map((c) => ({ value: String(c.id), label: c.name }))}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="er-label">Name on the bill</Label>
                        <Input id="er-label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} required />
                        <InputError message={errors.label} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="er-amount">Amount each month (pesos)</Label>
                        <Input
                            id="er-amount"
                            inputMode="decimal"
                            className="max-w-48"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            required
                        />
                        <InputError message={errors.amount} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || !cents || !form.data.label.trim()}>{form.processing ? 'Saving...' : 'Save'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EndRecurringDialog({
    charge,
    today,
    open,
    onOpenChange,
}: {
    charge: Recurring;
    today: string;
    open: boolean;
    onOpenChange: (o: boolean) => void;
}) {
    const form = useForm({ end_date: today });
    const errors = form.errors as Record<string, string | undefined>;
    useEffect(() => {
        if (open) form.setData({ end_date: charge.end_date ?? today });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, charge.id]);
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('recurring-charges.end', charge.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Stop {charge.label}</DialogTitle>
                        <DialogDescription>
                            It will not be added to bills for periods after the last day. Past bills are not changed, and the record is kept.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="end-date">Last day charged</Label>
                        <Input
                            id="end-date"
                            type="date"
                            value={form.data.end_date}
                            min={charge.start_date}
                            onChange={(e) => form.setData('end_date', e.target.value)}
                            required
                        />
                        <InputError message={errors.end_date} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>{form.processing ? 'Saving...' : 'Stop charge'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function OneTimeDialog({
    options,
    categories,
    today,
    open,
    onOpenChange,
}: {
    options: Options;
    categories: Category[];
    today: string;
    open: boolean;
    onOpenChange: (o: boolean) => void;
}) {
    const initial = {
        billing_account_id: '',
        rental_agreement_id: '',
        charge_category_id: '',
        label: '',
        kind: 'charge',
        amount: '',
        bill_month: today.slice(0, 7),
        reason: '',
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const cents = parsePesos(form.data.amount);
    const account = options.accounts.find((a) => String(a.id) === form.data.billing_account_id);
    useEffect(() => {
        if (open) {
            form.setData(initial);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('one-time-charges.store'), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Add a one-time charge or discount</DialogTitle>
                        <DialogDescription>Goes on the first bill made for that tenant in the month you pick, once only.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="ot-account">Tenant account</Label>
                        <SearchSelect
                            id="ot-account"
                            value={String(form.data.billing_account_id)}
                            onChange={(v) => form.setData((d) => ({ ...d, billing_account_id: v, rental_agreement_id: '' }))}
                            placeholder="Search tenant or account…"
                            options={options.accounts.map((a) => ({ value: String(a.id), label: a.label }))}
                        />
                        <InputError message={errors.billing_account_id} />
                    </div>
                    {account && account.agreements.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="ot-agreement">Room (optional)</Label>
                            <SearchSelect
                                id="ot-agreement"
                                value={String(form.data.rental_agreement_id)}
                                onChange={(v) => form.setData('rental_agreement_id', v)}
                                options={[
                                    { value: '', label: 'Whole account' },
                                    ...account.agreements.map((g) => ({ value: String(g.id), label: g.label })),
                                ]}
                            />
                            <InputError message={errors.rental_agreement_id} />
                        </div>
                    )}
                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">Type</legend>
                        <div className="flex gap-4 text-sm">
                            {[
                                ['charge', 'Charge (adds to the bill)'],
                                ['credit', 'Discount / credit (lowers the bill)'],
                            ].map(([value, label]) => (
                                <label key={value} className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={value}
                                        checked={form.data.kind === value}
                                        onChange={() => form.setData('kind', value)}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="grid gap-2">
                        <Label htmlFor="ot-category">Charge type</Label>
                        <SearchSelect
                            id="ot-category"
                            value={String(form.data.charge_category_id)}
                            onChange={(v) => form.setData('charge_category_id', v)}
                            placeholder="Search charge type…"
                            options={categories.map((c) => ({ value: String(c.id), label: c.name }))}
                        />
                        <InputError message={errors.charge_category_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ot-label">Name on the bill</Label>
                        <Input id="ot-label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} required />
                        <InputError message={errors.label} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="ot-amount">Amount (pesos)</Label>
                            <Input
                                id="ot-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                required
                            />
                            <p className="text-muted-foreground text-sm">
                                {cents ? `${form.data.kind === 'credit' ? '-' : ''}${formatCents(cents)}` : 'Like 100 or 1,250.50'}
                            </p>
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ot-month">Bill month</Label>
                            <Input
                                id="ot-month"
                                type="month"
                                value={form.data.bill_month}
                                onChange={(e) => form.setData('bill_month', e.target.value)}
                                required
                            />
                            <InputError message={errors.bill_month} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ot-reason">Reason (optional)</Label>
                        <Input id="ot-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                form.processing || !cents || !form.data.billing_account_id || !form.data.charge_category_id || !form.data.label.trim()
                            }
                        >
                            {form.processing ? 'Saving...' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CategoriesSection({ categories, canManage }: { categories: Category[]; canManage: boolean }) {
    const form = useForm({ name: '', kind: 'service' });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('charge-categories.store'), { preserveScroll: true, onSuccess: () => form.reset('name') });
    };
    const [renaming, setRenaming] = useState<Category | null>(null);
    const [newName, setNewName] = useState('');
    const rename = (c: Category) => {
        setRenaming(c);
        setNewName(c.name);
    };
    const [renameError, setRenameError] = useState<string | undefined>();
    const saveRename: FormEventHandler = (e) => {
        e.preventDefault();
        if (!renaming) return;
        router.patch(
            route('charge-categories.update', renaming.id),
            { name: newName },
            {
                preserveScroll: true,
                onSuccess: () => setRenaming(null),
                onError: (errs) => setRenameError(errs.name),
            },
        );
    };
    return (
        <div className="space-y-4">
            <ul className="divide-y rounded-lg border">
                {categories.map((c) => (
                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                        <span className="font-medium">{c.name}</span>
                        <span className="flex items-center gap-2">
                            <Badge variant="outline">{kindLabels[c.kind] ?? c.kind}</Badge>
                            {c.is_system ? (
                                <span className="text-muted-foreground">Built in</span>
                            ) : (
                                canManage && (
                                    <Button size="sm" variant="outline" onClick={() => rename(c)}>
                                        Rename
                                    </Button>
                                )
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            <Dialog open={renaming !== null} onOpenChange={(o) => !o && setRenaming(null)}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={saveRename} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Rename category</DialogTitle>
                            <DialogDescription>Past bills keep the name they were made with.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="rename-category">Category name</Label>
                            <Input
                                id="rename-category"
                                value={newName}
                                onChange={(e) => {
                                    setNewName(e.target.value);
                                    setRenameError(undefined);
                                }}
                                required
                            />
                            <InputError message={renameError} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="secondary" onClick={() => setRenaming(null)}>
                                Cancel
                            </Button>
                            <Button disabled={!newName.trim()}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            {canManage && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="cat-name">New category</Label>
                        <Input id="cat-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cat-kind">Type</Label>
                        <SearchSelect
                            id="cat-kind"
                            value={form.data.kind}
                            onChange={(v) => form.setData('kind', v)}
                            options={Object.entries(kindLabels).map(([value, label]) => ({ value, label }))}
                        />
                    </div>
                    <Button disabled={form.processing || !form.data.name.trim()}>
                        <Plus /> Add category
                    </Button>
                </form>
            )}
        </div>
    );
}

/** Step 1 of "Add charge": every month or just once? Then (for monthly) which boarder. */
function AddChargeChooser({
    options,
    open,
    onOpenChange,
    onMonthly,
    onOnce,
}: {
    options: Options;
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onMonthly: (agreementId: string) => void;
    onOnce: () => void;
}) {
    const [kind, setKind] = useState<'monthly' | 'once' | null>(null);
    const [agreementId, setAgreementId] = useState('');
    useEffect(() => {
        if (open) {
            setKind(null);
            setAgreementId('');
        }
    }, [open]);
    const choice = (value: 'monthly' | 'once', title: string, text: string, Icon: typeof Repeat) => (
        <button
            type="button"
            onClick={() => setKind(value)}
            aria-pressed={kind === value}
            className={cn(
                'focus-visible:ring-ring flex gap-3 rounded-xl border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                kind === value ? 'border-primary bg-accent' : 'hover:bg-muted/60',
            )}
        >
            <Icon className="text-primary mt-0.5 size-5 shrink-0" aria-hidden />
            <span>
                <span className="block font-medium">{title}</span>
                <span className="text-muted-foreground block text-sm">{text}</span>
            </span>
        </button>
    );
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add a charge</DialogTitle>
                    <DialogDescription>How often should it be on the boarder's bill?</DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    {choice('monthly', 'Every month', 'Like a rice cooker or Wi-Fi fee. Stays on every bill until you end it.', Repeat)}
                    {choice('once', 'Just once (or a discount)', 'Like a repair, a lost key, or a credit that lowers one bill.', CalendarCheck)}
                </div>
                {kind === 'monthly' && (
                    <div className="grid gap-2">
                        <Label htmlFor="ch-agreement">Which boarder?</Label>
                        <SearchSelect
                            id="ch-agreement"
                            value={agreementId}
                            onChange={setAgreementId}
                            placeholder="Search name or room…"
                            options={options.agreements.map((a) => ({ value: String(a.id), label: a.label }))}
                        />
                    </div>
                )}
                <DialogFooter className="gap-2">
                    <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={kind === null || (kind === 'monthly' && !agreementId)}
                        onClick={() => (kind === 'monthly' ? onMonthly(agreementId) : onOnce())}
                    >
                        Next <ArrowRight />
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const stateVariant: Record<Recurring['state'], 'success' | 'info' | 'muted' | 'warning'> = {
    running: 'success',
    scheduled: 'info',
    ended: 'muted',
    paused: 'warning',
};

export default function Charges({ recurring, oneTime, categories, canManage, options, today }: Props) {
    const [tab, setTab] = useState<'recurring' | 'one-time' | 'categories'>('recurring');
    const [chooserOpen, setChooserOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [agreementId, setAgreementId] = useState('');
    const [oneOpen, setOneOpen] = useState(false);
    const [selected, setSelected] = useState<Recurring | null>(null);
    const [mode, setMode] = useState<'edit' | 'end' | null>(null);
    const [search, setSearch] = useState('');
    const [showEnded, setShowEnded] = useState(false);
    const [billedFilter, setBilledFilter] = useState<'waiting' | 'billed' | 'all'>('all');
    const { flash } = usePage<SharedData>().props;
    const agreement = options?.agreements.find((a) => String(a.id) === agreementId);

    const q = search.trim().toLowerCase();
    const matches = (...parts: (string | null)[]) => q === '' || parts.join(' ').toLowerCase().includes(q);
    const ended = recurring.filter((c) => c.state === 'ended').length;
    const visibleRecurring = recurring.filter((c) => (showEnded || c.state !== 'ended') && matches(c.tenant, c.room, c.label, c.category));
    const waiting = oneTime.filter((c) => !c.billed).length;
    const visibleOneTime = oneTime.filter(
        (c) => (billedFilter === 'all' || (billedFilter === 'billed') === c.billed) && matches(c.tenant, c.account_no, c.label, c.category, c.reason),
    );

    const tabs = [
        ['recurring', 'Every month', recurring.length - ended],
        ['one-time', 'One-time & discounts', oneTime.length],
        ['categories', 'Charge types', categories.length],
    ] as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Extra charges" />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Receipt}
                    title="Extra charges"
                    description="Anything on a bill besides rent and electricity: add-ons like a rice cooker, one-time fees like a repair, or a discount."
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('utilities.index')}>
                                    <Zap /> Electricity
                                </Link>
                            </Button>
                            {canManage && options && (
                                <Button onClick={() => setChooserOpen(true)}>
                                    <Plus /> Add charge
                                </Button>
                            )}
                        </>
                    }
                />

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <div role="tablist" aria-label="Charges" className="bg-muted/60 inline-flex max-w-full flex-wrap gap-1 rounded-xl border p-1">
                        {tabs.map(([key, label, count]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key)}
                                className={cn(
                                    'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    tab === key ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                                <span className="bg-muted rounded-full px-1.5 font-mono text-xs tabular-nums">{count}</span>
                            </button>
                        ))}
                    </div>
                    {tab !== 'categories' && (
                        <div className="relative ml-auto w-full sm:w-72">
                            <Search
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search boarder, room or charge"
                                aria-label="Search boarder, room or charge"
                                className="pl-8"
                            />
                        </div>
                    )}
                </div>

                {tab === 'recurring' && (
                    <section aria-label="Every-month charges" className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-muted-foreground text-sm">Added to every monthly bill from the start date until you end it.</p>
                            {ended > 0 && (
                                <label className="text-muted-foreground flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={showEnded} onChange={(e) => setShowEnded(e.target.checked)} />
                                    Show {ended} ended
                                </label>
                            )}
                        </div>
                        {visibleRecurring.length === 0 ? (
                            <Empty>{recurring.length === 0 ? 'No every-month charges yet. Use "Add charge" to add one.' : 'Nothing matches.'}</Empty>
                        ) : (
                            <ul className="surface divide-y">
                                {visibleRecurring.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {c.label} <span className="text-muted-foreground font-normal">· {c.category}</span>
                                            </p>
                                            <p className="text-muted-foreground">
                                                {c.tenant}, room {c.room} · from {formatDate(c.start_date)}
                                                {c.end_date && ` to ${formatDate(c.end_date)}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono font-semibold tabular-nums">{c.amount}</span>
                                            <span className="text-muted-foreground text-xs">/ month</span>
                                            <Badge variant={stateVariant[c.state]}>{stateLabels[c.state]}</Badge>
                                            {canManage && c.state !== 'ended' && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSelected(c);
                                                            setMode('edit');
                                                        }}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => {
                                                            setSelected(c);
                                                            setMode('end');
                                                        }}
                                                    >
                                                        Stop
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {tab === 'one-time' && (
                    <section aria-label="One-time charges and discounts" className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-muted-foreground text-sm">Goes on one bill only, in the month you picked.</p>
                            <div className="bg-muted/60 inline-flex gap-1 rounded-lg p-1 text-xs" role="group" aria-label="Show">
                                {(
                                    [
                                        ['all', `All ${oneTime.length}`],
                                        ['waiting', `Waiting for a bill ${waiting}`],
                                        ['billed', `On a bill ${oneTime.length - waiting}`],
                                    ] as const
                                ).map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        aria-pressed={billedFilter === key}
                                        onClick={() => setBilledFilter(key)}
                                        className={cn(
                                            'rounded-md px-2.5 py-1 font-medium',
                                            billedFilter === key
                                                ? 'bg-card text-foreground shadow-soft'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {visibleOneTime.length === 0 ? (
                            <Empty>{oneTime.length === 0 ? 'No one-time charges or discounts yet.' : 'Nothing matches.'}</Empty>
                        ) : (
                            <ul className="surface divide-y">
                                {visibleOneTime.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {c.label} <span className="text-muted-foreground font-normal">· {c.category}</span>
                                            </p>
                                            <p className="text-muted-foreground">
                                                {c.tenant} ({c.account_no}) · {monthLabel(c.bill_month)} bill
                                                {c.reason && ` · ${c.reason}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className={cn('font-mono font-semibold tabular-nums', c.kind === 'credit' && 'text-success')}>
                                                {c.kind === 'credit' ? `− ${c.amount}` : c.amount}
                                            </span>
                                            {c.kind === 'credit' && <Badge variant="success">Discount</Badge>}
                                            <Badge variant={c.billed ? 'muted' : 'info'}>{c.billed ? 'On a bill' : 'Waiting for the bill'}</Badge>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {tab === 'categories' && (
                    <section aria-label="Charge types" className="space-y-3">
                        <p className="text-muted-foreground text-sm">
                            The list of names you pick from when adding a charge. Built-in types cannot be renamed.
                        </p>
                        <CategoriesSection categories={categories} canManage={canManage} />
                    </section>
                )}
            </div>

            {canManage && options && (
                <AddChargeChooser
                    options={options}
                    open={chooserOpen}
                    onOpenChange={setChooserOpen}
                    onMonthly={(id) => {
                        setAgreementId(id);
                        setChooserOpen(false);
                        setAddOpen(true);
                    }}
                    onOnce={() => {
                        setChooserOpen(false);
                        setOneOpen(true);
                    }}
                />
            )}
            {canManage && agreement && (
                <AddRecurringChargeDialog
                    agreementId={agreement.id}
                    room={agreement.label}
                    categories={categories}
                    today={today}
                    open={addOpen}
                    onOpenChange={setAddOpen}
                />
            )}
            {canManage && options && (
                <OneTimeDialog options={options} categories={categories} today={today} open={oneOpen} onOpenChange={setOneOpen} />
            )}
            {canManage && selected && mode === 'edit' && (
                <EditRecurringDialog charge={selected} categories={categories} open onOpenChange={() => setMode(null)} />
            )}
            {canManage && selected && mode === 'end' && (
                <EndRecurringDialog charge={selected} today={today} open onOpenChange={() => setMode(null)} />
            )}
        </AppLayout>
    );
}
