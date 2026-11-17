import { RefundDialog } from '@/components/account-money-dialogs';
import { PageHeader } from '@/components/page-header';
import { RecordPaymentDialog } from '@/components/record-payment-dialog';
import { StatCard } from '@/components/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Clock3, HandCoins, Search, UsersRound, Wallet, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Row {
    account_id: number;
    account_no: string;
    tenant: string;
    property: string;
    property_id: number;
    room: string | null;
    balance_cents: number;
    balance: string;
    is_credit: boolean;
    oldest_due: string | null;
    aging: string | null;
}
interface Props {
    rows: Row[];
    paymentAccounts: { account_id: number; account_no: string; tenant: string }[];
    summary: { total_due_cents: number; overdue_accounts: number; credit_cents: number; accounts_with_balance: number };
    statusCounts: Record<string, number>;
    properties: { id: number; name: string }[];
    filters: { status: string | null; property_id: number | null };
    canRecord: boolean;
    canManage: boolean;
    today: string;
    methods: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Collections', href: '/collections' }];
const tabs: [string, string][] = [
    ['', 'All'],
    ['unpaid', 'Unpaid'],
    ['partial', 'Partly paid'],
    ['overdue', 'Overdue'],
    ['credit', 'Credit'],
];
const agingLabels: Record<string, string> = {
    current: 'Not yet due',
    '1-30': '1-30 days late',
    '31-60': '31-60 days late',
    '61-90': '61-90 days late',
    '90+': 'Over 90 days late',
};

function Aging({ value }: { value: string | null }) {
    if (!value) return <span className="text-muted-foreground">-</span>;
    return <Badge variant={value === 'current' ? 'outline' : value === '1-30' ? 'secondary' : 'destructive'}>{agingLabels[value]}</Badge>;
}

export default function CollectionsIndex({
    rows,
    paymentAccounts,
    summary,
    statusCounts,
    properties,
    filters,
    canRecord,
    canManage,
    today,
    methods,
}: Props) {
    const { flash, auth } = usePage<SharedData>().props;
    const [paying, setPaying] = useState<{ open: boolean; accountId: number | null }>({ open: false, accountId: null });
    const [refunding, setRefunding] = useState<Row | null>(null);
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<'balance' | 'due' | 'tenant'>('balance');
    const isOwner = (auth.user as { role?: string }).role === 'owner';

    const visibleRows = useMemo(() => {
        const term = search.trim().toLocaleLowerCase();
        const found = term
            ? rows.filter((row) => `${row.tenant} ${row.account_no} ${row.room ?? ''} ${row.property}`.toLocaleLowerCase().includes(term))
            : rows;

        return [...found].sort((a, b) => {
            if (sort === 'tenant') return a.tenant.localeCompare(b.tenant);
            if (sort === 'due')
                return (a.oldest_due ?? '9999-12-31').localeCompare(b.oldest_due ?? '9999-12-31') || b.balance_cents - a.balance_cents;
            return b.balance_cents - a.balance_cents || a.tenant.localeCompare(b.tenant);
        });
    }, [rows, search, sort]);

    const go = (next: Partial<{ status: string; property_id: string }>) =>
        router.get(
            route('collections.index'),
            Object.fromEntries(
                Object.entries({ status: filters.status ?? '', property_id: filters.property_id ?? '', ...next }).filter(([, v]) => v !== ''),
            ),
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Collections" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Wallet}
                    title="Collections"
                    description="Find a boarder, check what is owed, and record a payment. Payments settle the oldest charges first."
                    actions={
                        canRecord && (
                            <Button size="lg" className="w-full sm:w-auto" onClick={() => setPaying({ open: true, accountId: null })}>
                                <Wallet /> Record Payment
                            </Button>
                        )
                    }
                />

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Total to collect" value={formatCents(summary.total_due_cents)} icon={Wallet} tone="brand" />
                    <StatCard label="Overdue accounts" value={summary.overdue_accounts} icon={Clock3} tone="danger" />
                    <StatCard label="Accounts on list" value={summary.accounts_with_balance} icon={UsersRound} tone="info" />
                    <StatCard label="Tenant credits" value={formatCents(summary.credit_cents)} icon={HandCoins} tone="success" />
                </div>

                <div className="surface space-y-4 p-3 sm:p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {tabs.map(([key, label]) => (
                            <Button
                                key={key}
                                size="sm"
                                className="h-10"
                                variant={(filters.status ?? '') === key ? 'default' : 'outline'}
                                onClick={() => go({ status: key })}
                            >
                                {label} <span className="ml-1 text-xs opacity-70">{statusCounts[key || 'all'] ?? 0}</span>
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <div className="min-w-0 flex-1">
                            <Label htmlFor="collections-search" className="mb-1.5 block">
                                Find a boarder, room, or account
                            </Label>
                            <div className="relative">
                                <Search className="text-muted-foreground pointer-events-none absolute top-3 left-3 size-4" aria-hidden />
                                <Input
                                    id="collections-search"
                                    type="text"
                                    className="pr-10 pl-9"
                                    placeholder="Search name, room, or JT number"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                                {search && (
                                    <button
                                        type="button"
                                        onClick={() => setSearch('')}
                                        className="text-muted-foreground hover:text-foreground absolute top-2 right-2 p-1"
                                        aria-label="Clear search"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </div>
                        </div>
                        <div className="flex gap-3">
                            <div className="min-w-40 flex-1 lg:flex-none">
                                <Label htmlFor="collections-sort" className="mb-1.5 block">
                                    Sort by
                                </Label>
                                <select
                                    id="collections-sort"
                                    className="border-input bg-card h-10 w-full rounded-lg border px-3 text-sm"
                                    value={sort}
                                    onChange={(event) => setSort(event.target.value as typeof sort)}
                                >
                                    <option value="balance">Largest balance</option>
                                    <option value="due">Oldest due</option>
                                    <option value="tenant">Tenant name</option>
                                </select>
                            </div>
                            {properties.length > 1 && (
                                <div className="min-w-44 flex-1 lg:flex-none">
                                    <Label htmlFor="col-property" className="mb-1.5 block">
                                        Property
                                    </Label>
                                    <SearchSelect
                                        id="col-property"
                                        value={String(filters.property_id ?? '')}
                                        onChange={(v) => go({ property_id: v })}
                                        options={[
                                            { value: '', label: 'All properties' },
                                            ...properties.map((p) => ({ value: String(p.id), label: p.name })),
                                        ]}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs" aria-live="polite">
                        Showing {visibleRows.length} of {rows.length} accounts in this view
                    </p>
                </div>

                {visibleRows.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">
                        <p>{search ? 'No accounts match your search.' : 'Nothing to collect here.'}</p>
                        {search && (
                            <Button variant="link" onClick={() => setSearch('')}>
                                Clear search
                            </Button>
                        )}
                    </div>
                ) : (
                    <>
                        <ul className="space-y-3 md:hidden">
                            {visibleRows.map((r) => (
                                <li key={r.account_id} className="surface space-y-2 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{r.tenant}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {r.account_no}
                                                {r.room ? ` · ${r.room}` : ''} · {r.property}
                                            </p>
                                        </div>
                                        <p
                                            className={
                                                'text-lg font-semibold whitespace-nowrap ' + (r.is_credit ? 'text-green-700 dark:text-green-400' : '')
                                            }
                                        >
                                            {r.balance}
                                            {r.is_credit && <span className="block text-xs font-normal">credit</span>}
                                        </p>
                                    </div>
                                    <div className="flex items-center justify-between gap-2 text-sm">
                                        <span>{r.oldest_due ? 'Oldest due ' + formatDate(r.oldest_due) : ''}</span>
                                        <Aging value={r.aging} />
                                    </div>
                                    {canRecord && (
                                        <Button
                                            variant="outline"
                                            className="h-11 w-full"
                                            onClick={() => setPaying({ open: true, accountId: r.account_id })}
                                        >
                                            Record payment
                                        </Button>
                                    )}
                                    <Link className="block text-center text-sm underline" href={route('accounts.statement', r.account_id)}>
                                        Ledger &amp; receipts
                                    </Link>
                                    {canManage && r.is_credit && (
                                        <div className="flex gap-2">
                                            <Button variant="ghost" className="h-11 flex-1" onClick={() => setRefunding(r)}>
                                                Refund
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <div className="surface hidden overflow-x-auto md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Account</th>
                                        <th className="p-3">Tenant</th>
                                        <th className="p-3">Room</th>
                                        <th className="p-3 text-right">Balance</th>
                                        <th className="p-3">Oldest due</th>
                                        <th className="p-3">Aging</th>
                                        {canRecord && <th className="p-3" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {visibleRows.map((r) => (
                                        <tr key={r.account_id} className="border-t">
                                            <td className="p-3 font-medium whitespace-nowrap">
                                                <Link className="underline" href={route('accounts.statement', r.account_id)}>
                                                    {r.account_no}
                                                </Link>
                                            </td>
                                            <td className="p-3">
                                                <Link className="font-medium hover:underline" href={route('accounts.statement', r.account_id)}>
                                                    {r.tenant}
                                                </Link>
                                                <span className="text-muted-foreground block text-xs">{r.property}</span>
                                            </td>
                                            <td className="p-3">{r.room ?? '-'}</td>
                                            <td
                                                className={
                                                    'p-3 text-right font-medium whitespace-nowrap ' +
                                                    (r.is_credit ? 'text-green-700 dark:text-green-400' : '')
                                                }
                                            >
                                                {r.balance}
                                                {r.is_credit && ' credit'}
                                            </td>
                                            <td className="p-3 whitespace-nowrap">{r.oldest_due ? formatDate(r.oldest_due) : '-'}</td>
                                            <td className="p-3">
                                                <Aging value={r.aging} />
                                            </td>
                                            {canRecord && (
                                                <td className="flex justify-end gap-1 p-3 text-right">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setPaying({ open: true, accountId: r.account_id })}
                                                    >
                                                        Record payment
                                                    </Button>
                                                    <Button asChild size="sm" variant="ghost">
                                                        <Link
                                                            href={route('accounts.statement', r.account_id)}
                                                            title="All payments, receipts and the full ledger"
                                                        >
                                                            <BookOpen /> Ledger &amp; receipts
                                                        </Link>
                                                    </Button>
                                                    {canManage && r.is_credit && (
                                                        <Button size="sm" variant="ghost" onClick={() => setRefunding(r)}>
                                                            Refund
                                                        </Button>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            {refunding && (
                <RefundDialog
                    open
                    onOpenChange={(o) => !o && setRefunding(null)}
                    accountId={refunding.account_id}
                    tenant={refunding.tenant}
                    credit={refunding.balance}
                />
            )}
            <RecordPaymentDialog
                open={paying.open}
                onOpenChange={(o) => setPaying((p) => ({ ...p, open: o }))}
                accounts={paymentAccounts}
                initialAccountId={paying.accountId}
                methods={methods}
                today={today}
                isOwner={isOwner}
            />
        </AppLayout>
    );
}
