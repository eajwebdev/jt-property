import { methodLabels } from '@/components/record-payment-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { BookOpen, Printer, ReceiptText } from 'lucide-react';

export interface TenantPayment {
    id: number;
    receipt_number: string | null;
    paid_on: string;
    amount_cents: number;
    amount: string;
    method: string;
    reference: string | null;
    status: 'approved' | 'pending' | 'reversed';
    account_id: number;
    account_no: string | null;
}

export interface AccountBalance {
    account_id: number;
    account_no: string;
    balance_cents: number;
}

const statusBadge: Record<TenantPayment['status'], { label: string; variant: 'success' | 'warning' | 'danger' }> = {
    approved: { label: 'Paid', variant: 'success' },
    pending: { label: 'Waiting for approval', variant: 'warning' },
    reversed: { label: 'Reversed', variant: 'danger' },
};

/** The boarder's payments with a receipt for each one, plus where each account stands and a link to its full ledger. */
export function TenantPayments({ payments, balances }: { payments: TenantPayment[]; balances: AccountBalance[] }) {
    const totalPaid = payments.filter((p) => p.status === 'approved').reduce((sum, p) => sum + p.amount_cents, 0);

    return (
        <section aria-labelledby="payments" className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="payments" className="text-lg font-semibold">
                        Payments &amp; receipts
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Every payment has its own receipt. {payments.length > 0 && `${formatCents(totalPaid)} paid in total.`}
                    </p>
                </div>
            </div>

            {balances.length > 0 && (
                <ul className="grid gap-2 sm:grid-cols-2">
                    {balances.map((b) => (
                        <li key={b.account_id} className="surface flex items-center justify-between gap-3 p-3">
                            <div>
                                <p className="text-muted-foreground text-xs">Account {b.account_no}</p>
                                <p
                                    className={cn(
                                        'font-mono text-lg font-semibold tabular-nums',
                                        b.balance_cents > 0 ? 'text-destructive' : 'text-success',
                                    )}
                                >
                                    {b.balance_cents > 0
                                        ? `${formatCents(b.balance_cents)} owed`
                                        : b.balance_cents < 0
                                          ? `${formatCents(-b.balance_cents)} credit`
                                          : 'Fully paid'}
                                </p>
                            </div>
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('accounts.statement', b.account_id)}>
                                    <BookOpen /> Full ledger
                                </Link>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {payments.length === 0 ? (
                <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No payments recorded yet.</p>
            ) : (
                <div className="surface overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <caption className="sr-only">Payments and receipts</caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2">Date paid</th>
                                <th className="px-3 py-2">Receipt No.</th>
                                <th className="px-3 py-2">Method</th>
                                <th className="px-3 py-2 text-right">Amount</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Receipt</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {payments.map((p) => (
                                <tr key={p.id} className={cn(p.status === 'reversed' && 'text-muted-foreground')}>
                                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(p.paid_on)}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <Link href={route('payments.show', p.id)} className="font-medium hover:underline">
                                            {p.receipt_number ?? 'Not issued yet'}
                                        </Link>
                                        {p.account_no && <span className="text-muted-foreground block text-xs">{p.account_no}</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        {methodLabels[p.method] ?? p.method}
                                        {p.reference && <span className="text-muted-foreground block text-xs">Ref. {p.reference}</span>}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-3 py-2 text-right font-mono font-semibold tabular-nums',
                                            p.status === 'reversed' && 'line-through',
                                        )}
                                    >
                                        {p.amount}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge variant={statusBadge[p.status].variant}>{statusBadge[p.status].label}</Badge>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {p.receipt_number ? (
                                            <Button asChild size="sm" variant="outline">
                                                <a
                                                    href={route('payments.acknowledgment', p.id)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    aria-label={`Print receipt ${p.receipt_number}`}
                                                >
                                                    <Printer /> Receipt
                                                </a>
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                <ReceiptText className="size-3.5" aria-hidden /> After approval
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
