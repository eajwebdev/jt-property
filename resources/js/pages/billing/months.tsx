import { BillingTabs } from '@/components/billing-tabs';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarPlus, CheckCircle2, FileText, Lock, Mail } from 'lucide-react';

interface Month {
    key: string;
    year: number;
    month: number;
    bills: number;
    billed_cents: number;
    unpaid_cents: number;
    paid_bills: number;
    emailed_bills: number;
}
interface Props {
    months: Month[];
    filters: { property_id: number | null };
    properties: { id: number; name: string }[];
    next: { year: number; month: number };
    canGenerate: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];
const monthName = (year: number, month: number) => new Date(year, month - 1, 1).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });

/** Billing home: one card per billed month. Open a month to see (and send) its bills. */
export default function BillingMonths({ months, filters, properties, next, canGenerate }: Props) {
    const { flash } = usePage<SharedData>().props;
    const property = filters.property_id ?? undefined;
    const open = (year: number, month: number) => route('billing.index', { year, month, property_id: property });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />
            <div className="mx-auto w-full max-w-7xl space-y-5 p-4 md:p-6">
                <PageHeader
                    icon={FileText}
                    title="Billing"
                    description="Every month you have billed. Open a month to see its bills, print or email the statements."
                    actions={
                        <>
                            <SearchSelect
                                aria-label="Property"
                                className="w-52"
                                value={String(filters.property_id ?? '')}
                                onChange={(v) => router.get(route('billing.index'), v ? { property_id: v } : {}, { preserveState: true, replace: true })}
                                options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                            />
                            {canGenerate && (
                                <Button asChild>
                                    <Link href={open(next.year, next.month)}>
                                        <CalendarPlus /> Start {monthName(next.year, next.month)}
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
                <BillingTabs active="preview" />

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {months.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="No bills yet"
                        description="Start your first month: you will see every tenant's bill before anything is saved."
                        action={
                            canGenerate && (
                                <Button asChild>
                                    <Link href={open(next.year, next.month)}>
                                        <CalendarPlus /> Start {monthName(next.year, next.month)}
                                    </Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {months.map((m, i) => {
                            const paidPct = m.billed_cents > 0 ? Math.round(((m.billed_cents - Math.min(m.unpaid_cents, m.billed_cents)) / m.billed_cents) * 100) : 100;
                            const allPaid = m.unpaid_cents <= 0;
                            return (
                                <li key={m.key}>
                                    <Link
                                        href={open(m.year, m.month)}
                                        className="surface group hover:shadow-lift focus-visible:ring-ring flex h-full flex-col gap-3 p-4 transition-shadow focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="flex items-center gap-2 text-lg leading-tight font-semibold">
                                                    {monthName(m.year, m.month)}
                                                    {i === 0 ? (
                                                        <Badge variant="success">Latest</Badge>
                                                    ) : (
                                                        <Badge variant="muted" title="A later month is billed: view, print and email only">
                                                            <Lock className="size-3" aria-hidden /> Closed
                                                        </Badge>
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {m.bills} {m.bills === 1 ? 'bill' : 'bills'} · {m.paid_bills} paid
                                                </p>
                                            </div>
                                            <ArrowRight className="text-muted-foreground group-hover:text-primary size-4 transition-colors" aria-hidden />
                                        </div>
                                        <dl className="grid grid-cols-2 gap-2 text-sm">
                                            <div>
                                                <dt className="text-muted-foreground text-xs">Billed</dt>
                                                <dd className="font-mono font-semibold tabular-nums">{formatCents(m.billed_cents)}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground text-xs">Still unpaid</dt>
                                                <dd className={cn('font-mono font-semibold tabular-nums', allPaid ? 'text-success' : 'text-destructive')}>
                                                    {allPaid ? 'All paid' : formatCents(m.unpaid_cents)}
                                                </dd>
                                            </div>
                                        </dl>
                                        <div>
                                            <div className="bg-muted h-1.5 overflow-hidden rounded-full" role="progressbar" aria-valuenow={paidPct} aria-valuemin={0} aria-valuemax={100} aria-label="Collected">
                                                <div className="bg-success h-full rounded-full" style={{ width: `${paidPct}%` }} />
                                            </div>
                                            <p className="text-muted-foreground mt-1.5 flex justify-between text-xs">
                                                <span className="flex items-center gap-1">
                                                    <CheckCircle2 className="size-3" aria-hidden /> {paidPct}% collected
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Mail className="size-3" aria-hidden /> {m.emailed_bills}/{m.bills} emailed
                                                </span>
                                            </p>
                                        </div>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
