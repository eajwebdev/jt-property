import { BillingTabs } from '@/components/billing-tabs';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';

interface Item {
    id: number;
    status: 'generated' | 'already_billed' | 'skipped' | 'needs_review' | 'failed';
    message: string | null;
    tenant: string;
    unit: string;
    invoice_id: number | null;
    invoice_number: string | null;
    amount_cents: number | null;
    email_status: 'not_sent' | 'queued' | 'sent' | 'failed' | 'skipped';
    email_reason: string | null;
}
interface Props {
    batch: { id: number; year: number; month: number; created_at: string | null; counts: Record<string, number> };
    items: Item[];
    canRetry: boolean;
    canSend: boolean;
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

export default function BillingBatch({ batch, items, canRetry, canSend }: Props) {
    const { flash } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Results', href: `/billing/batches/${batch.id}` },
    ];
    const c = batch.counts;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing results" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={FileText}
                    title={`${months[batch.month - 1]} ${batch.year} bills`}
                    description={`${c.generated ?? 0} created, ${c.already_billed ?? 0} already billed, ${(c.needs_review ?? 0) + (c.skipped ?? 0)} left out, ${c.failed ?? 0} failed.`}
                />
                <BillingTabs active="preview" />

                {items.some((item) => item.invoice_id !== null) && (
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <a href={route('billing.batches.combined', batch.id)} target="_blank" rel="noopener noreferrer">
                                View all bills as PDF / print
                            </a>
                        </Button>
                        {canSend && (
                            <Button
                                variant="outline"
                                onClick={() => router.post(route('billing.batches.email', batch.id), {}, { preserveScroll: true })}
                            >
                                Email all eligible tenants
                            </Button>
                        )}
                    </div>
                )}

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
                            <li key={i.id} className="surface flex flex-wrap items-center justify-between gap-3 p-4">
                                <div>
                                    <p className="font-medium">{i.tenant}</p>
                                    <p className="text-muted-foreground text-xs">Room {i.unit}</p>
                                    {i.message && <p className="mt-1 text-sm">{i.message}</p>}
                                    {i.invoice_id && (
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            Email:{' '}
                                            {i.email_status === 'not_sent' ? 'Not sent' : i.email_status === 'skipped' ? 'Not sent' : i.email_status}
                                            {i.email_reason ? ` — ${i.email_reason}` : ''}
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-3">
                                    {i.amount_cents !== null && <span className="font-semibold">{formatCents(i.amount_cents)}</span>}
                                    <Badge variant={variants[i.status]}>{labels[i.status]}</Badge>
                                    {i.invoice_id && (
                                        <Link href={route('invoices.show', i.invoice_id)} className="text-primary text-sm underline">
                                            Open {i.invoice_number}
                                        </Link>
                                    )}
                                    {canSend && i.invoice_id && i.email_status !== 'sent' && i.email_status !== 'queued' && (
                                        <Button
                                            variant="outline"
                                            className="h-10"
                                            onClick={() => router.post(route('invoices.email', i.invoice_id!), {}, { preserveScroll: true })}
                                        >
                                            Send email
                                        </Button>
                                    )}
                                    {canRetry && i.status === 'failed' && (
                                        <Button
                                            variant="outline"
                                            className="h-10"
                                            onClick={() => router.post(route('billing.items.retry', i.id), {}, { preserveScroll: true })}
                                        >
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
