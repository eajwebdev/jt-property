import InputError from '@/components/input-error';
import { OpeningBalanceWizard, type WizardAccount } from '@/components/opening-balance-wizard';
import { selectClass } from '@/components/recurring-charge-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Opening Balances', href: '/opening-balances' }];
const statusLabels: Record<Status, string> = { none: 'Not set', pending: 'Waiting for approval', approved: 'Posted', reversed: 'Reversed' };
const statusVariant: Record<Status, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    none: 'outline',
    pending: 'secondary',
    approved: 'default',
    reversed: 'destructive',
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
                <p className="rounded-lg border p-4 text-sm">
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

    const filter = (value: string) =>
        router.get(route('opening-balances.index'), value ? { property_id: value } : {}, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opening Balances" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Opening Balances</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            Unpaid debt from before the JT system. Each account gets one, posted once as Balance Brought Forward. It is never billed
                            as rent and never mixed with deposits or advances.
                        </p>
                    </div>
                    {canManage && (
                        <Button variant="outline" onClick={() => setImporting(true)}>
                            <Upload /> Import CSV
                        </Button>
                    )}
                </div>

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
                            <div className="overflow-x-auto rounded-lg border">
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

                <div className="flex items-center gap-2">
                    <Label htmlFor="ob-property">Property</Label>
                    <select
                        id="ob-property"
                        className={selectClass + ' max-w-xs'}
                        value={filters.property_id ?? ''}
                        onChange={(e) => filter(e.target.value)}
                    >
                        <option value="">All properties</option>
                        {properties.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </div>

                {accounts.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No billing accounts yet.</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
                                    <th className="p-3">Tenant</th>
                                    <th className="p-3">Property</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3 text-right">Amount</th>
                                    <th className="p-3">As of</th>
                                    {canManage && <th className="p-3" />}
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((r) => (
                                    <tr key={r.account_id} className="border-t">
                                        <td className="p-3 font-medium whitespace-nowrap">{r.account_no}</td>
                                        <td className="p-3">{r.tenant}</td>
                                        <td className="p-3">{r.property}</td>
                                        <td className="p-3">
                                            <Badge variant={statusVariant[r.status]}>{statusLabels[r.status]}</Badge>
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">{r.amount ?? '-'}</td>
                                        <td className="p-3 whitespace-nowrap">{r.as_of ? formatDate(r.as_of) : '-'}</td>
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
            </div>

            <OpeningBalanceWizard account={wizard} today={today} onClose={() => setWizard(null)} />
            <PendingDialog row={pending} onClose={() => setPending(null)} />
            <ReverseDialog row={reversing} onClose={() => setReversing(null)} />
            <ImportDialog open={importing} onOpenChange={setImporting} />
        </AppLayout>
    );
}
