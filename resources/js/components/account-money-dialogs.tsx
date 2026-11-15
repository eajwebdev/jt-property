import InputError from '@/components/input-error';
import { selectClass } from '@/components/recurring-charge-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export interface DebitOption {
    id: number;
    label: string;
    remaining_cents: number;
    remaining: string;
}

interface Base {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Owner-only: hand credit back to the tenant. Needs a reason; the server limits it to the credit. */
export function RefundDialog({ open, onOpenChange, accountId, tenant, credit }: Base & { accountId: number; tenant: string; credit: string }) {
    const form = useForm({ amount: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounts.refunds.store', accountId), { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Refund {tenant}</DialogTitle>
                        <DialogDescription>
                            Gives money back to the tenant. You can refund at most the credit on the account ({credit}). This cannot be undone except by a
                            correcting entry.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="refund-amount">Amount (pesos)</Label>
                        <Input id="refund-amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="refund-reason">Reason</Label>
                        <Input id="refund-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={form.processing || !form.data.amount.trim() || !form.data.reason.trim()}>
                            Confirm refund
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Owner-only: forgive part or all of one unpaid charge. */
export function WriteOffDialog({ open, onOpenChange, tenant, debits }: Base & { tenant: string; debits: DebitOption[] }) {
    const [entryId, setEntryId] = useState<number | null>(null);
    const form = useForm({ amount: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const chosen = entryId ?? debits[0]?.id ?? null;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (chosen) form.post(route('ledger-entries.write-off', chosen), { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Write off a debt of {tenant}</DialogTitle>
                        <DialogDescription>The tenant no longer owes the amount you write off. It is recorded in the ledger with your reason.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-debit">Which charge</Label>
                        <select id="wo-debit" className={selectClass} value={chosen ?? ''} onChange={(e) => setEntryId(Number(e.target.value))}>
                            {debits.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.label} - still owes {d.remaining}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-amount">Amount to write off (pesos)</Label>
                        <Input id="wo-amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-reason">Reason</Label>
                        <Input id="wo-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={form.processing || !chosen || !form.data.amount.trim() || !form.data.reason.trim()}>
                            Confirm write-off
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Owner-only: split the unallocated part of a payment over unpaid charges. */
export function AllocateDialog({ open, onOpenChange, paymentId, unallocated, debits }: Base & { paymentId: number; unallocated: string; debits: DebitOption[] }) {
    const form = useForm<{ allocations: Record<string, string> }>({ allocations: {} });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ allocations: Object.fromEntries(Object.entries(d.allocations).filter(([, v]) => v.trim() !== '')) }));
        form.post(route('payments.allocate', paymentId), { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Allocate payment</DialogTitle>
                        <DialogDescription>{unallocated} of this payment is not yet applied. Enter how much goes to each unpaid charge.</DialogDescription>
                    </DialogHeader>
                    {debits.length === 0 && <p className="text-muted-foreground text-sm">Nothing is unpaid on this account.</p>}
                    {debits.map((d) => (
                        <div key={d.id} className="grid gap-1">
                            <Label htmlFor={`alloc-${d.id}`}>
                                {d.label} <span className="text-muted-foreground">(owes {d.remaining})</span>
                            </Label>
                            <Input
                                id={`alloc-${d.id}`}
                                inputMode="decimal"
                                placeholder="0.00"
                                value={form.data.allocations[d.id] ?? ''}
                                onChange={(e) => form.setData('allocations', { ...form.data.allocations, [d.id]: e.target.value })}
                            />
                        </div>
                    ))}
                    <InputError message={errors.allocations} />
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || debits.length === 0}>Confirm allocation</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
