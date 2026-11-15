import { BillingTabs, paymentStatusLabels, paymentStatusVariant } from '@/components/billing-tabs';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Invoice {
    id: number;
    number: string;
    status: string;
    void_reason: string | null;
    voided_at: string | null;
    voided_by: string | null;
    period_start: string;
    period_end: string;
    issue_date: string;
    due_date: string;
    tenant: string;
    account_no: string;
    property: string;
    unit: string;
    lines: { label: string; description: string | null; qty: string | null; rate: string | null; amount_cents: number }[];
    current_total_cents: number;
    previous_balance_cents: number;
    payments_since_cents: number;
    statement_total_cents: number;
}
interface Props {
    invoice: Invoice;
    canVoid: boolean;
    statementUrl: string | null;
    statementPdfUrl: string | null;
}

function VoidDialog({ invoice, open, onOpenChange }: { invoice: Invoice; open: boolean; onOpenChange: (o: boolean) => void }) {
    const form = useForm({ reason: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('invoices.void', invoice.id), { onSuccess: () => onOpenChange(false) });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Void bill {invoice.number}?</DialogTitle>
                        <DialogDescription>The tenant will no longer owe this bill. The bill stays on record, marked as voided. You can then create a corrected one.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <Label htmlFor="void-reason">Why is it being voided?</Label>
                        <Input id="void-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="At least 5 letters" />
                        <InputError message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Keep the bill
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            Void this bill
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function InvoiceShow({ invoice, canVoid, statementUrl, statementPdfUrl }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [voiding, setVoiding] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'All Bills', href: '/invoices' },
        { title: invoice.number, href: `/invoices/${invoice.id}` },
    ];
    const isVoid = invoice.status === 'void';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill ${invoice.number}`} />
            <div className="space-y-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Bill {invoice.number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {invoice.tenant} - Room {invoice.unit} - {invoice.property}
                        </p>
                    </div>
                    <Badge variant={paymentStatusVariant[invoice.status]} className="text-sm">
                        {paymentStatusLabels[invoice.status]}
                    </Badge>
                </div>
                <BillingTabs active="invoices" />

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}
                {isVoid && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            Voided {invoice.voided_at ? formatDate(invoice.voided_at) : ''}
                            {invoice.voided_by ? ` by ${invoice.voided_by}` : ''}. Reason: {invoice.void_reason}
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    {[
                        ['Billing period', `${formatDate(invoice.period_start)} to ${formatDate(invoice.period_end)}`],
                        ['Issued', formatDate(invoice.issue_date)],
                        ['Due', formatDate(invoice.due_date)],
                        ['Account', invoice.account_no],
                    ].map(([k, v]) => (
                        <div key={k} className="rounded-lg border p-3">
                            <dt className="text-muted-foreground text-xs">{k}</dt>
                            <dd className="font-medium">{v}</dd>
                        </div>
                    ))}
                </dl>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">Charges on this bill</caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th scope="col" className="p-3">
                                    What it is for
                                </th>
                                <th scope="col" className="p-3">
                                    Usage
                                </th>
                                <th scope="col" className="p-3 text-right">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.lines.map((l, i) => (
                                <tr key={i} className="border-t">
                                    <td className="p-3">
                                        {l.label}
                                        {l.description && <span className="text-muted-foreground block text-xs">{l.description}</span>}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{l.qty && l.rate ? `${l.qty} x ${l.rate}` : (l.qty ?? '')}</td>
                                    <td className={'p-3 text-right whitespace-nowrap ' + (l.amount_cents < 0 ? 'text-green-700 dark:text-green-400' : '')}>{formatCents(l.amount_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <dl className="ml-auto max-w-md space-y-2 rounded-lg border p-4 text-sm">
                    <div className="flex justify-between gap-3">
                        <dt>Current Invoice Total</dt>
                        <dd className="font-semibold">{formatCents(invoice.current_total_cents)}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                        <dt>Previous Balance</dt>
                        <dd>{formatCents(invoice.previous_balance_cents)}</dd>
                    </div>
                    {invoice.payments_since_cents !== 0 && (
                        <div className="flex justify-between gap-3">
                            <dt>Payments and credits since</dt>
                            <dd>{formatCents(invoice.payments_since_cents)}</dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-3 border-t pt-2 text-base">
                        <dt className="font-semibold">Statement Total Due</dt>
                        <dd className="font-semibold">{formatCents(invoice.statement_total_cents)}</dd>
                    </div>
                </dl>

                <div className="flex flex-wrap gap-2">
                    {statementUrl && (
                        <Button asChild variant="outline" className="h-11">
                            <a href={statementUrl}>Statement</a>
                        </Button>
                    )}
                    {statementPdfUrl && (
                        <Button asChild variant="outline" className="h-11">
                            <a href={statementPdfUrl}>Statement (PDF)</a>
                        </Button>
                    )}
                    {canVoid && (
                        <Button variant="destructive" className="h-11" onClick={() => setVoiding(true)}>
                            Void this bill
                        </Button>
                    )}
                </div>
            </div>
            {canVoid && <VoidDialog invoice={invoice} open={voiding} onOpenChange={setVoiding} />}
        </AppLayout>
    );
}
