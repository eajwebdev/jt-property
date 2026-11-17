import { BillingTabs } from '@/components/billing-tabs';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FileDown,
    FileText,
    Lock,
    Mail,
    MailCheck,
    Printer,
    RotateCw,
    Search,
    Send,
    Settings2,
    Trash2,
    Zap,
} from 'lucide-react';
import { Fragment, useState } from 'react';

interface Line {
    category: string;
    label: string;
    description: string | null;
    amount_cents: number;
}
interface Row {
    agreement_id: number;
    account_id: number;
    property_id: number;
    tenant: string;
    unit: string;
    due_date: string;
    issue_date: string;
    period_start: string;
    period_end: string;
    lines: Line[];
    current_charges_cents: number;
    previous_balance_cents: number;
    payments_since_cents: number;
    estimated_total_cents: number;
    status: 'eligible' | 'needs_review' | 'already_billed' | 'skipped';
    flags: string[];
    invoice_number: string | null;
    invoice_id: number | null;
    email_status: 'queued' | 'sent' | 'skipped' | 'failed' | null;
    /** Electricity is required on every bill: on_bill, next_bill (entered after the bill was made) or missing. */
    electricity: 'on_bill' | 'next_bill' | 'missing';
    electricity_cents: number;
}
interface Props {
    year: number;
    month: number;
    filters: { property_id: number | null };
    properties: { id: number; name: string }[];
    rows: Row[];
    summary: {
        counts: Record<string, number>;
        total_new_charges_cents: number;
        total_previous_outstanding_cents: number;
        estimated_statement_total_cents: number;
    };
    canGenerate: boolean;
    /** Set when a later month is already billed: this month is closed (view, print, email only). */
    closedBy: string | null;
    canDeleteMonth: boolean;
    canEmail: boolean;
    operations: {
        queue_connection: string;
        pending_jobs: number | null;
        failed_jobs: number;
        queue_worker_running: boolean;
        queue_worker_last_seen: string | null;
        last_scheduler_run: string | null;
        mail_driver: string;
        pdf_ready: boolean;
        zip_ready: boolean;
    } | null;
}

type StatusFilter = 'all' | Row['status'];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];
const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const statusLabel: Record<Row['status'], string> = {
    eligible: 'Ready',
    needs_review: 'Needs attention',
    already_billed: 'Billed',
    skipped: 'Skipped',
};
const statusVariant: Record<Row['status'], 'success' | 'danger' | 'muted' | 'outline'> = {
    eligible: 'success',
    needs_review: 'danger',
    already_billed: 'muted',
    skipped: 'outline',
};

/** Plain-language version of a preview flag. */
function flagText(flag: string): string | null {
    const [key, type] = flag.split(':');
    switch (key) {
        case 'utility_pending':
            return `No ${type} bill entered yet: the bill shows "not yet entered", and the ${type} is added to a later bill once entered`;
        case 'no_occupants':
            return `Nobody is recorded in the room, so ${type} cannot be split`;
        case 'no_rent_rate':
            return 'No rent amount is set for this rental';
        case 'covered_by_opening_balance':
            return `Already counted in the opening balance as of ${type}. Bill from the next month.`;
        case 'no_email':
            return 'No email address: print this bill';
        default:
            return null;
    }
}

/** Type one tenant's electric bill for the month. Goes on this month's bill, or the next one if this bill is already made. */
function ElectricityForm({ row, year, month, onDone }: { row: Row; year: number; month: number; onDone: () => void }) {
    const usage = new Date(year, month - 2, 1).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
    const billed = row.status === 'already_billed';
    const form = useForm({
        rental_agreement_id: row.agreement_id,
        year,
        month,
        amount: row.electricity_cents > 0 ? (row.electricity_cents / 100).toFixed(2) : '',
    });
    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('billing.electricity'), { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Zap className="text-warning size-5" aria-hidden /> Electric bill
                </DialogTitle>
                <DialogDescription>
                    {row.tenant} · {row.unit}
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="eb-amount">Electricity for {usage} (pesos)</Label>
                <Input
                    id="eb-amount"
                    inputMode="decimal"
                    placeholder="e.g. 72.23"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    autoFocus
                    required
                />
                <InputError message={form.errors.amount} />
                <p className="text-muted-foreground text-xs">
                    {billed
                        ? 'This month is already billed, so it is added to the next bill as "(entered late)".'
                        : `Added to the ${new Date(year, month - 1, 1).toLocaleDateString('en-PH', { month: 'long' })} bill when you generate.`}
                </p>
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button disabled={form.processing}>Save electric bill</Button>
            </DialogFooter>
        </form>
    );
}

/** Deletes (voids) every bill of the latest billed month so it can be generated again. A reason is required and kept. */
function DeleteMonthForm({
    year,
    month,
    propertyId,
    bills,
    label,
    onDone,
}: {
    year: number;
    month: number;
    propertyId: number | null;
    bills: number;
    label: string;
    onDone: () => void;
}) {
    const form = useForm({ year, month, property_id: propertyId, reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('billing.void-month'), { preserveScroll: true, onSuccess: onDone });
            }}
        >
            <DialogHeader>
                <DialogTitle>Delete all {label} bills?</DialogTitle>
                <DialogDescription>
                    All {bills} bills of {label} are voided. Payments already applied to them go back to the tenants as credit, and electricity and
                    other charges go back to waiting, so you can generate {label} again. Voided bills stay in the records with your reason.
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="dm-reason">Reason</Label>
                <Input
                    id="dm-reason"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="e.g. Wrong electric bills, generating again"
                    autoFocus
                    required
                />
                <InputError message={errors.reason ?? errors.month ?? errors.invoice} />
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    Keep the bills
                </Button>
                <Button variant="destructive" disabled={form.processing || form.data.reason.trim().length < 5}>
                    <Trash2 /> Delete {bills} bills
                </Button>
            </DialogFooter>
        </form>
    );
}

/** One figure in the summary strip. */
function Figure({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return (
        <div className="min-w-0 px-4 py-2.5">
            <dt className="text-muted-foreground truncate text-xs">{label}</dt>
            <dd className={cn('font-mono text-base font-semibold tabular-nums', tone)}>{value}</dd>
        </div>
    );
}

export default function BillingIndex({ year, month, filters, properties, rows, canGenerate, closedBy, canDeleteMonth, canEmail, operations }: Props) {
    const [deleting, setDeleting] = useState(false);
    const { flash } = usePage<SharedData>().props;
    const [confirming, setConfirming] = useState(false);
    const [sendingAll, setSendingAll] = useState(false);
    const [busy, setBusy] = useState(false);
    const [showOps, setShowOps] = useState(false);
    const [status, setStatus] = useState<StatusFilter>('all');
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const [entering, setEntering] = useState<Row | null>(null);

    // Generate bills every active tenant of the month: no picking.
    const ready = rows.filter((r) => r.status === 'eligible');
    const readyTotal = ready.reduce((sum, r) => sum + r.current_charges_cents, 0);
    const counts: Record<StatusFilter, number> = {
        all: rows.length,
        eligible: rows.filter((r) => r.status === 'eligible').length,
        needs_review: rows.filter((r) => r.status === 'needs_review').length,
        already_billed: rows.filter((r) => r.status === 'already_billed').length,
        skipped: rows.filter((r) => r.status === 'skipped').length,
    };
    const needsReviewSelected = counts.needs_review;
    const noElectric = rows.filter((r) => r.electricity === 'missing').length;
    const readyPending = ready.filter((r) => r.electricity === 'missing').length;
    // Bills already made this month, and the ones "Send all" would email now (not yet sent, tenant reachable by email).
    const billed = rows.filter((r) => r.status === 'already_billed' && r.invoice_id !== null);
    // Consolidated Statement of Accounts "as of" this month's due date (e.g. October 05, 2026), never later than today.
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
    const monthDue = rows.reduce((latest, r) => (r.due_date > latest ? r.due_date : latest), '');
    const soaAsOf = monthDue && monthDue < today ? monthDue : today;
    const toSend = billed.filter((r) => r.email_status !== 'sent' && !r.flags.includes('no_email'));
    const sendPending = toSend.filter((r) => r.electricity === 'missing').length;
    const previousTotal = [...new Map(ready.map((row) => [row.account_id, row.previous_balance_cents])).values()].reduce(
        (sum, amount) => sum + amount,
        0,
    );
    const paymentsTotal = [...new Map(ready.map((row) => [row.account_id, row.payments_since_cents])).values()].reduce(
        (sum, amount) => sum + amount,
        0,
    );

    const visible = rows.filter(
        (r) =>
            (status === 'all' || r.status === status) &&
            (search === '' || `${r.tenant} ${r.unit}`.toLowerCase().includes(search.trim().toLowerCase())),
    );

    const go = (next: Partial<{ year: number; month: number; property_id: string }>) =>
        router.get(
            route('billing.index'),
            Object.fromEntries(Object.entries({ year, month, property_id: filters.property_id ?? '', ...next }).filter(([, v]) => v !== '')),
        );
    const shiftMonth = (delta: number) => {
        const d = new Date(year, month - 1 + delta, 1);
        go({ year: d.getFullYear(), month: d.getMonth() + 1 });
    };

    const generate = () => {
        setBusy(true);
        router.post(
            route('billing.generate'),
            { year, month, property_id: filters.property_id },
            {
                onFinish: () => {
                    setBusy(false);
                    setConfirming(false);
                },
            },
        );
    };

    const chips: [StatusFilter, string][] = [
        ['all', 'All'],
        ['eligible', 'Ready'],
        ['needs_review', 'Needs attention'],
        ['already_billed', 'Billed'],
    ];

    return (
        <AppLayout breadcrumbs={[...breadcrumbs, { title: `${months[month - 1]} ${year}`, href: route('billing.index', { year, month }) }]}>
            <Head title={`Billing · ${months[month - 1]} ${year}`} />
            <div className="mx-auto w-full max-w-7xl space-y-4 p-4 md:p-6">
                {/* Title, period and property, and the main action: one bar. */}
                <div className="flex flex-wrap items-center gap-3">
                    <Button asChild variant="outline" size="icon" className="size-10 shrink-0" title="All billing months">
                        <Link
                            href={route('billing.index', filters.property_id ? { property_id: filters.property_id } : {})}
                            aria-label="Back to all billing months"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <div className="mr-auto min-w-0">
                        <h1 className="text-xl leading-tight font-semibold tracking-tight">
                            {months[month - 1]} {year} bills
                        </h1>
                        <p className="text-muted-foreground text-xs">
                            {counts.already_billed > 0 ? `${counts.already_billed} billed` : 'Not billed yet'}
                            {ready.length > 0 ? ` · ${ready.length} ready to generate (preview, nothing saved until Generate)` : ''}
                        </p>
                    </div>
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" className="size-9" onClick={() => shiftMonth(-1)} aria-label="Previous month">
                            <ChevronLeft />
                        </Button>
                        <label htmlFor="b-month" className="sr-only">
                            Month
                        </label>
                        <SearchSelect
                            id="b-month"
                            className="w-36"
                            value={String(month)}
                            onChange={(v) => go({ month: Number(v) })}
                            options={months.map((m, i) => ({ value: String(i + 1), label: m }))}
                        />
                        <label htmlFor="b-year" className="sr-only">
                            Year
                        </label>
                        <SearchSelect
                            id="b-year"
                            className="w-24"
                            value={String(year)}
                            onChange={(v) => go({ year: Number(v) })}
                            options={[year - 1, year, year + 1].map((y) => ({ value: String(y), label: String(y) }))}
                        />
                        <Button variant="outline" size="icon" className="size-9" onClick={() => shiftMonth(1)} aria-label="Next month">
                            <ChevronRight />
                        </Button>
                    </div>
                    <label htmlFor="b-property" className="sr-only">
                        Property
                    </label>
                    <SearchSelect
                        id="b-property"
                        className="w-52"
                        value={String(filters.property_id ?? '')}
                        onChange={(v) => go({ property_id: v })}
                        options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                    />
                    {operations && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-9"
                            onClick={() => setShowOps(true)}
                            aria-label="Billing operations"
                            title="Billing operations"
                        >
                            <Settings2 />
                        </Button>
                    )}
                    {billed.length > 0 && (
                        <Button
                            asChild
                            variant="outline"
                            title={`Every boarder on one sheet (long bond paper, landscape), as of ${formatDate(soaAsOf)}`}
                        >
                            <a
                                href={route('reports.statement-of-accounts', { property_id: filters.property_id ?? undefined, as_of: soaAsOf })}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <FileDown /> Statement of Accounts
                            </a>
                        </Button>
                    )}
                    {billed.length > 0 && (
                        <Button
                            asChild
                            variant="outline"
                            title={`All ${billed.length} billing statements of ${months[month - 1]} in one PDF, one per page`}
                        >
                            <a
                                href={route('billing.month-statements', { year, month, property_id: filters.property_id ?? undefined })}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Printer /> All statements ({billed.length})
                            </a>
                        </Button>
                    )}
                    {canDeleteMonth && (
                        <Button
                            variant="outline"
                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => setDeleting(true)}
                        >
                            <Trash2 /> Delete month
                        </Button>
                    )}
                    {canEmail && billed.length > 0 && (
                        <Button variant="outline" onClick={() => setSendingAll(true)} title="Email every generated statement of this month">
                            <Send /> Send all{toSend.length > 0 ? ` (${toSend.length})` : ''}
                        </Button>
                    )}
                    {canGenerate && (
                        <Button disabled={ready.length === 0} onClick={() => setConfirming(true)}>
                            <FileText /> Generate {ready.length > 0 ? `${ready.length} bills` : 'bills'}
                        </Button>
                    )}
                </div>

                <BillingTabs active="preview" />

                {closedBy && (
                    <div className="bg-muted/60 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm">
                        <Lock className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                        <p>
                            <strong>This month is closed.</strong> {closedBy} is already billed and carries this month's unpaid balance. You can view,
                            print and email these statements, but nothing here can be changed.
                        </p>
                    </div>
                )}

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {/* Totals for the selected, ready accounts: one thin strip instead of seven cards. */}
                <dl className="surface grid grid-cols-2 divide-x divide-y sm:grid-cols-4 sm:divide-y-0 lg:grid-cols-8">
                    <Figure label="Ready to bill" value={String(ready.length)} tone="text-success" />
                    <Figure
                        label="Need attention"
                        value={String(needsReviewSelected)}
                        tone={needsReviewSelected > 0 ? 'text-destructive' : undefined}
                    />
                    <Figure label="Already billed" value={String(counts.already_billed)} />
                    <Figure label="New charges" value={formatCents(readyTotal)} />
                    <Figure label="Owed from before" value={formatCents(previousTotal)} />
                    <Figure label="Payments / credits" value={formatCents(paymentsTotal)} />
                    <Figure label="No electric bill yet" value={String(noElectric)} tone={noElectric > 0 ? 'text-warning' : 'text-success'} />
                    <Figure label="Estimated total due" value={formatCents(readyTotal + previousTotal + paymentsTotal)} />
                </dl>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-6 text-center text-sm">Nobody has a bill in this month.</p>
                ) : (
                    <section className="surface overflow-hidden">
                        {/* Find a tenant without scrolling: status chips + search. */}
                        <div className="flex flex-wrap items-center gap-2 border-b p-3">
                            <div className="bg-muted/60 inline-flex flex-wrap gap-1 rounded-lg p-1" role="tablist" aria-label="Filter by status">
                                {chips.map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        role="tab"
                                        aria-selected={status === key}
                                        onClick={() => setStatus(key)}
                                        className={cn(
                                            'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            status === key ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {label}
                                        <span className="font-mono tabular-nums opacity-70">{counts[key]}</span>
                                    </button>
                                ))}
                            </div>
                            <div className="relative ml-auto w-full sm:w-64">
                                <Search
                                    className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search tenant or room"
                                    aria-label="Search tenant or room"
                                    className="h-9 pl-8"
                                />
                            </div>
                            {noElectric > 0 && (
                                <span className="text-warning flex w-full items-center gap-1 text-xs font-medium sm:w-auto">
                                    <Zap className="size-3.5" aria-hidden /> {noElectric} without electric bill
                                </span>
                            )}
                        </div>

                        <div className="max-h-[calc(100dvh-20rem)] min-h-64 overflow-auto">
                            <table className="w-full min-w-[860px] text-sm">
                                <caption className="sr-only">
                                    Bills for {months[month - 1]} {year}
                                </caption>
                                <thead className="bg-muted/80 sticky top-0 z-10 text-left backdrop-blur">
                                    <tr className="border-b">
                                        <th className="px-3 py-2">Tenant / room</th>
                                        <th className="px-2 py-2">Due</th>
                                        <th className="px-2 py-2 text-right">Previous</th>
                                        <th className="px-2 py-2 text-right">New charges</th>
                                        <th className="px-2 py-2 text-right">Total due</th>
                                        <th className="px-2 py-2">Status</th>
                                        <th className="w-28 px-3 py-2">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((r) => {
                                        const notes = r.flags.map((f) => ({ flag: f, text: flagText(f) })).filter((n) => n.text !== null);
                                        const isOpen = open === r.agreement_id;
                                        return (
                                            <Fragment key={r.agreement_id}>
                                                <tr className={cn('border-b', isOpen && 'bg-muted/40')}>
                                                    <td className="max-w-72 px-3 py-2">
                                                        <button
                                                            type="button"
                                                            className="block w-full text-left"
                                                            onClick={() => setOpen(isOpen ? null : r.agreement_id)}
                                                            aria-expanded={isOpen}
                                                        >
                                                            <span className="block truncate font-medium">{r.tenant}</span>
                                                            <span className="text-muted-foreground block truncate text-xs">{r.unit}</span>
                                                        </button>
                                                    </td>
                                                    <td className="px-2 py-2 text-xs whitespace-nowrap">{formatDate(r.due_date)}</td>
                                                    <td className="px-2 py-2 text-right font-mono whitespace-nowrap">
                                                        {r.previous_balance_cents !== 0 ? formatCents(r.previous_balance_cents) : '—'}
                                                    </td>
                                                    <td className="px-2 py-2 text-right font-mono whitespace-nowrap">
                                                        {formatCents(r.current_charges_cents)}
                                                    </td>
                                                    <td className="px-2 py-2 text-right font-mono font-semibold whitespace-nowrap">
                                                        {formatCents(r.estimated_total_cents)}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            <Badge variant={statusVariant[r.status]}>
                                                                {r.status === 'needs_review' && <AlertTriangle className="size-3" aria-hidden />}
                                                                {statusLabel[r.status]}
                                                            </Badge>
                                                            {r.electricity === 'missing' && (
                                                                <Badge
                                                                    variant="warning"
                                                                    title="Electricity is required. Click ⚡ to enter this tenant's electric bill."
                                                                >
                                                                    <Zap className="size-3" aria-hidden /> No electric bill yet
                                                                </Badge>
                                                            )}
                                                            {r.electricity === 'next_bill' && (
                                                                <Badge
                                                                    variant="info"
                                                                    title="Entered after this bill was made: it is added to the next bill."
                                                                >
                                                                    <Zap className="size-3" aria-hidden /> {formatCents(r.electricity_cents)} on next
                                                                    bill
                                                                </Badge>
                                                            )}
                                                            {r.email_status === 'sent' && (
                                                                <Badge variant="info" title="Statement emailed">
                                                                    <MailCheck className="size-3" aria-hidden /> Emailed
                                                                </Badge>
                                                            )}
                                                            {r.email_status === 'queued' && (
                                                                <Badge variant="info" title="Statement email is on its way">
                                                                    <Mail className="size-3" aria-hidden /> Sending
                                                                </Badge>
                                                            )}
                                                            {r.email_status === 'failed' && (
                                                                <Badge variant="danger" title="The last email failed. Send again.">
                                                                    <Mail className="size-3" aria-hidden /> Email failed
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <div className="flex items-center justify-end gap-1">
                                                            {canGenerate && r.electricity !== 'on_bill' && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className={cn('size-8', r.electricity === 'missing' && 'text-warning')}
                                                                    title="Enter electric bill"
                                                                    aria-label={`Enter electric bill for ${r.tenant}`}
                                                                    onClick={() => setEntering(r)}
                                                                >
                                                                    <Zap />
                                                                </Button>
                                                            )}
                                                            {r.invoice_id && (
                                                                <Button
                                                                    asChild
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    title="Billing statement (PDF)"
                                                                >
                                                                    <a
                                                                        href={route('invoices.statement.pdf', r.invoice_id)}
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        aria-label={`Billing statement PDF for ${r.tenant}`}
                                                                    >
                                                                        <FileDown />
                                                                    </a>
                                                                </Button>
                                                            )}
                                                            {r.invoice_id && canEmail && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    title={
                                                                        r.email_status === 'sent' ? 'Send the statement again' : 'Email the statement'
                                                                    }
                                                                    aria-label={`${r.email_status === 'sent' ? 'Send again' : 'Email'} the statement to ${r.tenant}`}
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route('invoices.email', r.invoice_id!),
                                                                            r.email_status === 'sent' ? { resend: 1 } : {},
                                                                            { preserveScroll: true },
                                                                        )
                                                                    }
                                                                >
                                                                    {r.email_status === 'sent' ? <RotateCw /> : <Send />}
                                                                </Button>
                                                            )}
                                                            {r.invoice_id && (
                                                                <Button
                                                                    asChild
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    title={`Open bill ${r.invoice_number}`}
                                                                >
                                                                    <Link
                                                                        href={route('invoices.show', r.invoice_id)}
                                                                        aria-label={`Open bill ${r.invoice_number}`}
                                                                    >
                                                                        <ExternalLink />
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8"
                                                                onClick={() => setOpen(isOpen ? null : r.agreement_id)}
                                                                aria-label={isOpen ? 'Hide bill details' : 'Show bill details'}
                                                                aria-expanded={isOpen}
                                                            >
                                                                <ChevronDown className={cn('transition-transform', isOpen && 'rotate-180')} />
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                {isOpen && (
                                                    <tr className="bg-muted/30 border-b">
                                                        <td colSpan={7} className="px-3 py-3">
                                                            <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                                                                <div>
                                                                    <p className="text-muted-foreground mb-1 text-xs">
                                                                        {formatDate(r.period_start)} to {formatDate(r.period_end)} · issued{' '}
                                                                        {formatDate(r.issue_date)}
                                                                    </p>
                                                                    <ul className="bg-card divide-y rounded-lg border">
                                                                        {r.lines.map((l, i) => (
                                                                            <li key={i} className="flex justify-between gap-3 px-3 py-1.5">
                                                                                <span className="min-w-0">
                                                                                    {l.label}
                                                                                    {l.description && (
                                                                                        <span className="text-muted-foreground block truncate text-xs">
                                                                                            {l.description}
                                                                                        </span>
                                                                                    )}
                                                                                </span>
                                                                                <span className="font-mono whitespace-nowrap">
                                                                                    {formatCents(l.amount_cents)}
                                                                                </span>
                                                                            </li>
                                                                        ))}
                                                                        {r.lines.length === 0 && (
                                                                            <li className="text-muted-foreground px-3 py-1.5">No charges yet.</li>
                                                                        )}
                                                                    </ul>
                                                                </div>
                                                                <div className="space-y-2">
                                                                    <dl className="grid grid-cols-3 gap-2 text-xs">
                                                                        <div>
                                                                            <dt className="text-muted-foreground">Previous balance</dt>
                                                                            <dd className="font-mono">{formatCents(r.previous_balance_cents)}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt className="text-muted-foreground">Payments / credits</dt>
                                                                            <dd className="font-mono">{formatCents(r.payments_since_cents)}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt className="text-muted-foreground">Total due (about)</dt>
                                                                            <dd className="font-mono font-semibold">
                                                                                {formatCents(r.estimated_total_cents)}
                                                                            </dd>
                                                                        </div>
                                                                    </dl>
                                                                    {notes.length > 0 && (
                                                                        <ul className="space-y-1 text-xs">
                                                                            {notes.map((n) => (
                                                                                <li key={n.flag} className="text-muted-foreground flex gap-1.5">
                                                                                    <AlertTriangle
                                                                                        className="text-warning mt-0.5 size-3 shrink-0"
                                                                                        aria-hidden
                                                                                    />{' '}
                                                                                    {n.text}
                                                                                </li>
                                                                            ))}
                                                                        </ul>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                    {visible.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-muted-foreground px-3 py-8 text-center">
                                                No tenants match.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Generate {months[month - 1]} bills?</DialogTitle>
                        <DialogDescription>
                            This will create {ready.length} {ready.length === 1 ? 'bill' : 'bills'} totalling {formatCents(readyTotal)}. Nothing is
                            emailed.
                        </DialogDescription>
                    </DialogHeader>
                    {needsReviewSelected > 0 && (
                        <p className="text-sm">
                            {needsReviewSelected} {needsReviewSelected === 1 ? 'tenant needs' : 'tenants need'} attention and will be left out for
                            now.
                        </p>
                    )}
                    {readyPending > 0 && (
                        <p className="bg-warning/10 text-warning flex gap-2 rounded-lg p-3 text-sm">
                            <Zap className="mt-0.5 size-4 shrink-0" aria-hidden />
                            {readyPending} {readyPending === 1 ? 'bill has' : 'bills have'} no electric bill entered yet. They will show "not yet
                            entered"; the electricity is added to the next bill once you enter it.
                        </p>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button onClick={generate} disabled={busy}>
                            Yes, create the bills
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent className="sm:max-w-md">
                    {deleting && (
                        <DeleteMonthForm
                            year={year}
                            month={month}
                            propertyId={filters.property_id}
                            bills={billed.length}
                            label={`${months[month - 1]} ${year}`}
                            onDone={() => setDeleting(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={entering !== null} onOpenChange={(o) => !o && setEntering(null)}>
                <DialogContent className="sm:max-w-md">
                    {entering && <ElectricityForm row={entering} year={year} month={month} onDone={() => setEntering(null)} />}
                </DialogContent>
            </Dialog>

            <Dialog open={sendingAll} onOpenChange={setSendingAll}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Email all {months[month - 1]} statements?</DialogTitle>
                        <DialogDescription>
                            Each tenant gets their billing statement PDF by email. Bills already emailed are not sent twice (use Send again on a bill
                            for that).
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="space-y-2 text-sm">
                        <li className="flex justify-between gap-3 rounded-lg border px-3 py-2">
                            <span>Will be emailed now</span>
                            <span className="font-mono font-semibold">{toSend.length}</span>
                        </li>
                        <li className="flex justify-between gap-3 rounded-lg border px-3 py-2">
                            <span>Already emailed</span>
                            <span className="font-mono">{billed.filter((r) => r.email_status === 'sent').length}</span>
                        </li>
                        <li className="flex justify-between gap-3 rounded-lg border px-3 py-2">
                            <span>No email address / print only (skipped)</span>
                            <span className="font-mono">{billed.filter((r) => r.flags.includes('no_email')).length}</span>
                        </li>
                    </ul>
                    {sendPending > 0 && (
                        <p className="bg-warning/10 text-warning flex gap-2 rounded-lg p-3 text-sm">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                            {sendPending} of these {sendPending === 1 ? 'statement still shows' : 'statements still show'} electricity "not yet
                            entered". If you send now, the electricity will only appear on the next bill.
                        </p>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setSendingAll(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={busy || toSend.length === 0}
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    route('billing.email-month'),
                                    { year, month, property_id: filters.property_id },
                                    { preserveScroll: true, onFinish: () => (setBusy(false), setSendingAll(false)) },
                                );
                            }}
                        >
                            <Send /> Yes, email {toSend.length} {toSend.length === 1 ? 'statement' : 'statements'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {operations && (
                <Dialog open={showOps} onOpenChange={setShowOps}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Billing operations</DialogTitle>
                            <DialogDescription>Background jobs, email and PDF readiness.</DialogDescription>
                        </DialogHeader>
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            {[
                                [
                                    'Queue',
                                    `${operations.queue_connection}; ${operations.pending_jobs === null ? 'pending count unavailable' : `${operations.pending_jobs} pending`}`,
                                ],
                                [
                                    'Queue worker',
                                    `${operations.queue_worker_running ? 'Running' : 'Not seen recently'}${operations.queue_worker_last_seen ? ` (last seen ${operations.queue_worker_last_seen})` : ''}`,
                                ],
                                ['Failed jobs', String(operations.failed_jobs)],
                                ['Scheduler last ran', operations.last_scheduler_run ?? 'not recorded'],
                                ['Email transport', operations.mail_driver],
                                ['PDF engine', operations.pdf_ready ? 'Chrome ready' : 'Chrome path needs configuration'],
                                ['ZIP export', operations.zip_ready ? 'Ready' : 'PHP ZIP extension missing'],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-lg border px-3 py-2">
                                    <dt className="text-muted-foreground text-xs">{label}</dt>
                                    <dd>{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => router.post(route('billing.test-email'))}>
                                Send SMTP test to my email
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </AppLayout>
    );
}
