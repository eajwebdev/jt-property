import InputError from '@/components/input-error';
import { AddRecurringChargeDialog, type Category, selectClass } from '@/components/recurring-charge-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents, parsePesos } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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

const stateLabels: Record<Recurring['state'], string> = { running: 'Running', scheduled: 'Starts later', ended: 'Ended', paused: 'Paused' };
const kindLabels: Record<string, string> = { rent: 'Rent', appliance: 'Appliance', utility: 'Utility', service: 'Service', other: 'Other' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Charges & Utilities', href: '/charges' }];
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
                        <Label htmlFor="er-category">Category</Label>
                        <select
                            id="er-category"
                            className={selectClass}
                            value={form.data.charge_category_id}
                            onChange={(e) => form.setData('charge_category_id', e.target.value)}
                        >
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
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
                        <DialogTitle>End {charge.label}</DialogTitle>
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
                        <Button disabled={form.processing}>{form.processing ? 'Saving...' : 'End charge'}</Button>
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
                        <DialogTitle>Add a one-time charge or credit</DialogTitle>
                        <DialogDescription>Goes on the first bill made for that tenant in the month you pick, once only.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="ot-account">Tenant account</Label>
                        <select
                            id="ot-account"
                            className={selectClass}
                            value={form.data.billing_account_id}
                            onChange={(e) => form.setData((d) => ({ ...d, billing_account_id: e.target.value, rental_agreement_id: '' }))}
                            required
                        >
                            <option value="">Choose...</option>
                            {options.accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.billing_account_id} />
                    </div>
                    {account && account.agreements.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="ot-agreement">Room (optional)</Label>
                            <select
                                id="ot-agreement"
                                className={selectClass}
                                value={form.data.rental_agreement_id}
                                onChange={(e) => form.setData('rental_agreement_id', e.target.value)}
                            >
                                <option value="">Whole account</option>
                                {account.agreements.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.rental_agreement_id} />
                        </div>
                    )}
                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">Type</legend>
                        <div className="flex gap-4 text-sm">
                            {[
                                ['charge', 'Charge (adds to the bill)'],
                                ['credit', 'Credit (reduces the bill)'],
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
                        <Label htmlFor="ot-category">Category</Label>
                        <select
                            id="ot-category"
                            className={selectClass}
                            value={form.data.charge_category_id}
                            onChange={(e) => form.setData('charge_category_id', e.target.value)}
                            required
                        >
                            <option value="">Choose...</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
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
                        <select id="cat-kind" className={selectClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                            {Object.entries(kindLabels).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button disabled={form.processing || !form.data.name.trim()}>
                        <Plus /> Add category
                    </Button>
                </form>
            )}
        </div>
    );
}

export default function Charges({ recurring, oneTime, categories, canManage, options, today }: Props) {
    const [tab, setTab] = useState<'recurring' | 'one-time' | 'categories'>('recurring');
    const [addOpen, setAddOpen] = useState(false);
    const [agreementId, setAgreementId] = useState('');
    const [oneOpen, setOneOpen] = useState(false);
    const [selected, setSelected] = useState<Recurring | null>(null);
    const [mode, setMode] = useState<'edit' | 'end' | null>(null);
    const { flash } = usePage<SharedData>().props;
    const agreement = options?.agreements.find((a) => String(a.id) === agreementId);

    const tabs = [
        ['recurring', 'Recurring add-ons'],
        ['one-time', 'One-time charges and credits'],
        ['categories', 'Categories'],
    ] as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Charges & Utilities" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Charges &amp; Utilities</h1>
                    <p className="text-muted-foreground text-sm">Extra fees on top of rent, like a rice cooker, a repair, or a credit.</p>
                </div>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <div role="tablist" aria-label="Charges" className="flex flex-wrap gap-2">
                    {tabs.map(([key, label]) => (
                        <Button
                            key={key}
                            role="tab"
                            aria-selected={tab === key}
                            variant={tab === key ? 'default' : 'outline'}
                            onClick={() => setTab(key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                {tab === 'recurring' && (
                    <section aria-label="Recurring add-ons" className="space-y-3">
                        <p className="text-muted-foreground text-sm">Added to every monthly bill from the start date until you end it.</p>
                        {canManage && options && (
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="rc-agreement">Add for</Label>
                                    <select
                                        id="rc-agreement"
                                        className={selectClass}
                                        value={agreementId}
                                        onChange={(e) => setAgreementId(e.target.value)}
                                    >
                                        <option value="">Choose a tenant and room...</option>
                                        {options.agreements.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Button disabled={!agreement} onClick={() => setAddOpen(true)}>
                                    <Plus /> Add recurring charge
                                </Button>
                            </div>
                        )}
                        {recurring.length === 0 ? (
                            <Empty>No recurring charges yet</Empty>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {recurring.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                                        <div>
                                            <p className="font-medium">
                                                {c.label} - {c.amount} a month
                                            </p>
                                            <p className="text-muted-foreground">
                                                {c.tenant}, room {c.room}. From {formatDate(c.start_date)}
                                                {c.end_date && ` to ${formatDate(c.end_date)}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline">{stateLabels[c.state]}</Badge>
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
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSelected(c);
                                                            setMode('end');
                                                        }}
                                                    >
                                                        End
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
                    <section aria-label="One-time charges and credits" className="space-y-3">
                        {canManage && options && (
                            <Button onClick={() => setOneOpen(true)}>
                                <Plus /> Add one-time charge or credit
                            </Button>
                        )}
                        {oneTime.length === 0 ? (
                            <Empty>No one-time charges or credits yet</Empty>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {oneTime.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
                                        <div>
                                            <p className="font-medium">
                                                {c.label} - {c.amount}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {c.tenant} ({c.account_no}), {monthLabel(c.bill_month)} bill. {c.category}
                                                {c.reason && `. ${c.reason}`}
                                            </p>
                                        </div>
                                        <Badge variant="outline">{c.billed ? 'On a bill' : 'Waiting for the bill'}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {tab === 'categories' && <CategoriesSection categories={categories} canManage={canManage} />}
            </div>

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
