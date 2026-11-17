import InputError from '@/components/input-error';
import { OpeningBalanceWizard, type WizardAccount } from '@/components/opening-balance-wizard';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { History, Search, Upload } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Status = 'none' | 'pending' | 'approved' | 'reversed';
interface Row extends WizardAccount {
    property: string;
    property_id: number;
    account_status: string;
    status: Status;
    opening_balance_id: number | null;
    amount_cents: number | null;
    amount: string | null;
    as_of: string | null;
    description: string | null;
    breakdown: { month: string; amount_cents: number; note: string | null }[] | null;
    reverse_reason: string | null;
}
interface Props {
    accounts: Row[];
    properties: { id: number; name: string }[];
    filters: { property_id: number | null };
    canManage: boolean;
    today: string;
    importResult: { created: number; errors: string[] } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Opening balances', href: '/opening-balances' }];
const statusLabels: Record<Status, string> = { none: 'Not set', pending: 'Waiting for approval', approved: 'Posted', reversed: 'Reversed' };
const statusVariant: Record<Status, 'outline' | 'warning' | 'success' | 'muted'> = {
    none: 'outline',
    pending: 'warning',
    approved: 'success',
    reversed: 'muted',
};

function ImportDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const form = useForm<{ file: File | null }>({ file: null });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('opening-balances.import'), { forceFormData: true, onSuccess: () => onOpenChange(false) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Import opening balances</DialogTitle>
                        <DialogDescription>
                            A .csv or .txt file under 1 MB, saved as UTF-8, with the header <code>account_no,amount,as_of,description</code>. Example
                            row: <code>JT-000002,"1,800.00",2026-10-31,Old unpaid rent</code>. Rows are saved as drafts that you approve one by one.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="ob-file">CSV file</Label>
                        <Input
                            id="ob-file"
                            type="file"
                            accept=".csv,.txt"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            required
                        />
                        <InputError message={errors.file} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || !form.data.file}>{form.processing ? 'Uploading...' : 'Import'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Review a pending draft and approve it, or cancel it. */
function PendingDialog({ row, onClose }: { row: Row | null; onClose: () => void }) {
    const [busy, setBusy] = useState(false);
    if (!row) return null;
    const approve = () =>
        router.post(
            route('opening-balances.approve', row.opening_balance_id!),
            {},
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose },
        );
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Approve opening balance for {row.tenant} ({row.account_no})
                    </DialogTitle>
                    <DialogDescription>Nothing is posted until you approve.</DialogDescription>
                </DialogHeader>
                <p className="surface p-4 text-sm">
                    This will post <strong>{row.amount}</strong> as Balance Brought Forward on <strong>{row.account_no}</strong>. It will not be
                    billed as rent.
                </p>
                <p className="text-muted-foreground text-sm">
                    Owed as of {row.as_of ? formatDate(row.as_of) : ''}. {row.description}
                </p>
                {row.first_charge_date && row.as_of && row.first_charge_date <= row.as_of && (
                    <Alert>
                        <AlertDescription>
                            This account already has bills before this date - make sure this debt isn't already on them.
                        </AlertDescription>
                    </Alert>
                )}
                <DialogFooter className="gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Close
                    </Button>
                    <Button type="button" disabled={busy} onClick={approve}>
                        {busy ? 'Posting...' : 'Approve and post'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Reverses a posted balance, or cancels a draft, with a required reason. */
function ReverseDialog({ row, onClose }: { row: Row | null; onClose: () => void }) {
    const form = useForm({ reason: '' });
    if (!row) return null;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('opening-balances.reverse', row.opening_balance_id!), { preserveScroll: true, onSuccess: () => (form.reset(), onClose()) });
    };
    const posted = row.status === 'approved';
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            {posted ? 'Reverse' : 'Cancel'} opening balance {row.amount}
                        </DialogTitle>
                        <DialogDescription>
                            {posted
                                ? 'A reversal entry is added to the ledger; the original stays on record. You can then enter a new opening balance.'
                                : 'The draft is discarded and nothing is posted.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="ob-reason">Reason</Label>
                        <Input id="ob-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                        <InputError message={(form.errors as Record<string, string | undefined>).reason} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Keep it
                        </Button>
                        <Button variant="destructive" disabled={form.processing || !form.data.reason.trim()}>
                            {posted ? 'Reverse' : 'Cancel draft'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OpeningBalancesIndex({ accounts, properties, filters, canManage, today, importResult }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [wizard, setWizard] = useState<Row | null>(null);
    const [pending, setPending] = useState<Row | null>(null);
    const [reversing, setReversing] = useState<Row | null>(null);
    const [importing, setImporting] = useState(false);
    const [status, setStatus] = useState<'all' | Status>('all');
    const [search, setSearch] = useState('');

    const filter = (value: string) =>
        router.get(route('opening-balances.index'), value ? { property_id: value } : {}, { preserveState: true, replace: true });
    const count = (s: Status) => accounts.filter((r) => r.status === s).length;
    const postedCents = accounts.filter((r) => r.status === 'approved').reduce((sum, r) => sum + (r.amount_cents ?? 0), 0);
    const q = search.trim().toLowerCase();
    const visible = accounts.filter(
        (r) => (status === 'all' || r.status === status) && (q === '' || `${r.tenant} ${r.account_no} ${r.property}`.toLowerCase().includes(q)),
    );
    const chips: ['all' | Status, string, number][] = [
        ['all', 'All', accounts.length],
        ['none', 'Not set', count('none')],
        ['pending', 'Waiting for approval', count('pending')],
        ['approved', 'Posted', count('approved')],
        ['reversed', 'Reversed', count('reversed')],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opening balances" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={History}
                    title="Opening balances"
                    description="What a boarder still owed before you started using this system. Enter it once per boarder; it shows on their next bill as “Balance brought forward”."
                    actions={
                        canManage && (
                            <Button variant="outline" onClick={() => setImporting(true)}>
                                <Upload /> Import from a spreadsheet (CSV)
                            </Button>
                        )
                    }
                />

                <ol className="grid gap-3 sm:grid-cols-3" aria-label="How it works">
                    {[
                        ['Enter', 'Click "Set up" on a boarder and type what they owed and as of what date.'],
                        ['Approve', 'Check the amount and click "Review & approve". Nothing is posted before that.'],
                        ['Done', 'It appears on their next bill as Balance brought forward. Made a mistake? Reverse it and enter a new one.'],
                    ].map(([title, text], i) => (
                        <li key={title} className="surface flex gap-3 p-3 text-sm">
                            <span className="bg-primary text-primary-foreground grid size-7 shrink-0 place-items-center rounded-full font-semibold">
                                {i + 1}
                            </span>
                            <span>
                                <span className="block font-medium">{title}</span>
                                <span className="text-muted-foreground block text-xs leading-snug">{text}</span>
                            </span>
                        </li>
                    ))}
                </ol>

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {importResult && (
                    <section className="space-y-2" aria-label="Import results">
                        <h2 className="font-medium">
                            Import results: {importResult.created} saved, {importResult.errors.length} not saved
                        </h2>
                        {importResult.errors.length > 0 && (
                            <div className="surface overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-2">Row</th>
                                            <th className="p-2">Problem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {importResult.errors.map((e, i) => {
                                            const m = e.match(/^Row (\d+)(?: \(([^)]*)\))?: (.*)$/);
                                            return (
                                                <tr key={i} className="border-t">
                                                    <td className="p-2 whitespace-nowrap">{m ? `${m[1]}${m[2] ? ` (${m[2]})` : ''}` : '-'}</td>
                                                    <td className="p-2">{m ? m[3] : e}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                <dl className="surface grid grid-cols-2 divide-x sm:grid-cols-4">
                    {[
                        ['Not set yet', String(count('none')), undefined],
                        ['Waiting for your approval', String(count('pending')), count('pending') > 0 ? 'text-warning' : undefined],
                        ['Posted', String(count('approved')), 'text-success'],
                        ['Total posted', formatCents(postedCents), undefined],
                    ].map(([text, value, tone]) => (
                        <div key={text} className="min-w-0 px-4 py-2.5">
                            <dt className="text-muted-foreground truncate text-xs">{text}</dt>
                            <dd className={cn('font-mono text-lg font-semibold tabular-nums', tone)}>{value}</dd>
                        </div>
                    ))}
                </dl>

                <section className="surface overflow-hidden">
                    <div className="flex flex-wrap items-center gap-2 border-b p-3">
                        <div className="bg-muted/60 inline-flex flex-wrap gap-1 rounded-lg p-1" role="tablist" aria-label="Filter by status">
                            {chips.map(([key, text, n]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={status === key}
                                    onClick={() => setStatus(key)}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                        status === key ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {text}
                                    <span className="font-mono tabular-nums opacity-70">{n}</span>
                                </button>
                            ))}
                        </div>
                        <label htmlFor="ob-property" className="sr-only">
                            Property
                        </label>
                        <SearchSelect
                            id="ob-property"
                            className="w-52"
                            value={String(filters.property_id ?? '')}
                            onChange={(v) => filter(v)}
                            options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                        <div className="relative ml-auto w-full sm:w-64">
                            <Search
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search boarder or account"
                                aria-label="Search boarder or account"
                                className="h-9 pl-8"
                            />
                        </div>
                    </div>

                    {accounts.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">No boarders yet.</p>
                    ) : visible.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">No boarders match.</p>
                    ) : (
                        <div className="max-h-[calc(100dvh-22rem)] min-h-64 overflow-auto">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead className="bg-muted/80 sticky top-0 z-10 text-left backdrop-blur">
                                    <tr className="border-b">
                                        <th className="p-3">Boarder</th>
                                        <th className="p-3">Property</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3 text-right">Amount owed</th>
                                        <th className="p-3">As of</th>
                                        {canManage && (
                                            <th className="p-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((r) => (
                                        <tr key={r.account_id} className="border-b last:border-0">
                                            <td className="p-3">
                                                <span className="block font-medium">{r.tenant}</span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {r.account_no}
                                                    {r.account_status === 'closed' && ' · closed account'}
                                                </span>
                                            </td>
                                            <td className="p-3">{r.property}</td>
                                            <td className="p-3">
                                                <Badge variant={statusVariant[r.status]}>{statusLabels[r.status]}</Badge>
                                            </td>
                                            <td className="p-3 text-right font-mono whitespace-nowrap tabular-nums">{r.amount ?? '—'}</td>
                                            <td className="p-3 whitespace-nowrap">{r.as_of ? formatDate(r.as_of) : '—'}</td>
                                            {canManage && (
                                                <td className="p-3 text-right whitespace-nowrap">
                                                    {(r.status === 'none' || r.status === 'reversed') && (
                                                        <Button size="sm" variant="outline" onClick={() => setWizard(r)}>
                                                            {r.status === 'none' ? 'Set up' : 'Enter new'}
                                                        </Button>
                                                    )}
                                                    {r.status === 'pending' && (
                                                        <span className="flex justify-end gap-2">
                                                            <Button size="sm" onClick={() => setPending(r)}>
                                                                Review & approve
                                                            </Button>
                                                            <Button size="sm" variant="ghost" onClick={() => setReversing(r)}>
                                                                Cancel
                                                            </Button>
                                                        </span>
                                                    )}
                                                    {r.status === 'approved' && (
                                                        <Button size="sm" variant="ghost" onClick={() => setReversing(r)}>
                                                            Reverse
                                                        </Button>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <OpeningBalanceWizard account={wizard} today={today} onClose={() => setWizard(null)} />
            <PendingDialog row={pending} onClose={() => setPending(null)} />
            <ReverseDialog row={reversing} onClose={() => setReversing(null)} />
            <ImportDialog open={importing} onOpenChange={setImporting} />
        </AppLayout>
    );
}
