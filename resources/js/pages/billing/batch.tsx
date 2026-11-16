import { BillingTabs } from '@/components/billing-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Item {
    id: number;
    status: 'generated' | 'already_billed' | 'skipped' | 'needs_review' | 'failed';
    message: string | null;
    tenant: string;
    unit: string;
    invoice_id: number | null;
    invoice_number: string | null;
    amount_cents: number | null;
}
interface Props {
    batch: { id: number; year: number; month: number; created_at: string | null; counts: Record<string, number> };
    items: Item[];
    canRetry: boolean;
}

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const labels: Record<Item['status'], string> = {
    generated: 'Bill created',
    already_billed: 'Already billed',
    skipped: 'Skipped',
    needs_review: 'Needs attention',
    failed: 'Failed',
};
const variants: Record<Item['status'], 'default' | 'secondary' | 'outline' | 'destructive'> = {
    generated: 'default',
    already_billed: 'secondary',
    skipped: 'outline',
    needs_review: 'destructive',
    failed: 'destructive',
};

export default function BillingBatch({ batch, items, canRetry }: Props) {
    const { flash } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Results', href: `/billing/batches/${batch.id}` },
    ];
    const c = batch.counts;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing results" />
            <div className="space-y-5 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {months[batch.month - 1]} {batch.year} bills
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {c.generated ?? 0} created, {c.already_billed ?? 0} already billed, {(c.needs_review ?? 0) + (c.skipped ?? 0)} left out, {c.failed ?? 0} failed. Nothing was
                        emailed.
                    </p>
                </div>
                <BillingTabs active="preview" />

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {items.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">Nobody had a bill in this month.</p>
                ) : (
                    <ul className="space-y-3">
                        {items.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                                <div>
                                    <p className="font-medium">{i.tenant}</p>
                                    <p className="text-muted-foreground text-xs">Room {i.unit}</p>
                                    {i.message && <p className="mt-1 text-sm">{i.message}</p>}
                                </div>
                                <div className="flex flex-wrap items-center gap-3">
                                    {i.amount_cents !== null && <span className="font-semibold">{formatCents(i.amount_cents)}</span>}
                                    <Badge variant={variants[i.status]}>{labels[i.status]}</Badge>
                                    {i.invoice_id && (
                                        <Link href={route('invoices.show', i.invoice_id)} className="text-primary text-sm underline">
                                            Open {i.invoice_number}
                                        </Link>
                                    )}
                                    {canRetry && i.status === 'failed' && (
                                        <Button variant="outline" className="h-10" onClick={() => router.post(route('billing.items.retry', i.id), {}, { preserveScroll: true })}>
                                            Try again
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
