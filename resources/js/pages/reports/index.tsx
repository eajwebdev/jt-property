import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, FileDown, FileSpreadsheet, HandCoins, History, Landmark, Receipt, Scale } from 'lucide-react';
import { useState } from 'react';

interface AgingRow {
    account_id: number;
    account_no: string;
    tenant: string;
    property: string;
    property_id: number;
    balance_cents: number;
    open_cents: number;
    oldest_due: string | null;
    buckets: Record<string, number>;
}
interface Props {
    filters: { property_id: number | null; month: string };
    properties: { id: number; name: string }[];
    aging: { rows: AgingRow[]; totals: Record<string, number>; net_receivable_cents: number };
    monthly: { billed_cents: number; collected_cents: number };
    propertySummary: {
        id: number;
        name: string;
        billed_cents: number;
        collected_cents: number;
        net_receivable_cents: number;
        capacity: number;
        occupied: number;
    }[];
    rentRoll: { account_id: number; account_no: string; tenant: string; property: string; unit: string; due_day: number; rent_cents: number }[];
    chargeCategories: Record<string, number>;
    depositsHeldCents: number;
    unpaidOpeningsCents: number;
    soaAsOf: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];
const buckets = ['current', '1-30', '31-60', '61-90', '90+'];

export default function Reports({
    filters,
    properties,
    aging,
    monthly,
    propertySummary,
    rentRoll,
    chargeCategories,
    depositsHeldCents,
    unpaidOpeningsCents,
    soaAsOf,
}: Props) {
    const [asOf, setAsOf] = useState(soaAsOf);
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
    const go = (propertyId: string, month: string) =>
        router.get(route('reports.index'), { property_id: propertyId || undefined, month }, { preserveState: true, replace: true });
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={BarChart3}
                    title="Reports"
                    description="Billings are accrual charges; collections are approved cash receipts. Aging uses unpaid ledger charges."
                    actions={
                        <>
                            <div className="flex items-center gap-2">
                                <label htmlFor="soa-as-of" className="text-muted-foreground text-sm whitespace-nowrap">
                                    As of
                                </label>
                                <input
                                    id="soa-as-of"
                                    type="date"
                                    className="border-input border px-3 text-sm"
                                    value={asOf}
                                    max={today}
                                    onChange={(e) => e.target.value && setAsOf(e.target.value)}
                                />
                                <Button asChild>
                                    <a
                                        href={route('reports.statement-of-accounts', { property_id: filters.property_id ?? undefined, as_of: asOf })}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title="Every boarder's unpaid rent, electric and other charges as of this date, on long bond paper (landscape)"
                                    >
                                        <FileDown /> Statement of Accounts
                                    </a>
                                </Button>
                            </div>
                            <Button asChild variant="outline">
                                <a
                                    href={route('reports.pdf', { property_id: filters.property_id ?? undefined, month: filters.month })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <FileDown /> All reports PDF
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a href={route('reports.aging.csv', { property_id: filters.property_id ?? undefined })}>
                                    <FileSpreadsheet /> Aging CSV
                                </a>
                            </Button>
                        </>
                    }
                />
                <div className="surface flex flex-wrap gap-3 p-4">
                    <label htmlFor="rp-property" className="grid gap-1.5 text-sm font-medium">
                        Property
                        <SearchSelect
                            id="rp-property"
                            className="w-56 font-normal"
                            value={String(filters.property_id ?? '')}
                            onChange={(v) => go(v, filters.month)}
                            options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium">
                        Month
                        <input
                            className="border-input border px-3 font-normal"
                            type="month"
                            value={filters.month}
                            onChange={(event) => event.target.value && go(String(filters.property_id ?? ''), event.target.value)}
                        />
                    </label>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <StatCard label="Billed in month" value={formatCents(monthly.billed_cents)} icon={Receipt} tone="brand" />
                    <StatCard label="Collected in month" value={formatCents(monthly.collected_cents)} icon={HandCoins} tone="success" />
                    <StatCard label="Net receivable now" value={formatCents(aging.net_receivable_cents)} icon={Scale} tone="danger" />
                    <StatCard label="Deposits held" value={formatCents(depositsHeldCents)} icon={Landmark} tone="info" hint="Liability, refundable" />
                    <StatCard label="Unpaid opening balances" value={formatCents(unpaidOpeningsCents)} icon={History} tone="warning" />
                </div>
                <section className="surface overflow-x-auto">
                    <h2 className="p-4 font-semibold">Property summary</h2>
                    <table className="w-full min-w-[700px] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Property</th>
                                <th className="p-3 text-right">Occupied / capacity</th>
                                <th className="p-3 text-right">Billed</th>
                                <th className="p-3 text-right">Collected</th>
                                <th className="p-3 text-right">Net receivable</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {propertySummary.map((row) => (
                                <tr key={row.id}>
                                    <td className="p-3">{row.name}</td>
                                    <td className="p-3 text-right">
                                        {row.occupied} / {row.capacity}
                                    </td>
                                    <td className="p-3 text-right">{formatCents(row.billed_cents)}</td>
                                    <td className="p-3 text-right">{formatCents(row.collected_cents)}</td>
                                    <td className="p-3 text-right">{formatCents(row.net_receivable_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
                <section className="surface p-4">
                    <h2 className="font-semibold">Billed charge types in month</h2>
                    <div className="mt-2 grid gap-2 sm:grid-cols-4">
                        {Object.entries(chargeCategories).map(([category, cents]) => (
                            <div key={category} className="bg-muted/50 rounded-lg p-3">
                                <p className="text-muted-foreground text-sm capitalize">{category.replaceAll('_', ' ')}</p>
                                <p className="font-mono font-semibold">{formatCents(cents)}</p>
                            </div>
                        ))}
                        {Object.keys(chargeCategories).length === 0 && (
                            <p className="text-muted-foreground text-sm">No bills issued in this month.</p>
                        )}
                    </div>
                </section>
                <section className="surface overflow-x-auto">
                    <div className="p-4">
                        <h2 className="font-semibold">Outstanding charges by days past due</h2>
                        <p className="text-muted-foreground text-sm">Unallocated account credits are reflected in net receivable above.</p>
                    </div>
                    <table className="w-full min-w-[850px] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Account / tenant</th>
                                <th className="p-3">Property</th>
                                <th className="p-3">Oldest due</th>
                                {buckets.map((bucket) => (
                                    <th className="p-3 text-right" key={bucket}>
                                        {bucket}
                                    </th>
                                ))}
                                <th className="p-3 text-right">Open total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {aging.rows.map((row) => (
                                <tr key={row.account_id}>
                                    <td className="p-3">
                                        <Link className="font-medium underline" href={route('collections.index', { property_id: row.property_id })}>
                                            {row.tenant}
                                        </Link>
                                        <span className="text-muted-foreground block">{row.account_no}</span>
                                    </td>
                                    <td className="p-3">{row.property}</td>
                                    <td className="p-3">{row.oldest_due ? formatDate(row.oldest_due) : '—'}</td>
                                    {buckets.map((bucket) => (
                                        <td className="p-3 text-right" key={bucket}>
                                            {formatCents(row.buckets[bucket])}
                                        </td>
                                    ))}
                                    <td className="p-3 text-right font-medium">{formatCents(row.open_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/50 font-semibold">
                            <tr>
                                <td className="p-3" colSpan={3}>
                                    Total
                                </td>
                                {buckets.map((bucket) => (
                                    <td className="p-3 text-right" key={bucket}>
                                        {formatCents(aging.totals[bucket])}
                                    </td>
                                ))}
                                <td className="p-3 text-right">{formatCents(Object.values(aging.totals).reduce((sum, value) => sum + value, 0))}</td>
                            </tr>
                        </tfoot>
                    </table>
                    {aging.rows.length === 0 && (
                        <p className="text-muted-foreground p-4 text-sm">No outstanding charges or credits for this property.</p>
                    )}
                </section>
                <section className="surface overflow-x-auto">
                    <div className="p-4">
                        <h2 className="font-semibold">Rent roll at month end</h2>
                        <p className="text-muted-foreground text-sm">
                            Contracted base rent for active agreements; invoices may include prorations and other charges.
                        </p>
                    </div>
                    <table className="w-full min-w-[650px] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Tenant / account</th>
                                <th className="p-3">Property</th>
                                <th className="p-3">Room</th>
                                <th className="p-3 text-right">Due day</th>
                                <th className="p-3 text-right">Base rent</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rentRoll.map((row, index) => (
                                <tr key={`${row.account_id}-${index}`}>
                                    <td className="p-3">
                                        {row.tenant}
                                        <span className="text-muted-foreground block">{row.account_no}</span>
                                    </td>
                                    <td className="p-3">{row.property}</td>
                                    <td className="p-3">{row.unit}</td>
                                    <td className="p-3 text-right">{row.due_day}</td>
                                    <td className="p-3 text-right">{formatCents(row.rent_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rentRoll.length === 0 && <p className="text-muted-foreground p-4 text-sm">No active agreements at month end.</p>}
                </section>
            </div>
        </AppLayout>
    );
}
