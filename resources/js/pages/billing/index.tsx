import { BillingTabs } from '@/components/billing-tabs';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/recurring-charge-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatCents } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Line {
    category: string;
    label: string;
    description: string | null;
    amount_cents: number;
}
interface Row {
    agreement_id: number;
    property_id: number;
    tenant: string;
    unit: string;
    due_date: string;
    lines: Line[];
    current_charges_cents: number;
    previous_balance_cents: number;
    estimated_total_cents: number;
    status: 'eligible' | 'needs_review' | 'already_billed' | 'skipped';
    flags: string[];
    invoice_number: string | null;
    invoice_id: number | null;
}
interface Props {
    year: number;
    month: number;
    filters: { property_id: number | null };
    properties: { id: number; name: string }[];
    rows: Row[];
    summary: { counts: Record<string, number>; total_new_charges_cents: number; total_previous_outstanding_cents: number; estimated_statement_total_cents: number };
    canGenerate: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];
const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const statusLabel: Record<Row['status'], string> = { eligible: 'Ready', needs_review: 'Needs attention', already_billed: 'Already billed', skipped: 'Skipped' };
const statusVariant: Record<Row['status'], 'default' | 'secondary' | 'outline' | 'destructive'> = {
    eligible: 'default',
    needs_review: 'destructive',
    already_billed: 'secondary',
    skipped: 'outline',
};

/** Plain-language version of a preview flag. */
function flagText(flag: string): string | null {
    const [key, type] = flag.split(':');
    switch (key) {
        case 'needs_utility_input':
            return `Waiting for the ${type} reading or bill`;
        case 'no_occupants':
            return `Nobody is recorded in the room, so ${type} cannot be split`;
        case 'no_rent_rate':
            return 'No rent amount is set for this rental';
        case 'no_email':
            return 'No email address: this bill needs to be printed';
        default:
            return null;
    }
}

function ExcludeDialog({ agreementId, type, tenant, monthKey, onClose }: { agreementId: number; type: string; tenant: string; monthKey: string; onClose: () => void }) {
    const form = useForm({ type, bill_month: monthKey, reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('agreements.utility-exclusions.store', agreementId), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Do not bill {type} this month</DialogTitle>
                        <DialogDescription>
                            {tenant} will not be charged for {type} in this month's bill. You can undo this later.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <Label htmlFor="ex-reason">Why?</Label>
                        <Input id="ex-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Meter was broken" />
                        <InputError message={errors.reason ?? errors.type} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Do not bill {type}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function BillingIndex({ year, month, filters, properties, rows, summary, canGenerate }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [confirming, setConfirming] = useState(false);
    const [excluding, setExcluding] = useState<{ row: Row; type: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const monthKey = `${year}-${String(month).padStart(2, '0')}`;
    const ready = rows.filter((r) => r.status === 'eligible');
    const readyTotal = ready.reduce((sum, r) => sum + r.current_charges_cents, 0);
    const counts = summary.counts;

    const go = (next: Partial<{ year: number; month: number; property_id: string }>) =>
        router.get(route('billing.index'), Object.fromEntries(Object.entries({ year, month, property_id: filters.property_id ?? '', ...next }).filter(([, v]) => v !== '')));

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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />
            <div className="space-y-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Bills for {months[month - 1]} {year}
                        </h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">This is a preview. Nothing is saved or emailed until you press Generate.</p>
                    </div>
                    {canGenerate && (
                        <Button size="lg" className="h-14 w-full text-lg sm:w-auto" disabled={ready.length === 0} onClick={() => setConfirming(true)}>
                            <FileText className="size-5" /> Generate Monthly Bills
                        </Button>
                    )}
                </div>

                <BillingTabs active="preview" />

                {flash?.success && (
                    <Alert>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Label htmlFor="b-month">Month</Label>
                        <select id="b-month" className={selectClass + ' w-40'} value={month} onChange={(e) => go({ month: Number(e.target.value) })}>
                            {months.map((m, i) => (
                                <option key={m} value={i + 1}>
                                    {m}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="b-year">Year</Label>
                        <select id="b-year" className={selectClass + ' w-28'} value={year} onChange={(e) => go({ year: Number(e.target.value) })}>
                            {[year - 1, year, year + 1].map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="b-property">Property</Label>
                        <select id="b-property" className={selectClass + ' w-52'} value={filters.property_id ?? ''} onChange={(e) => go({ property_id: e.target.value })}>
                            <option value="">All properties</option>
                            {properties.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    {[
                        ['Ready to bill', String(counts.eligible ?? 0)],
                        ['Need attention', String(counts.needs_review ?? 0)],
                        ['Already billed', String(counts.already_billed ?? 0)],
                        ['New charges', formatCents(summary.total_new_charges_cents)],
                        ['Owed from before', formatCents(summary.total_previous_outstanding_cents)],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-muted-foreground text-xs">{label}</dt>
                            <dd className="text-xl font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">Nobody has a bill in this month.</p>
                ) : (
                    <ul className="space-y-3">
                        {rows.map((r) => {
                            const notes = r.flags.map((f) => ({ flag: f, text: flagText(f) })).filter((n) => n.text !== null);
                            const missing = r.flags.filter((f) => f.startsWith('needs_utility_input:')).map((f) => f.split(':')[1]);
                            return (
                                <li key={r.agreement_id} className="rounded-lg border p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{r.tenant}</p>
                                            <p className="text-muted-foreground text-xs">
                                                Room {r.unit} - due {formatDate(r.due_date)}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <Badge variant={statusVariant[r.status]}>{statusLabel[r.status]}</Badge>
                                            <p className="mt-1 text-lg font-semibold">{formatCents(r.current_charges_cents)}</p>
                                        </div>
                                    </div>

                                    {r.previous_balance_cents !== 0 && (
                                        <p className="mt-2 text-sm">
                                            Also owes from before: <strong>{formatCents(r.previous_balance_cents)}</strong>. Total due about <strong>{formatCents(r.estimated_total_cents)}</strong>.
                                        </p>
                                    )}

                                    <details className="mt-2 text-sm">
                                        <summary className="text-primary cursor-pointer py-1">See what is on this bill</summary>
                                        <ul className="mt-1 divide-y rounded-md border">
                                            {r.lines.map((l, i) => (
                                                <li key={i} className="flex justify-between gap-3 p-2">
                                                    <span>
                                                        {l.label}
                                                        {l.description && <span className="text-muted-foreground block text-xs">{l.description}</span>}
                                                    </span>
                                                    <span className="whitespace-nowrap">{formatCents(l.amount_cents)}</span>
                                                </li>
                                            ))}
                                            {r.lines.length === 0 && <li className="text-muted-foreground p-2">No charges yet.</li>}
                                        </ul>
                                    </details>

                                    {notes.length > 0 && (
                                        <ul className="text-muted-foreground mt-2 space-y-1 text-sm">
                                            {notes.map((n) => (
                                                <li key={n.flag}>- {n.text}</li>
                                            ))}
                                        </ul>
                                    )}

                                    {r.status === 'already_billed' && r.invoice_id && (
                                        <Link href={route('invoices.show', r.invoice_id)} className="text-primary mt-2 inline-block text-sm underline">
                                            Open bill {r.invoice_number}
                                        </Link>
                                    )}

                                    {missing.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <Button asChild variant="outline" className="h-10">
                                                <Link href={`/utilities?month=${monthKey}`}>Enter the reading or bill</Link>
                                            </Button>
                                            {canGenerate &&
                                                missing.map((type) => (
                                                    <Button key={type} variant="outline" className="h-10" onClick={() => setExcluding({ row: r, type })}>
                                                        Exclude {type} this month
                                                    </Button>
                                                ))}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Generate {months[month - 1]} bills?</DialogTitle>
                        <DialogDescription>
                            This will create {ready.length} {ready.length === 1 ? 'bill' : 'bills'} totalling {formatCents(readyTotal)}. Nothing is emailed.
                        </DialogDescription>
                    </DialogHeader>
                    {(counts.needs_review ?? 0) > 0 && (
                        <p className="text-sm">
                            {counts.needs_review} {counts.needs_review === 1 ? 'tenant needs' : 'tenants need'} attention and will be left out for now.
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

            {excluding && (
                <ExcludeDialog agreementId={excluding.row.agreement_id} type={excluding.type} tenant={excluding.row.tenant} monthKey={monthKey} onClose={() => setExcluding(null)} />
            )}
        </AppLayout>
    );
}
