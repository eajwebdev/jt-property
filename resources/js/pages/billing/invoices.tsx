import { BillingTabs, paymentStatusLabels, paymentStatusVariant } from '@/components/billing-tabs';
import { PageHeader } from '@/components/page-header';
import { selectClass } from '@/components/recurring-charge-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Files } from 'lucide-react';

interface Row {
    id: number;
    number: string;
    tenant: string;
    unit: string;
    property: string;
    period_start: string;
    due_date: string;
    current_total_cents: number;
    status: string;
}
interface Props {
    rows: Row[];
    properties: { id: number; name: string }[];
    filters: { month: string | null; property_id: number | null; status: string | null };
    statuses: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'All Bills', href: '/invoices' },
];

export default function Invoices({ rows, properties, filters, statuses }: Props) {
    const go = (next: Partial<{ month: string; property_id: string; status: string }>) =>
        router.get(
            route('invoices.index'),
            Object.fromEntries(
                Object.entries({ month: filters.month ?? '', property_id: filters.property_id ?? '', status: filters.status ?? '', ...next }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="All bills" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader icon={Files} title="All bills" description="Every bill that has been created. Open one to see what is on it." />
                <BillingTabs active="invoices" />

                <div className="surface flex flex-wrap items-end gap-3 p-4">
                    <div className="space-y-1">
                        <Label htmlFor="i-month">Month</Label>
                        <input
                            id="i-month"
                            type="month"
                            className={selectClass + ' w-44'}
                            value={filters.month ?? ''}
                            onChange={(e) => go({ month: e.target.value })}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="i-status">Status</Label>
                        <SearchSelect
                            id="i-status"
                            className="w-40"
                            value={filters.status ?? ''}
                            onChange={(v) => go({ status: v })}
                            options={[{ value: '', label: 'All' }, ...statuses.map((s) => ({ value: s, label: paymentStatusLabels[s] }))]}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="i-property">Property</Label>
                        <SearchSelect
                            id="i-property"
                            className="w-52"
                            value={String(filters.property_id ?? '')}
                            onChange={(v) => go({ property_id: v })}
                            options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </div>
                </div>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No bills found.</p>
                ) : (
                    <ul className="space-y-3">
                        {rows.map((r) => (
                            <li
                                key={r.id}
                                className="surface hover:bg-muted/40 relative flex flex-wrap items-center justify-between gap-3 p-4 transition-colors"
                            >
                                <div className="min-w-0">
                                    <Link
                                        href={route('invoices.show', r.id)}
                                        className="font-medium after:absolute after:inset-0 focus-visible:outline-none"
                                    >
                                        {r.tenant}
                                    </Link>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="font-mono">{r.number}</span> · {r.unit} · {r.property} · due {formatDate(r.due_date)}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="font-mono font-semibold">{formatCents(r.current_total_cents)}</span>
                                    <Badge variant={paymentStatusVariant[r.status]}>{paymentStatusLabels[r.status]}</Badge>
                                    {r.status !== 'void' && (
                                        <Button asChild variant="outline" size="sm" className="relative z-10">
                                            <a
                                                href={route('invoices.statement.pdf', r.id)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={`Billing statement PDF for ${r.tenant}`}
                                            >
                                                <FileText /> Statement
                                            </a>
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
