import { GroupedBarChart } from '@/components/charts/grouped-bar-chart';
import { DashboardBillsTab, type BillPayments } from '@/components/dashboard-bills-tab';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { methodLabels } from '@/components/record-payment-dialog';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    ArrowRight,
    BedDouble,
    Building2,
    CalendarClock,
    CircleAlert,
    Clock,
    FileText,
    HandCoins,
    LayoutGrid,
    LogIn,
    LogOut,
    Plus,
    Receipt,
    ReceiptText,
    TrendingUp,
    Wallet,
    Zap,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

interface Metrics {
    properties: number;
    rooms: number;
    capacity: number;
    occupied: number;
    vacant: number;
    billed_cents: number;
    collected_cents: number;
    still_due_cents: number;
    overdue_cents: number;
    coming_due_cents: number;
    pending_utilities: number;
    late_accounts: number;
    /** Latest month with bills ("2026-10") and how many of its bills are not fully paid. */
    bill_month: string | null;
    bills_not_fully_paid: number;
}
interface Income {
    months: { month: string; label: string; billed_cents: number; collected_cents: number }[];
    by_method: { method: string; count: number; cents: number }[];
    by_property: { id: number; name: string; billed_cents: number; collected_cents: number }[];
    by_category: { category: string; cents: number }[];
    payments_count: number;
}
type Bucket = '1-30' | '31-60' | '61-90' | '90+';
interface Late {
    late_accounts: number;
    late_bills: number;
    overdue_cents: number;
    due_soon_count: number;
    due_soon_cents: number;
    paid_late_this_month: number;
    buckets: Record<Bucket, { accounts: number; cents: number }>;
    rows: {
        account_id: number;
        account_no: string | null;
        tenant: string;
        property: string;
        oldest_due: string;
        days_late: number;
        late_bills: number;
        overdue_cents: number;
        open_cents: number;
    }[];
}
interface Occupancy {
    properties: { id: number; name: string; rooms: number; capacity: number; occupied: number; vacant: number }[];
    move_ins: { id: number; tenant: string | null; unit: string | null; date: string }[];
    move_outs: { id: number; tenant: string | null; unit: string | null; date: string }[];
}
interface Props {
    metrics: Metrics;
    due: { account_id: number; account_no: string; tenant: string; property: string; due_date: string; open_cents: number }[];
    recentActivity: { id: number; action: string; user: string; at: string | null }[];
    income?: Income;
    late?: Late;
    occupancy?: Occupancy;
    bills?: BillPayments;
}

const TABS = ['overview', 'bills', 'income', 'late', 'occupancy'] as const;
type Tab = (typeof TABS)[number];
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
const percent = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);
const categoryLabels: Record<string, string> = { rent: 'Rent', utility: 'Utilities', recurring: 'Monthly add-ons', one_time: 'One-time charges' };
const bucketTone: Record<Bucket, 'warning' | 'danger'> = { '1-30': 'warning', '31-60': 'warning', '61-90': 'danger', '90+': 'danger' };
const bucketOf = (days: number): Bucket => (days <= 30 ? '1-30' : days <= 60 ? '31-60' : days <= 90 ? '61-90' : '90+');

function Panel({
    title,
    description,
    action,
    children,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('surface p-5', className)}>
            <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && <p className="text-muted-foreground text-sm">{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Meter({ value, tone = 'bg-primary', label }: { value: number; tone?: string; label: string }) {
    return (
        <div
            className="bg-muted h-2 overflow-hidden rounded-full"
            role="progressbar"
            aria-valuenow={value}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={label}
        >
            <div className={cn('h-full rounded-full transition-[width] duration-500', tone)} style={{ width: `${Math.min(100, value)}%` }} />
        </div>
    );
}

/** The owner's monthly routine as four numbered steps, each a link to the screen that does it. */
function MonthlyRoutine({ metrics }: { metrics: Metrics }) {
    const now = new Date();
    const month = now.toLocaleDateString('en-PH', { month: 'long' });
    const billing = route('billing.index', { year: now.getFullYear(), month: now.getMonth() + 1 });
    const steps: { title: string; text: string; href: string; icon: typeof Zap; status?: ReactNode }[] = [
        {
            title: 'Enter electricity',
            text: "On Electricity, type each room's electric bill. It is split equally between the room's boarders.",
            href: route('utilities.index'),
            icon: Zap,
            status: metrics.pending_utilities > 0 ? <Badge variant="warning">{metrics.pending_utilities} readings not billed yet</Badge> : null,
        },
        {
            title: 'Generate bills',
            text: `Check the ${month} preview, then press Generate. Nothing is saved before that.`,
            href: billing,
            icon: FileText,
            status:
                metrics.billed_cents > 0 ? (
                    <Badge variant="success">{formatCents(metrics.billed_cents)} billed</Badge>
                ) : (
                    <Badge variant="muted">Not billed yet</Badge>
                ),
        },
        {
            title: 'Print or email statements',
            text: 'Print every statement in one PDF, or email them to all boarders at once.',
            href: billing,
            icon: Receipt,
        },
        {
            title: 'Record payments',
            text: 'When a boarder pays, record it so their balance and receipt update.',
            href: route('collections.index'),
            icon: HandCoins,
            status:
                metrics.late_accounts > 0 ? (
                    <Badge variant="danger">{metrics.late_accounts} late</Badge>
                ) : (
                    <Badge variant="success">Nobody late</Badge>
                ),
        },
    ];
    return (
        <Panel
            title={`${month} checklist`}
            description="Do these in order each month. Click a step to open its screen."
            action={
                <Button asChild variant="ghost" size="sm">
                    <Link href={route('guide')}>
                        Full flowchart <ArrowRight />
                    </Link>
                </Button>
            }
        >
            <ol className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {steps.map((step, i) => (
                    <li key={step.title}>
                        <Link
                            href={step.href}
                            className="hover:border-primary/40 hover:bg-accent/40 focus-visible:ring-ring flex h-full gap-3 rounded-xl border p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span className="bg-primary text-primary-foreground grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold">
                                {i + 1}
                            </span>
                            <span className="min-w-0 space-y-1">
                                <span className="flex items-center gap-1.5 font-medium">
                                    <step.icon className="text-primary size-4" aria-hidden /> {step.title}
                                </span>
                                <span className="text-muted-foreground block text-xs leading-snug">{step.text}</span>
                                {step.status && <span className="block pt-0.5">{step.status}</span>}
                            </span>
                        </Link>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

function TabLoading() {
    return (
        <div className="space-y-4" aria-busy="true" aria-label="Loading">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-28 rounded-xl" />
                ))}
            </div>
            <Skeleton className="h-72 rounded-xl" />
        </div>
    );
}

export default function Dashboard({ metrics, due, recentActivity, bills, income, late, occupancy }: Props) {
    const { auth } = usePage<SharedData>().props;
    const isOwner = (auth.user as { role?: string }).role === 'owner';
    const [tab, setTab] = useState<Tab>(() => {
        const requested = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;
        return TABS.includes(requested as Tab) ? (requested as Tab) : 'overview';
    });
    const loaded: Record<Tab, boolean> = { overview: true, bills: !!bills, income: !!income, late: !!late, occupancy: !!occupancy };

    // Load a tab's data the first time it is opened (and on a deep link like ?tab=late).
    const needsLoad = !loaded[tab];
    useEffect(() => {
        if (!needsLoad) return;
        // Deferred one tick: Inertia's router.init runs in the parent App's effect, which fires after this child effect.
        const id = setTimeout(() => router.reload({ only: [tab] }), 0);
        return () => clearTimeout(id);
    }, [tab, needsLoad]);
    const change = (next: string) => {
        setTab(next as Tab);
        const url = new URL(window.location.href);
        if (next === 'overview') url.searchParams.delete('tab');
        else url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url.toString());
    };

    const collectionRate = percent(metrics.collected_cents, metrics.billed_cents);
    const occupancyRate = percent(metrics.occupied, metrics.capacity);
    const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={LayoutGrid}
                    title={`Hello, ${auth.user.name.split(' ')[0]}`}
                    description={`${today} · live figures from billing, collections and occupancy.`}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={route('tenants.index', { create: 1 })}>
                                    <Plus /> Add tenant
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={route('collections.index')}>
                                    <HandCoins /> Record payment
                                </Link>
                            </Button>
                            {isOwner && (
                                <Button asChild>
                                    <Link href={route('billing.index')}>
                                        <FileText /> Generate monthly bills
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <Tabs value={tab} onValueChange={change}>
                    <TabsList aria-label="Dashboard sections">
                        <TabsTrigger value="overview">
                            <LayoutGrid /> Overview
                        </TabsTrigger>
                        <TabsTrigger value="bills">
                            <ReceiptText />{' '}
                            {metrics.bill_month
                                ? `${new Date(`${metrics.bill_month}-01T12:00:00`).toLocaleDateString('en-PH', { month: 'long' })} bills`
                                : "This month's bills"}
                            {metrics.bills_not_fully_paid > 0 && (
                                <span
                                    className="bg-destructive text-destructive-foreground rounded-full px-1.5 py-px font-mono text-[11px] leading-4 font-semibold"
                                    title="Bills not fully paid"
                                >
                                    {metrics.bills_not_fully_paid}
                                </span>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="income">
                            <TrendingUp /> Income
                        </TabsTrigger>
                        <TabsTrigger value="late">
                            <AlarmClock /> Late dues
                            {metrics.late_accounts > 0 && (
                                <span className="bg-destructive text-destructive-foreground rounded-full px-1.5 py-px font-mono text-[11px] leading-4 font-semibold">
                                    {metrics.late_accounts}
                                </span>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="occupancy">
                            <BedDouble /> Occupancy
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="space-y-6">
                        {isOwner && <MonthlyRoutine metrics={metrics} />}
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                label="Collected this month"
                                value={formatCents(metrics.collected_cents)}
                                icon={Wallet}
                                tone="success"
                                href={route('collections.index')}
                                hint={`${collectionRate}% of ${formatCents(metrics.billed_cents)} billed`}
                            />
                            <StatCard
                                label="Overdue"
                                value={formatCents(metrics.overdue_cents)}
                                icon={CircleAlert}
                                tone="danger"
                                href={route('dashboard', { tab: 'late' })}
                                hint={`${metrics.late_accounts} late ${metrics.late_accounts === 1 ? 'account' : 'accounts'}`}
                            />
                            <StatCard
                                label="Due in 7 days"
                                value={formatCents(metrics.coming_due_cents)}
                                icon={CalendarClock}
                                tone="warning"
                                href={route('calendar.index')}
                                hint={`Open charges ${formatCents(metrics.still_due_cents)}`}
                            />
                            <StatCard
                                label="Occupancy"
                                value={`${occupancyRate}%`}
                                icon={BedDouble}
                                tone="info"
                                href={route('dashboard', { tab: 'occupancy' })}
                                hint={`${metrics.occupied} of ${metrics.capacity} places · ${metrics.vacant} vacant`}
                            />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                label="Billed this month"
                                value={formatCents(metrics.billed_cents)}
                                icon={Receipt}
                                tone="brand"
                                href={route('billing.index')}
                            />
                            <StatCard
                                label="Properties"
                                value={metrics.properties}
                                icon={Building2}
                                tone="brand"
                                hint={`${metrics.rooms} rooms`}
                                href={route('properties.index')}
                            />
                            <StatCard label="Vacant places" value={metrics.vacant} icon={BedDouble} href={route('dashboard', { tab: 'occupancy' })} />
                            <StatCard
                                label="Utility bills to bill"
                                value={metrics.pending_utilities}
                                icon={Zap}
                                tone={metrics.pending_utilities > 0 ? 'warning' : 'default'}
                                href={route('utilities.index')}
                                hint={metrics.pending_utilities > 0 ? 'Draft readings this month' : 'All caught up'}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-5">
                            <Panel
                                className="lg:col-span-3"
                                title="Needs attention"
                                description="Accounts with the oldest unpaid charges first."
                                action={
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={route('collections.index')}>
                                            All collections <ArrowRight />
                                        </Link>
                                    </Button>
                                }
                            >
                                {due.length === 0 ? (
                                    <p className="text-muted-foreground py-6 text-center text-sm">No open charges. Everyone is paid up.</p>
                                ) : (
                                    <ul className="-mx-2 divide-y">
                                        {due.map((item) => {
                                            const overdue = item.due_date < new Date().toISOString().slice(0, 10);
                                            return (
                                                <li key={item.account_id}>
                                                    <Link
                                                        href={route('accounts.statement', item.account_id)}
                                                        className="hover:bg-muted/60 flex items-center justify-between gap-3 rounded-lg px-2 py-3 text-sm transition-colors"
                                                    >
                                                        <span className="min-w-0">
                                                            <span className="block truncate font-medium">{item.tenant}</span>
                                                            <span className="text-muted-foreground block truncate text-xs">
                                                                {item.property} · {item.account_no}
                                                            </span>
                                                        </span>
                                                        <span className="flex shrink-0 items-center gap-3">
                                                            <Badge variant={overdue ? 'danger' : 'warning'}>
                                                                <Clock className="size-3" aria-hidden />
                                                                {overdue ? 'Overdue' : 'Due'} {formatDate(item.due_date)}
                                                            </Badge>
                                                            <span className="font-mono font-semibold tabular-nums">
                                                                {formatCents(item.open_cents)}
                                                            </span>
                                                        </span>
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </Panel>
                            <Panel className="lg:col-span-2" title="Recent activity">
                                {recentActivity.length === 0 ? (
                                    <p className="text-muted-foreground py-6 text-center text-sm">No activity yet.</p>
                                ) : (
                                    <ol className="relative space-y-4 border-l pl-4">
                                        {recentActivity.map((item) => (
                                            <li key={item.id} className="relative text-sm">
                                                <span
                                                    className="bg-primary ring-card absolute top-1.5 -left-[21px] size-2 rounded-full ring-4"
                                                    aria-hidden
                                                />
                                                <p>
                                                    <span className="font-medium">{item.user}</span>{' '}
                                                    <span className="text-muted-foreground">
                                                        {item.action.replaceAll('.', ' ').replaceAll('_', ' ')}
                                                    </span>
                                                </p>
                                                <p className="text-muted-foreground font-mono text-xs">{item.at?.slice(0, 16)}</p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </Panel>
                        </div>
                    </TabsContent>

                    <TabsContent value="bills">{bills ? <DashboardBillsTab bills={bills} /> : <TabLoading />}</TabsContent>
                    <TabsContent value="income">{income ? <IncomeTab income={income} /> : <TabLoading />}</TabsContent>
                    <TabsContent value="late">{late ? <LateTab late={late} /> : <TabLoading />}</TabsContent>
                    <TabsContent value="occupancy">{occupancy ? <OccupancyTab occupancy={occupancy} /> : <TabLoading />}</TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}

function IncomeTab({ income }: { income: Income }) {
    const current = income.months[income.months.length - 1];
    const previous = income.months[income.months.length - 2];
    const change =
        previous && previous.collected_cents > 0
            ? Math.round(((current.collected_cents - previous.collected_cents) / previous.collected_cents) * 100)
            : null;
    const year = income.months.reduce((sum, m) => ({ billed: sum.billed + m.billed_cents, collected: sum.collected + m.collected_cents }), {
        billed: 0,
        collected: 0,
    });
    const categoryTotal = income.by_category.reduce((sum, c) => sum + Math.max(0, c.cents), 0);
    const methodTotal = income.by_method.reduce((sum, m) => sum + m.cents, 0);

    return (
        <div className="space-y-6">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Collected this month"
                    value={formatCents(current.collected_cents)}
                    icon={Wallet}
                    tone="success"
                    hint={
                        change === null
                            ? `${income.payments_count} payments`
                            : `${change >= 0 ? '+' : ''}${change}% vs last month · ${income.payments_count} payments`
                    }
                />
                <StatCard label="Billed this month" value={formatCents(current.billed_cents)} icon={Receipt} tone="brand" />
                <StatCard
                    label="Collection rate"
                    value={`${percent(current.collected_cents, current.billed_cents)}%`}
                    icon={TrendingUp}
                    tone="info"
                    hint="Collected ÷ billed, this month"
                />
                <StatCard
                    label="Collected, last 12 months"
                    value={formatCents(year.collected)}
                    icon={HandCoins}
                    hint={`${formatCents(year.billed)} billed`}
                />
            </div>

            <Panel title="Billed vs collected" description="Last 12 months. Billed = bills issued; collected = approved payments received.">
                <GroupedBarChart
                    title="Billed vs collected, last 12 months"
                    data={income.months}
                    series={[
                        { key: 'billed_cents', label: 'Billed', swatch: 'bg-series-1' },
                        { key: 'collected_cents', label: 'Collected', swatch: 'bg-series-2' },
                    ]}
                    footer={(row) => `Collection rate ${percent(Number(row.collected_cents), Number(row.billed_cents))}%`}
                />
            </Panel>

            <div className="grid gap-4 lg:grid-cols-3">
                <Panel title="Billed by charge type" description="This month's bills.">
                    {income.by_category.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No bills this month yet.</p>
                    ) : (
                        <ul className="space-y-3">
                            {income.by_category.map((c) => (
                                <li key={c.category} className="space-y-1.5">
                                    <div className="flex justify-between text-sm">
                                        <span>{categoryLabels[c.category] ?? c.category}</span>
                                        <span className="font-mono tabular-nums">{formatCents(c.cents)}</span>
                                    </div>
                                    <Meter
                                        value={percent(Math.max(0, c.cents), categoryTotal)}
                                        tone="bg-series-1"
                                        label={`${categoryLabels[c.category] ?? c.category} share`}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel title="How tenants paid" description="Approved payments this month.">
                    {income.by_method.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No payments this month yet.</p>
                    ) : (
                        <ul className="space-y-3">
                            {income.by_method.map((m) => (
                                <li key={m.method} className="space-y-1.5">
                                    <div className="flex justify-between text-sm">
                                        <span>
                                            {methodLabels[m.method] ?? m.method} <span className="text-muted-foreground">· {m.count}</span>
                                        </span>
                                        <span className="font-mono tabular-nums">{formatCents(m.cents)}</span>
                                    </div>
                                    <Meter
                                        value={percent(m.cents, methodTotal)}
                                        tone="bg-series-2"
                                        label={`${methodLabels[m.method] ?? m.method} share`}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel title="By property" description="This month.">
                    {income.by_property.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing billed or collected yet.</p>
                    ) : (
                        <ul className="divide-y">
                            {income.by_property.map((p) => (
                                <li key={p.id} className="py-2.5 text-sm first:pt-0">
                                    <p className="font-medium">{p.name}</p>
                                    <p className="text-muted-foreground flex justify-between font-mono text-xs tabular-nums">
                                        <span>Billed {formatCents(p.billed_cents)}</span>
                                        <span>Collected {formatCents(p.collected_cents)}</span>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </div>
    );
}

function LateTab({ late }: { late: Late }) {
    const buckets = Object.entries(late.buckets) as [Bucket, { accounts: number; cents: number }][];
    const maxAccounts = Math.max(1, ...buckets.map(([, b]) => b.accounts));

    return (
        <div className="space-y-6">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard label="Late accounts" value={late.late_accounts} icon={CircleAlert} tone="danger" hint="Tenants with a bill past due" />
                <StatCard label="Late bills" value={late.late_bills} icon={Receipt} tone="danger" hint="Unpaid charges past due" />
                <StatCard label="Overdue amount" value={formatCents(late.overdue_cents)} icon={Wallet} tone="danger" />
                <StatCard
                    label="Due in 7 days"
                    value={late.due_soon_count}
                    icon={CalendarClock}
                    tone="warning"
                    hint={formatCents(late.due_soon_cents)}
                />
                <StatCard
                    label="Paid late this month"
                    value={late.paid_late_this_month}
                    icon={Clock}
                    tone="warning"
                    hint="Payments made after the due date"
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Panel title="How late" description="Late accounts by their oldest unpaid due date.">
                    <ul className="space-y-4">
                        {buckets.map(([bucket, b]) => (
                            <li key={bucket} className="space-y-1.5">
                                <div className="flex items-center justify-between gap-2 text-sm">
                                    <Badge variant={bucketTone[bucket]}>{bucket} days</Badge>
                                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                        <span className="text-foreground font-semibold">{b.accounts}</span> · {formatCents(b.cents)}
                                    </span>
                                </div>
                                <Meter
                                    value={percent(b.accounts, maxAccounts)}
                                    tone={bucketTone[bucket] === 'danger' ? 'bg-destructive' : 'bg-warning'}
                                    label={`${bucket} days late accounts`}
                                />
                            </li>
                        ))}
                    </ul>
                </Panel>

                <Panel
                    className="lg:col-span-2"
                    title="Late tenants"
                    description="Most days late first."
                    action={
                        <Button asChild variant="ghost" size="sm">
                            <Link href={route('reports.index')}>
                                Aging report <ArrowRight />
                            </Link>
                        </Button>
                    }
                >
                    {late.rows.length === 0 ? (
                        <EmptyState icon={AlarmClock} title="No late dues" description="Every bill that is due has been paid." />
                    ) : (
                        <div className="-mx-5 overflow-x-auto">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Late tenants</caption>
                                <thead className="text-left">
                                    <tr className="border-b">
                                        <th className="px-5 py-2">Tenant</th>
                                        <th className="px-3 py-2">Oldest due</th>
                                        <th className="px-3 py-2">Late</th>
                                        <th className="px-3 py-2 text-right">Bills</th>
                                        <th className="px-5 py-2 text-right">Overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {late.rows.map((row) => (
                                        <tr key={row.account_id} className="border-b last:border-0">
                                            <td className="px-5 py-2.5">
                                                <Link
                                                    href={route('accounts.statement', row.account_id)}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {row.tenant}
                                                </Link>
                                                <span className="text-muted-foreground block text-xs">
                                                    {row.property}
                                                    {row.account_no && ` · ${row.account_no}`}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap">{formatDate(row.oldest_due)}</td>
                                            <td className="px-3 py-2.5">
                                                <Badge variant={bucketTone[bucketOf(row.days_late)]}>{row.days_late} days</Badge>
                                            </td>
                                            <td className="px-3 py-2.5 text-right font-mono">{row.late_bills}</td>
                                            <td className="px-5 py-2.5 text-right font-mono font-semibold whitespace-nowrap">
                                                {formatCents(row.overdue_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </div>
        </div>
    );
}

function OccupancyTab({ occupancy }: { occupancy: Occupancy }) {
    const moves = (title: string, icon: typeof LogIn, rows: Occupancy['move_ins'], empty: string) => {
        const Icon = icon;
        return (
            <Panel title={title} description="Next 30 days.">
                {rows.length === 0 ? (
                    <p className="text-muted-foreground text-sm">{empty}</p>
                ) : (
                    <ul className="divide-y">
                        {rows.map((r) => (
                            <li key={r.id} className="flex items-center justify-between gap-3 py-2.5 text-sm first:pt-0">
                                <span className="flex min-w-0 items-center gap-2">
                                    <Icon className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                    <span className="truncate">
                                        {r.tenant} <span className="text-muted-foreground">· {r.unit}</span>
                                    </span>
                                </span>
                                <span className="text-muted-foreground shrink-0 font-mono text-xs">{formatDate(r.date)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        );
    };

    return (
        <div className="space-y-6">
            <Panel title="Places by property" description="Occupied today out of total places.">
                {occupancy.properties.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No properties yet.</p>
                ) : (
                    <ul className="grid gap-x-8 gap-y-5 md:grid-cols-2">
                        {occupancy.properties.map((p) => {
                            const rate = percent(p.occupied, p.capacity);
                            return (
                                <li key={p.id} className="space-y-2">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <Link
                                            href={route('properties.show', p.id)}
                                            className="truncate font-medium underline-offset-4 hover:underline"
                                        >
                                            {p.name}
                                        </Link>
                                        <span className="font-mono text-sm font-semibold tabular-nums">{rate}%</span>
                                    </div>
                                    <Meter value={rate} label={`${p.name} occupancy`} />
                                    <p className="text-muted-foreground font-mono text-xs tabular-nums">
                                        {p.occupied}/{p.capacity} occupied · {p.vacant} vacant · {p.rooms} rooms
                                    </p>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Panel>
            <div className="grid gap-4 md:grid-cols-2">
                {moves('Moving in', LogIn, occupancy.move_ins, 'No move-ins scheduled.')}
                {moves('Moving out', LogOut, occupancy.move_outs, 'No move-outs scheduled.')}
            </div>
        </div>
    );
}
