import { AllocateDialog, type DebitOption } from '@/components/account-money-dialogs';
import InputError from '@/components/input-error';
import { methodLabels } from '@/components/record-payment-dialog';
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
import { FormEventHandler, useState } from 'react';

interface Props {
    payment: {
        id: number;
        account_no: string;
        tenant: string;
        property: string;
        amount: string;
        paid_on: string;
        method: string;
        reference: string | null;
        payer_name: string | null;
        notes: string | null;
        status: 'pending' | 'approved' | 'reversed';
        ack_number: string | null;
        collected_by: string | null;
        approved_by: string | null;
        has_proof: boolean;
        reverse_reason: string | null;
        unallocated: string;
        unallocated_cents: number;
    };
    openDebits: DebitOption[];
    allocations: { id: number; label: string; date: string; amount: string; reversed: boolean }[];
    canManage: boolean;
}

const statusLabels = { pending: 'Waiting for approval', approved: 'Approved', reversed: 'Reversed' };

function ReverseDialog({ id, open, onOpenChange }: { id: number; open: boolean; onOpenChange: (o: boolean) => void }) {
    const form = useForm({ reason: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('payments.reverse', id), { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Reverse this payment</DialogTitle>
                        <DialogDescription>A reversal is added to the ledger and the charges it covered become unpaid again. The original stays on record.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="rev-reason">Reason</Label>
                        <Input id="rev-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                        <InputError message={(form.errors as Record<string, string | undefined>).reason} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Keep it
                        </Button>
                        <Button variant="destructive" disabled={form.processing || !form.data.reason.trim()}>
                            Reverse payment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PaymentShow({ payment, allocations, openDebits, canManage }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [reversing, setReversing] = useState(false);
    const [allocating, setAllocating] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Collections', href: '/collections' },
        { title: payment.ack_number ?? 'Payment', href: `/payments/${payment.id}` },
    ];
    const details: [string, string | null][] = [
        ['Tenant', `${payment.tenant} (${payment.account_no})`],
        ['Property', payment.property],
        ['Date paid', formatDate(payment.paid_on)],
        ['Method', methodLabels[payment.method] ?? payment.method],
        ['Reference', payment.reference],
        ['Paid by', payment.payer_name],
        ['Recorded by', payment.collected_by],
        ['Approved by', payment.approved_by],
        ['Notes', payment.notes],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={payment.ack_number ?? 'Payment'} />
            <div className="mx-auto max-w-3xl space-y-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">{payment.amount}</h1>
                        <p className="text-muted-foreground text-sm">{payment.ack_number ? `Acknowledgment ${payment.ack_number} (not a BIR official receipt)` : 'No acknowledgment number until approved'}</p>
                    </div>
                    <Badge variant={payment.status === 'approved' ? 'default' : payment.status === 'pending' ? 'secondary' : 'destructive'}>{statusLabels[payment.status]}</Badge>
                </div>

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {canManage && payment.status !== 'reversed' && (
                    <div className="flex flex-wrap gap-2">
                        {payment.status === 'pending' && (
                            <Button className="h-11" onClick={() => router.post(route('payments.approve', payment.id), {}, { preserveScroll: true })}>
                                Approve and post
                            </Button>
                        )}
                        {payment.status === 'approved' && payment.unallocated_cents > 0 && (
                            <Button className="h-11" onClick={() => setAllocating(true)}>
                                Allocate
                            </Button>
                        )}
                        {payment.status === 'approved' && (
                            <Button className="h-11" variant="outline" onClick={() => setReversing(true)}>
                                Reverse
                            </Button>
                        )}
                    </div>
                )}
                {payment.status === 'reversed' && payment.reverse_reason && (
                    <Alert variant="destructive">
                        <AlertDescription>Reversed: {payment.reverse_reason}</AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border p-4 text-sm sm:grid-cols-2">
                    {details.map(([k, v]) =>
                        v ? (
                            <div key={k}>
                                <dt className="text-muted-foreground">{k}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ) : null,
                    )}
                    {payment.has_proof && (
                        <div>
                            <dt className="text-muted-foreground">Proof</dt>
                            <dd>
                                <a className="font-medium underline" href={route('payments.proof', payment.id)} target="_blank" rel="noreferrer">
                                    Open proof
                                </a>
                            </dd>
                        </div>
                    )}
                </dl>

                <section aria-label="Allocations" className="space-y-2">
                    <h2 className="font-medium">What this payment covered</h2>
                    {allocations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{payment.status === 'pending' ? 'Allocated when approved.' : 'Nothing allocated; the whole amount is credit.'}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {allocations.map((a) => (
                                <li key={a.id} className={'flex justify-between gap-3 p-3 ' + (a.reversed ? 'text-muted-foreground line-through' : '')}>
                                    <span>
                                        {a.label} <span className="text-muted-foreground">{formatDate(a.date)}</span>
                                    </span>
                                    <span className="font-medium whitespace-nowrap">{a.amount}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {payment.status === 'approved' && <p className="text-muted-foreground text-sm">Kept as credit: {payment.unallocated}</p>}
                </section>
            </div>
            <ReverseDialog id={payment.id} open={reversing} onOpenChange={setReversing} />
            <AllocateDialog open={allocating} onOpenChange={setAllocating} paymentId={payment.id} unallocated={payment.unallocated} debits={openDebits} />
        </AppLayout>
    );
}
