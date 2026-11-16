import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FileDown, Scale, ScrollText, Search } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Props {
    account: { id: number; number: string; tenant: string; property: string };
    from: string;
    to: string;
    statement: { opening_cents: number; closing_cents: number; rows: { date: string; type: string; memo: string | null; amount_cents: number; balance_cents: number }[] };
}

export default function AccountStatement({ account, from, to, statement }: Props) {
    const [start, setStart] = useState(from);
    const [end, setEnd] = useState(to);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Collections', href: '/collections' },
        { title: account.number, href: route('accounts.statement', account.id) },
    ];
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('accounts.statement', account.id), { from: start, to: end });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Statement ${account.number}`} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={ScrollText}
                    title="Account statement"
                    description={`${account.tenant} · ${account.number} · ${account.property}`}
                    actions={
                        <Button asChild variant="outline">
                            <a href={route('accounts.statement.pdf', { account: account.id, from: start, to: end })} target="_blank" rel="noopener noreferrer">
                                <FileDown /> View PDF
                            </a>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="surface flex flex-wrap items-end gap-3 p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={start} onChange={(e) => setStart(e.target.value)} required />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" min={start} value={end} onChange={(e) => setEnd(e.target.value)} required />
                    </div>
                    <Button>
                        <Search /> View
                    </Button>
                </form>

                <div className="grid gap-3 sm:grid-cols-2">
                    <StatCard label={`Opening balance · ${formatDate(from)}`} value={formatCents(statement.opening_cents)} icon={Scale} tone="brand" />
                    <StatCard label={`Closing balance · ${formatDate(to)}`} value={formatCents(statement.closing_cents)} icon={Scale} tone={statement.closing_cents > 0 ? 'danger' : 'success'} />
                </div>

                <div className="surface overflow-x-auto">
                    <table className="w-full min-w-[600px] text-sm">
                        <caption className="sr-only">Ledger entries</caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3">Date</th>
                                <th className="px-4 py-3">Description</th>
                                <th className="px-4 py-3 text-right">Change</th>
                                <th className="px-4 py-3 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            <tr>
                                <td className="text-muted-foreground px-4 py-3">—</td>
                                <td className="px-4 py-3 font-medium">Opening balance</td>
                                <td />
                                <td className="px-4 py-3 text-right font-mono">{formatCents(statement.opening_cents)}</td>
                            </tr>
                            {statement.rows.map((row, index) => (
                                <tr key={index}>
                                    <td className="px-4 py-3 whitespace-nowrap">{formatDate(row.date)}</td>
                                    <td className="px-4 py-3">
                                        <span className="capitalize">{row.type}</span>
                                        {row.memo && <span className="text-muted-foreground"> — {row.memo}</span>}
                                    </td>
                                    <td className={cn('px-4 py-3 text-right font-mono', row.amount_cents < 0 ? 'text-success' : '')}>{formatCents(row.amount_cents)}</td>
                                    <td className="px-4 py-3 text-right font-mono">{formatCents(row.balance_cents)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/50 font-semibold">
                            <tr>
                                <td className="px-4 py-3" colSpan={3}>
                                    Closing balance
                                </td>
                                <td className="px-4 py-3 text-right font-mono">{formatCents(statement.closing_cents)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
