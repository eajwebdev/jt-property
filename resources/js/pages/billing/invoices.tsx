import { BillingTabs, paymentStatusLabels, paymentStatusVariant } from '@/components/billing-tabs';
import { selectClass } from '@/components/recurring-charge-dialog';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

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
            Object.fromEntries(Object.entries({ month: filters.month ?? '', property_id: filters.property_id ?? '', status: filters.status ?? '', ...next }).filter(([, v]) => v !== '')),
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="All bills" />
            <div className="space-y-5 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">All bills</h1>
                    <p className="text-muted-foreground text-sm">Every bill that has been created. Open one to see what is on it.</p>
                </div>
                <BillingTabs active="invoices" />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Label htmlFor="i-month">Month</Label>
                        <input id="i-month" type="month" className={selectClass + ' w-44'} value={filters.month ?? ''} onChange={(e) => go({ month: e.target.value })} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="i-status">Status</Label>
                        <select id="i-status" className={selectClass + ' w-40'} value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value })}>
                            <option value="">All</option>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {paymentStatusLabels[s]}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="i-property">Property</Label>
                        <select id="i-property" className={selectClass + ' w-52'} value={filters.property_id ?? ''} onChange={(e) => go({ property_id: e.target.value })}>
                            <option value="">All properties</option>
                            {properties.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No bills found.</p>
                ) : (
                    <ul className="space-y-3">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <Link href={route('invoices.show', r.id)} className="hover:bg-muted/50 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                                    <div>
                                        <p className="font-medium">{r.tenant}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {r.number} - Room {r.unit} - {r.property} - due {formatDate(r.due_date)}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="font-semibold">{formatCents(r.current_total_cents)}</span>
                                        <Badge variant={paymentStatusVariant[r.status]}>{paymentStatusLabels[r.status]}</Badge>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
