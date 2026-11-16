import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { formatDate } from '@/lib/tenant-labels';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface PayAccount {
    account_id: number;
    account_no: string;
    tenant: string;
}

interface PreviewLine {
    ledger_entry_id: number;
    label: string;
    due_date: string;
    remaining_cents: number;
    apply_cents: number;
}
interface Preview {
    lines: PreviewLine[];
    credit_cents: number;
}

export const methodLabels: Record<string, string> = {
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    gcash: 'GCash',
    maya: 'Maya',
    check: 'Check',
    other: 'Other',
};

export const peso = (cents: number) => '₱' + (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    accounts: PayAccount[];
    initialAccountId: number | null;
    methods: string[];
    today: string;
    isOwner: boolean;
}

/** Records a payment. The allocation preview is computed by the server so it always matches what gets saved. */
export function RecordPaymentDialog({ open, onOpenChange, accounts, initialAccountId, methods, today, isOwner }: Props) {
    const form = useForm<{
        amount: string;
        paid_on: string;
        method: string;
        reference: string;
        payer_name: string;
        notes: string;
        proof: File | null;
    }>({
        amount: '',
        paid_on: today,
        method: 'cash',
        reference: '',
        payer_name: '',
        notes: '',
        proof: null,
    });
    const [accountId, setAccountId] = useState<number | null>(initialAccountId);
    const [preview, setPreview] = useState<Preview | null>(null);
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) setAccountId(initialAccountId);
    }, [open, initialAccountId]);

    useEffect(() => {
        setPreview(null);
        if (!accountId || !form.data.amount.trim()) return;
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            fetch(route('accounts.payments.preview', accountId) + '?amount=' + encodeURIComponent(form.data.amount), {
                headers: { Accept: 'application/json' },
                signal: ctrl.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((p) => setPreview(p))
                .catch(() => undefined);
        }, 300);
        return () => (clearTimeout(t), ctrl.abort());
    }, [accountId, form.data.amount]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!accountId) return;
        form.post(route('accounts.payments.store', accountId), { forceFormData: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Record payment</DialogTitle>
                        <DialogDescription>
                            {isOwner ? 'The payment posts right away.' : 'Your payment waits for the owner to approve it before it counts.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="pay-account">Account</Label>
                        <SearchSelect
                            id="pay-account"
                            value={String(accountId ?? '')}
                            onChange={(v) => setAccountId(Number(v) || null)}
                            placeholder="Search tenant or account no…"
                            options={accounts.map((a) => ({ value: String(a.account_id), label: a.tenant, hint: a.account_no }))}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="pay-amount">Amount (pesos)</Label>
                            <Input
                                id="pay-amount"
                                inputMode="decimal"
                                placeholder="2,500.00"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                required
                            />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pay-date">Date paid</Label>
                            <Input
                                id="pay-date"
                                type="date"
                                max={today}
                                value={form.data.paid_on}
                                onChange={(e) => form.setData('paid_on', e.target.value)}
                                required
                            />
                            <InputError message={errors.paid_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pay-method">Method</Label>
                            <SearchSelect
                                id="pay-method"
                                value={form.data.method}
                                onChange={(v) => form.setData('method', v)}
                                options={methods.map((m) => ({ value: m, label: methodLabels[m] ?? m }))}
                            />
                            <InputError message={errors.method} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pay-ref">Reference no.</Label>
                            <Input id="pay-ref" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                            <InputError message={errors.reference} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="pay-payer">Paid by (if not the tenant)</Label>
                        <Input id="pay-payer" value={form.data.payer_name} onChange={(e) => form.setData('payer_name', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pay-proof">Proof (photo or PDF, up to 5 MB)</Label>
                        <Input
                            id="pay-proof"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={errors.proof} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pay-notes">Notes</Label>
                        <Input id="pay-notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>

                    {preview && (
                        <section aria-label="Allocation preview" className="rounded-lg border p-3 text-sm">
                            <h3 className="mb-2 font-medium">This payment will cover</h3>
                            {preview.lines.length === 0 && <p className="text-muted-foreground">Nothing is owed on this account.</p>}
                            <ul className="space-y-1">
                                {preview.lines.map((l) => (
                                    <li key={l.ledger_entry_id} className="flex justify-between gap-3">
                                        <span>
                                            {l.label} <span className="text-muted-foreground">due {formatDate(l.due_date)}</span>
                                        </span>
                                        <span className="font-medium whitespace-nowrap">{peso(l.apply_cents)}</span>
                                    </li>
                                ))}
                            </ul>
                            {preview.credit_cents > 0 && (
                                <p className="mt-2 flex justify-between border-t pt-2 font-medium">
                                    <span>Extra kept as credit</span>
                                    <span>{peso(preview.credit_cents)}</span>
                                </p>
                            )}
                        </section>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || !accountId || !form.data.amount.trim()}>
                            {form.processing ? 'Saving...' : 'Save payment'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
