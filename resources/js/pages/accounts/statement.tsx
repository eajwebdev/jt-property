import { TenantPayments, type TenantPayment } from '@/components/tenant-payments';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    BedDouble,
    CalendarRange,
    FileDown,
    FileText,
    History,
    Mail,
    Printer,
    RotateCcw,
    Scale,
    Undo2,
    UserRound,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { FormEvent, Fragment, useState } from 'react';

interface Row {
    date: string;
    type: string;
    memo: string | null;
    amount_cents: number;
    balance_cents: number;
    /** Payment lines: the payment and its receipt number. */
    payment_id?: number | null;
    receipt_number?: string | null;
}

interface Props {
    account: {
        id: number;
        number: string;
        tenant: string;
        tenant_id: number;
        email: string | null;
        property: string;
        rooms: string[];
        status: string;
    };
    payments: TenantPayment[];
    balance_cents: number;
    first_date: string;
    from: string;
    to: string;
    statement: { opening_cents: number; closing_cents: number; rows: Row[] };
}

/** How each kind of ledger line is shown: a plain name, an icon and a colour. */
const kinds: Record<string, { label: string; icon: LucideIcon; tone: string }> = {
    charge: { label: 'Bill', icon: FileText, tone: 'bg-brand/10 text-brand' },
    opening: { label: 'Balance brought forward', icon: History, tone: 'bg-warning/12 text-warning' },
    payment: { label: 'Payment', icon: Wallet, tone: 'bg-success/12 text-success' },
    credit: { label: 'Credit', icon: ArrowDownLeft, tone: 'bg-success/12 text-success' },
    adjustment: { label: 'Adjustment', icon: Scale, tone: 'bg-muted text-muted-foreground' },
    refund: { label: 'Refund', icon: Undo2, tone: 'bg-info/12 text-info' },
    reversal: { label: 'Reversal', icon: RotateCcw, tone: 'bg-destructive/12 text-destructive' },
    writeoff: { label: 'Write-off', icon: ArrowUpRight, tone: 'bg-muted text-muted-foreground' },
};

/** The label already says "Bill", so "Invoice MBH-202610-0026" shows as "MBH-202610-0026"; "Payment other" as "Other". */
function details(row: Row): string | null {
    if (!row.memo) return null;
    if (row.type === 'payment')
        return row.memo
            .replace(/^Payment\s+/i, '')
            .replace(/_/g, ' ')
            .replace(/^\w/, (c) => c.toUpperCase());
    return row.memo.replace(/^Invoice\s+/i, '');
}

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const monthLabel = (date: string) => new Date(`${date.slice(0, 7)}-01T12:00:00`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });

function Figure({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: string }) {
    return (
        <div className="min-w-0 px-4 py-3">
            <dt className="text-muted-foreground truncate text-xs font-medium">{label}</dt>
            <dd className={cn('mt-0.5 font-mono text-lg font-semibold tabular-nums', tone)}>{value}</dd>
            {hint && <dd className="text-muted-foreground truncate text-xs">{hint}</dd>}
        </div>
    );
}

export default function AccountStatement({ account, from, to, statement, payments, balance_cents, first_date }: Props) {
    const [start, setStart] = useState(from);
    const [end, setEnd] = useState(to);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Payments', href: '/collections' },
        { title: `${account.tenant} · ${account.number}`, href: route('accounts.statement', account.id) },
    ];
    const show = (nextFrom: string, nextTo: string) => router.get(route('accounts.statement', account.id), { from: nextFrom, to: nextTo });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        show(start, end);
    };

    const today = new Date();
    const presets: [string, string, string][] = [
        ['This month', ymd(new Date(today.getFullYear(), today.getMonth(), 1)), ymd(today)],
        ['Last 3 months', ymd(new Date(today.getFullYear(), today.getMonth() - 3, today.getDate())), ymd(today)],
        ['This year', ymd(new Date(today.getFullYear(), 0, 1)), ymd(today)],
        ['All time', first_date < ymd(today) ? first_date : ymd(today), ymd(today)],
    ];

    const charges = statement.rows.filter((r) => r.amount_cents > 0).reduce((sum, r) => sum + r.amount_cents, 0);
    const paid = statement.rows.filter((r) => r.amount_cents < 0).reduce((sum, r) => sum - r.amount_cents, 0);
    const owes = balance_cents > 0;
    const credit = balance_cents < 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Ledger · ${account.tenant}`} />
            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-6">
                {/* Who and where they stand today. */}
                <section className="surface overflow-hidden">
                    <div className="flex flex-wrap items-center gap-4 p-5">
                        <span
                            className="bg-brand text-brand-foreground grid size-14 shrink-0 place-items-center rounded-2xl text-lg font-bold"
                            aria-hidden
                        >
                            {account.tenant
                                .split(/[\s,]+/)
                                .filter(Boolean)
                                .slice(0, 2)
                                .map((w) => w[0])
                                .join('')}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">Account ledger</p>
                            <h1 className="truncate text-2xl font-semibold tracking-tight">{account.tenant}</h1>
                            <p className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span className="font-mono">{account.number}</span>
                                <span>{account.property}</span>
                                {account.rooms.map((room) => (
                                    <span key={room} className="inline-flex items-center gap-1">
                                        <BedDouble className="size-3.5" aria-hidden /> {room}
                                    </span>
                                ))}
                                {account.email && (
                                    <span className="inline-flex items-center gap-1">
                                        <Mail className="size-3.5" aria-hidden /> {account.email}
                                    </span>
                                )}
                                {account.status !== 'active' && <Badge variant="muted">Closed account</Badge>}
                            </p>
                        </div>
                        <div
                            className={cn('rounded-2xl px-5 py-3 text-right', owes ? 'bg-destructive/8' : credit ? 'bg-success/10' : 'bg-success/10')}
                        >
                            <p className="text-muted-foreground text-xs font-medium">Balance today</p>
                            <p className={cn('font-mono text-3xl font-bold tracking-tight tabular-nums', owes ? 'text-destructive' : 'text-success')}>
                                {formatCents(Math.abs(balance_cents))}
                            </p>
                            <p className={cn('text-xs font-semibold', owes ? 'text-destructive' : 'text-success')}>
                                {owes ? 'Still owed' : credit ? 'Credit for the next bill' : 'Fully paid'}
                            </p>
                        </div>
                    </div>
                    <div className="bg-muted/40 flex flex-wrap items-center justify-end gap-2 border-t px-5 py-3">
                        <Button asChild variant="outline">
                            <Link href={route('tenants.show', account.tenant_id)}>
                                <UserRound /> Tenant page
                            </Link>
                        </Button>
                        <Button asChild>
                            <a href={route('accounts.statement.pdf', { account: account.id, from, to })} target="_blank" rel="noopener noreferrer">
                                <FileDown /> Print ledger (PDF)
                            </a>
                        </Button>
                    </div>
                </section>

                {/* Which period to show. */}
                <form onSubmit={submit} className="surface flex flex-wrap items-end gap-3 p-4">
                    <div className="grid gap-1.5">
                        <span className="text-sm font-medium">Show</span>
                        <div className="bg-muted/60 inline-flex flex-wrap gap-1 rounded-lg p-1" role="group" aria-label="Period">
                            {presets.map(([label, f, t]) => {
                                const active = from === f && to === t;
                                return (
                                    <button
                                        key={label}
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => show(f, t)}
                                        className={cn(
                                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                            active ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={start} onChange={(e) => setStart(e.target.value)} required />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" min={start} value={end} onChange={(e) => setEnd(e.target.value)} required />
                    </div>
                    <Button variant="outline">
                        <CalendarRange /> Show these dates
                    </Button>
                </form>

                {/* The period in four numbers: opening + bills − payments = closing. */}
                <dl className="surface grid grid-cols-2 divide-x divide-y sm:grid-cols-4 sm:divide-y-0">
                    <Figure label={`Balance on ${formatDate(from)}`} value={formatCents(statement.opening_cents)} hint="Before this period" />
                    <Figure label="Bills added" value={`+ ${formatCents(charges)}`} hint="Rent, electricity and charges" />
                    <Figure label="Payments & credits" value={`− ${formatCents(paid)}`} hint="Received in this period" tone="text-success" />
                    <Figure
                        label={`Balance on ${formatDate(to)}`}
                        value={formatCents(statement.closing_cents)}
                        hint={statement.closing_cents > 0 ? 'Owed' : statement.closing_cents < 0 ? 'Credit' : 'Fully paid'}
                        tone={statement.closing_cents > 0 ? 'text-destructive' : 'text-success'}
                    />
                </dl>

                {/* The ledger, like a passbook. */}
                <section className="surface overflow-hidden" aria-labelledby="ledger-title">
                    <div className="flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                        <h2 id="ledger-title" className="font-semibold">
                            Ledger
                        </h2>
                        <p className="text-muted-foreground text-xs">
                            {formatDate(from)} to {formatDate(to)} · {statement.rows.length} {statement.rows.length === 1 ? 'entry' : 'entries'}
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <caption className="sr-only">Ledger entries</caption>
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs tracking-wide uppercase">
                                <tr>
                                    <th className="px-4 py-2.5 font-semibold">Date</th>
                                    <th className="px-4 py-2.5 font-semibold">Details</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Charges</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Payments</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="bg-muted/20 border-b">
                                    <td className="text-muted-foreground px-4 py-2.5 whitespace-nowrap">{formatDate(from)}</td>
                                    <td className="px-4 py-2.5 font-medium" colSpan={3}>
                                        Balance at the start of the period
                                    </td>
                                    <td className="px-4 py-2.5 text-right font-mono font-semibold tabular-nums">
                                        {formatCents(statement.opening_cents)}
                                    </td>
                                </tr>
                                {statement.rows.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">
                                            Nothing was billed or paid in this period.
                                        </td>
                                    </tr>
                                )}
                                {statement.rows.map((row, index) => {
                                    const kind = kinds[row.type] ?? { label: row.type, icon: FileText, tone: 'bg-muted text-muted-foreground' };
                                    const newMonth = index === 0 || row.date.slice(0, 7) !== statement.rows[index - 1].date.slice(0, 7);
                                    const text = details(row);
                                    return (
                                        <Fragment key={index}>
                                            {newMonth && (
                                                <tr className="bg-card">
                                                    <td
                                                        colSpan={5}
                                                        className="text-muted-foreground px-4 pt-4 pb-1 text-xs font-semibold tracking-wider uppercase"
                                                    >
                                                        {monthLabel(row.date)}
                                                    </td>
                                                </tr>
                                            )}
                                            <tr className="hover:bg-muted/30 border-b transition-colors last:border-0">
                                                <td className="px-4 py-2.5 whitespace-nowrap">{formatDate(row.date)}</td>
                                                <td className="px-4 py-2.5">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                                                kind.tone,
                                                            )}
                                                        >
                                                            <kind.icon className="size-3" aria-hidden /> {kind.label}
                                                        </span>
                                                        {text && <span>{text}</span>}
                                                        {row.payment_id && row.receipt_number && (
                                                            <a
                                                                href={route('payments.acknowledgment', row.payment_id)}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="text-primary inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium hover:underline"
                                                            >
                                                                <Printer className="size-3" aria-hidden /> {row.receipt_number}
                                                            </a>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                                    {row.amount_cents > 0 ? formatCents(row.amount_cents) : ''}
                                                </td>
                                                <td className="text-success px-4 py-2.5 text-right font-mono tabular-nums">
                                                    {row.amount_cents < 0 ? formatCents(-row.amount_cents) : ''}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'px-4 py-2.5 text-right font-mono font-semibold tabular-nums',
                                                        row.balance_cents < 0 && 'text-success',
                                                    )}
                                                >
                                                    {formatCents(row.balance_cents)}
                                                </td>
                                            </tr>
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr className="bg-brand text-brand-foreground font-semibold">
                                    <td className="px-4 py-3" colSpan={2}>
                                        Balance on {formatDate(to)}
                                    </td>
                                    <td className="px-4 py-3 text-right font-mono tabular-nums">{formatCents(charges)}</td>
                                    <td className="px-4 py-3 text-right font-mono tabular-nums">{formatCents(paid)}</td>
                                    <td className="px-4 py-3 text-right font-mono text-base tabular-nums">{formatCents(statement.closing_cents)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p className="text-muted-foreground border-t px-4 py-2.5 text-xs">
                        Balance above zero is owed to the boarding house; below zero is credit that goes on the next bill.
                    </p>
                </section>

                <TenantPayments payments={payments} email={account.email} title="All payments & receipts" />
            </div>
        </AppLayout>
    );
}
