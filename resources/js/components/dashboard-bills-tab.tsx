import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { BookOpen, CircleCheck, CircleDashed, CircleDot, FileText, PiggyBank, Search, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

type Status = 'unpaid' | 'partial' | 'paid' | 'advance';

export interface BillPayments {
    month: string | null;
    due_date: string | null;
    counts: Record<Status, number>;
    billed_cents: number;
    collected_cents: number;
    unpaid_cents: number;
    rows: {
        invoice_id: number;
        number: string;
        account_id: number;
        account_no: string;
        tenant: string;
        email: string | null;
        room: string;
        property: string;
        due_date: string;
        bill_cents: number;
        paid_cents: number;
        remaining_cents: number;
        balance_cents: number;
        status: Status;
        overdue: boolean;
        last_paid_on: string | null;
    }[];
}

const statuses: Record<
    Status,
    { label: string; hint: string; icon: LucideIcon; tone: string; ring: string; badge: 'danger' | 'warning' | 'success' | 'info' }
> = {
    unpaid: {
        label: 'Not paid',
        hint: 'Nothing paid on this bill yet',
        icon: CircleDashed,
        tone: 'text-destructive',
        ring: 'border-destructive/40 bg-destructive/5',
        badge: 'danger',
    },
    partial: {
        label: 'Partly paid',
        hint: 'Paid some, still owes the rest',
        icon: CircleDot,
        tone: 'text-warning',
        ring: 'border-warning/50 bg-warning/5',
        badge: 'warning',
    },
    paid: {
        label: 'Paid',
        hint: 'This bill is paid in full',
        icon: CircleCheck,
        tone: 'text-success',
        ring: 'border-success/40 bg-success/5',
        badge: 'success',
    },
    advance: {
        label: 'Paid ahead',
        hint: 'Paid in full with credit left for the next bill',
        icon: PiggyBank,
        tone: 'text-info',
        ring: 'border-info/40 bg-info/5',
        badge: 'info',
    },
};

const monthName = (ym: string) => new Date(`${ym}-01T12:00:00`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });

/** Who has paid the latest month's bills: not paid, partly paid, paid, or paid ahead. Unpaid ones are listed first. */
export function DashboardBillsTab({ bills }: { bills: BillPayments }) {
    const [filter, setFilter] = useState<Status | 'all' | 'owing'>('owing');
    const [search, setSearch] = useState('');

    if (bills.month === null) {
        return (
            <EmptyState icon={FileText} title="No bills yet" description="Generate a month's bills on the Bills screen; their payments show here." />
        );
    }

    const total = bills.rows.length;
    const owing = bills.counts.unpaid + bills.counts.partial;
    const collected = bills.billed_cents > 0 ? Math.round((bills.collected_cents / bills.billed_cents) * 100) : 0;
    const q = search.trim().toLowerCase();
    const rows = bills.rows.filter(
        (r) =>
            (filter === 'all' || (filter === 'owing' ? r.status === 'unpaid' || r.status === 'partial' : r.status === filter)) &&
            (q === '' || `${r.tenant} ${r.room} ${r.account_no} ${r.number}`.toLowerCase().includes(q)),
    );

    return (
        <div className="space-y-4">
            <section className="surface p-5">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">Latest bills</p>
                        <h2 className="text-xl font-semibold">{monthName(bills.month)}</h2>
                        <p className="text-muted-foreground text-sm">
                            {total} {total === 1 ? 'bill' : 'bills'}
                            {bills.due_date && ` · due ${formatDate(bills.due_date)}`} ·{' '}
                            {owing === 0 ? 'everyone has paid' : `${owing} still to collect from`}
                        </p>
                    </div>
                    <div className="min-w-60 flex-1 sm:max-w-sm">
                        <div className="flex justify-between text-sm">
                            <span className="text-muted-foreground">Collected</span>
                            <span className="font-mono font-semibold tabular-nums">
                                {formatCents(bills.collected_cents)}{' '}
                                <span className="text-muted-foreground font-normal">of {formatCents(bills.billed_cents)}</span>
                            </span>
                        </div>
                        <div
                            className="bg-muted mt-1.5 h-2.5 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-valuenow={collected}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label="Collected"
                        >
                            <div className="bg-success h-full rounded-full transition-all" style={{ width: `${collected}%` }} />
                        </div>
                        <p className="text-muted-foreground mt-1 text-xs">
                            {collected}% collected ·{' '}
                            <span className="text-destructive font-medium">{formatCents(bills.unpaid_cents)} still unpaid</span>
                        </p>
                    </div>
                </div>
            </section>

            {/* The four counts double as filters. */}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {(Object.keys(statuses) as Status[]).map((key) => {
                    const s = statuses[key];
                    const active = filter === key;
                    return (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={active}
                            onClick={() => setFilter(active ? 'all' : key)}
                            className={cn(
                                'surface focus-visible:ring-ring flex items-start gap-3 border-2 p-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                active ? s.ring : 'hover:bg-muted/40 border-transparent',
                            )}
                        >
                            <s.icon className={cn('mt-0.5 size-5 shrink-0', s.tone)} aria-hidden />
                            <span className="min-w-0">
                                <span className="text-muted-foreground block text-sm font-medium">{s.label}</span>
                                <span className={cn('block font-mono text-2xl font-semibold tabular-nums', bills.counts[key] > 0 && s.tone)}>
                                    {bills.counts[key]}
                                </span>
                                <span className="text-muted-foreground block text-xs">{s.hint}</span>
                            </span>
                        </button>
                    );
                })}
            </div>

            <section className="surface overflow-hidden">
                <div className="flex flex-wrap items-center gap-2 border-b p-3">
                    <div className="bg-muted/60 inline-flex flex-wrap gap-1 rounded-lg p-1 text-xs" role="group" aria-label="Show">
                        {(
                            [
                                ['owing', `Still owing ${owing}`],
                                ['all', `All ${total}`],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={filter === key}
                                onClick={() => setFilter(key)}
                                className={cn(
                                    'rounded-md px-2.5 py-1 font-medium',
                                    filter === key ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                        {filter !== 'owing' && filter !== 'all' && (
                            <span className="bg-card text-foreground shadow-soft rounded-md px-2.5 py-1 font-medium">
                                {statuses[filter].label} {bills.counts[filter]}
                            </span>
                        )}
                    </div>
                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search boarder or room"
                            aria-label="Search boarder or room"
                            className="h-9 pl-8"
                        />
                    </div>
                </div>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground p-8 text-center text-sm">
                        {filter === 'owing' && q === '' ? 'Everyone has paid this month’s bill.' : 'No boarders match.'}
                    </p>
                ) : (
                    <div className="max-h-[calc(100dvh-24rem)] min-h-64 overflow-auto">
                        <table className="w-full min-w-[760px] text-sm">
                            <caption className="sr-only">Payments on the {monthName(bills.month)} bills</caption>
                            <thead className="bg-muted/80 text-muted-foreground sticky top-0 z-10 text-left text-xs tracking-wide uppercase backdrop-blur">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Boarder</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                    <th className="px-3 py-2 text-right font-semibold">Bill</th>
                                    <th className="px-3 py-2 text-right font-semibold">Paid</th>
                                    <th className="px-3 py-2 text-right font-semibold">Still owes</th>
                                    <th className="px-3 py-2 text-right font-semibold" title="Everything owed, including earlier months">
                                        Total owed
                                    </th>
                                    <th className="px-3 py-2 font-semibold">Last paid</th>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((r) => {
                                    const s = statuses[r.status];
                                    return (
                                        <tr key={r.invoice_id} className="hover:bg-muted/30">
                                            <td className="max-w-72 px-3 py-2">
                                                <span className="block truncate font-medium">{r.tenant}</span>
                                                <span className="text-muted-foreground block truncate text-xs">{r.room}</span>
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="flex flex-wrap items-center gap-1">
                                                    <Badge variant={s.badge}>
                                                        <s.icon className="size-3" aria-hidden /> {s.label}
                                                    </Badge>
                                                    {r.overdue && <Badge variant="danger">Overdue</Badge>}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <span className="block font-mono tabular-nums">{formatCents(r.bill_cents)}</span>
                                                <Link
                                                    href={route('invoices.show', r.invoice_id)}
                                                    className="text-muted-foreground block text-xs whitespace-nowrap hover:underline"
                                                    title="Open this bill"
                                                >
                                                    {r.number}
                                                </Link>
                                            </td>
                                            <td className="text-success px-3 py-2 text-right font-mono tabular-nums">
                                                {r.paid_cents > 0 ? formatCents(r.paid_cents) : '—'}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right font-mono font-semibold tabular-nums',
                                                    r.remaining_cents > 0 && 'text-destructive',
                                                )}
                                            >
                                                {r.remaining_cents > 0 ? formatCents(r.remaining_cents) : '—'}
                                            </td>
                                            <td className={cn('px-3 py-2 text-right font-mono tabular-nums', r.balance_cents < 0 && 'text-success')}>
                                                {r.balance_cents < 0 ? `${formatCents(-r.balance_cents)} credit` : formatCents(r.balance_cents)}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap">
                                                {r.last_paid_on ? formatDate(r.last_paid_on) : 'Never'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="flex justify-end">
                                                    <Button asChild size="icon" variant="outline" className="size-8" title="Ledger & receipts">
                                                        <Link
                                                            href={route('accounts.statement', r.account_id)}
                                                            aria-label={`Ledger and receipts of ${r.tenant}`}
                                                        >
                                                            <BookOpen />
                                                        </Link>
                                                    </Button>
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="text-muted-foreground border-t px-3 py-2 text-xs">
                    To record a payment, open{' '}
                    <Link href={route('collections.index')} className="text-primary font-medium hover:underline">
                        Payments
                    </Link>
                    . Payments settle the oldest bill first, so an old unpaid balance is paid before this month's bill.
                </p>
            </section>
        </div>
    );
}
