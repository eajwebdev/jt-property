import { RefundDialog } from '@/components/account-money-dialogs';
import { PageHeader } from '@/components/page-header';
import { RecordPaymentDialog } from '@/components/record-payment-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useState } from 'react';

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

export default function CollectionsIndex({ rows, properties, filters, canRecord, canManage, today, methods }: Props) {
    const { flash, auth } = usePage<SharedData>().props;
    const [paying, setPaying] = useState<{ open: boolean; accountId: number | null }>({ open: false, accountId: null });
    const [refunding, setRefunding] = useState<Row | null>(null);
    const isOwner = (auth.user as { role?: string }).role === 'owner';

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
                    description="Who owes what, oldest debts first. Record a payment and it settles the oldest charges first."
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

                <div className="flex flex-wrap items-center gap-2">
                    {tabs.map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            className="h-10"
                            variant={(filters.status ?? '') === key ? 'default' : 'outline'}
                            onClick={() => go({ status: key })}
                        >
                            {label}
                        </Button>
                    ))}
                    <div className="ml-auto flex items-center gap-2">
                        <Label htmlFor="col-property">Property</Label>
                        <SearchSelect
                            id="col-property"
                            className="w-48"
                            value={String(filters.property_id ?? '')}
                            onChange={(v) => go({ property_id: v })}
                            options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </div>
                </div>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">Nothing to collect here.</p>
                ) : (
                    <>
                        <ul className="space-y-3 md:hidden">
                            {rows.map((r) => (
                                <li key={r.account_id} className="surface space-y-2 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{r.tenant}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {r.account_no}
                                                {r.room ? ` - Room ${r.room}` : ''} - {r.property}
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
                                        Account statement
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
                                    {rows.map((r) => (
                                        <tr key={r.account_id} className="border-t">
                                            <td className="p-3 font-medium whitespace-nowrap">
                                                <Link className="underline" href={route('accounts.statement', r.account_id)}>
                                                    {r.account_no}
                                                </Link>
                                            </td>
                                            <td className="p-3">
                                                {r.tenant}
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
                accounts={rows}
                initialAccountId={paying.accountId}
                methods={methods}
                today={today}
                isOwner={isOwner}
            />
        </AppLayout>
    );
}
