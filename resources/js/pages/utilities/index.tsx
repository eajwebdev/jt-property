import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import AppLayout from '@/layouts/app-layout';
import { formatCents, parsePesos } from '@/lib/money';
import { formatFixed, meteredAmountCents, parseReading } from '@/lib/quantity';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Zap } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type UtilityType = 'electricity' | 'water';
type Mode = 'inclusive' | 'fixed' | 'metered' | 'shared';

interface Bill {
    id: number;
    status: 'draft' | 'billed';
    amount_cents: number;
    amount: string;
    previous_e3: number | null;
    current_e3: number | null;
    reading_date: string | null;
    rate_e4: number | null;
    base_charge_cents: number;
    has_evidence: boolean;
}
interface Share {
    rental_agreement_id: number;
    percent_bp: number | null;
    manual_cents: number | null;
}
interface Utility {
    mode: Mode;
    fixed_cents: number | null;
    rate: string | null;
    rate_e4: number | null;
    base_charge_cents: number | null;
    split_method: 'equal' | 'days' | 'percent' | 'manual' | null;
    shares: Share[];
    previous_e3: number | null;
    bill: Bill | null;
}
interface UnitRow {
    id: number;
    code: string;
    property: string;
    per_bed: boolean;
    can_enter: boolean;
    agreements: { id: number; label: string }[];
    utilities: Record<UtilityType, Utility>;
}
interface Props {
    month: string;
    canConfigure: boolean;
    units: UnitRow[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Charges & Utilities', href: '/charges' },
    { title: 'Electricity', href: '/utilities' },
];
const names: Record<UtilityType, string> = { electricity: 'Electricity', water: 'Water' };
const measure: Record<UtilityType, string> = { electricity: 'kWh', water: 'm³' };
const modeLabels: Record<Mode, string> = {
    inclusive: 'Included in rent',
    fixed: 'Fixed amount',
    metered: 'Metered (meter reading)',
    shared: 'Shared master bill',
};
const splitLabels: Record<string, string> = {
    equal: 'Equally between tenants',
    days: 'By days each tenant stayed',
    percent: 'By percent',
    manual: 'Fixed amount each',
};
const bigInput = 'h-12 text-lg';

type Err = Record<string, string | undefined>;

/** Enter this month's meter reading (or master bill amount). The amount is shown before saving. */
function ReadingDialog({ unit, type, month, onClose }: { unit: UnitRow; type: UtilityType; month: string; onClose: () => void }) {
    const u = unit.utilities[type];
    const metered = u.mode === 'metered';
    // Last month's closing reading; only the very first reading of a meter is typed in.
    const knownPrevious = u.previous_e3;
    const form = useForm<{
        unit_id: number;
        type: UtilityType;
        bill_month: string;
        previous_reading: string;
        current_reading: string;
        amount: string;
        reading_date: string;
        evidence: File | null;
    }>({
        unit_id: unit.id,
        type,
        bill_month: month,
        previous_reading: u.bill?.previous_e3 != null ? formatFixed(u.bill.previous_e3, 3) : '',
        current_reading: u.bill?.current_e3 != null ? formatFixed(u.bill.current_e3, 3) : '',
        amount: !metered && u.bill ? (u.bill.amount_cents / 100).toFixed(2) : '',
        reading_date: u.bill?.reading_date ?? '',
        evidence: null,
    });
    const errors = form.errors as Err;
    const previous = knownPrevious != null ? BigInt(knownPrevious) : parseReading(form.data.previous_reading);
    const current = parseReading(form.data.current_reading);
    const rate = u.bill?.rate_e4 ?? u.rate_e4;
    const base = u.bill?.base_charge_cents ?? u.base_charge_cents ?? 0;
    const amountCents = parsePesos(form.data.amount);
    const lower = previous != null && current != null && current < previous;
    const preview =
        metered && previous != null && current != null && !lower && rate != null
            ? `${formatFixed(current - previous, 3)} ${measure[type]} × ₱${formatFixed(rate, 4)}${base ? ` + ${formatCents(base)} base` : ''} = ${formatCents(
                  meteredAmountCents(current - previous, BigInt(rate), base),
              )}`
            : null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('utility-bills.store'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            {names[type]} - Room {unit.code}
                        </DialogTitle>
                        <DialogDescription>
                            {metered ? 'Type the number on the meter today.' : 'Type the total on the master bill.'}
                        </DialogDescription>
                    </DialogHeader>
                    {metered ? (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="rd-previous">Previous reading ({measure[type]})</Label>
                                <Input
                                    id="rd-previous"
                                    className={bigInput}
                                    inputMode="decimal"
                                    value={knownPrevious != null ? formatFixed(knownPrevious, 3) : form.data.previous_reading}
                                    readOnly={knownPrevious != null}
                                    onChange={(e) => form.setData('previous_reading', e.target.value)}
                                    placeholder="Starting meter number"
                                />
                                <InputError message={errors.previous_reading} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="rd-current">Current reading ({measure[type]})</Label>
                                <Input
                                    id="rd-current"
                                    className={bigInput}
                                    inputMode="decimal"
                                    autoFocus
                                    value={form.data.current_reading}
                                    onChange={(e) => form.setData('current_reading', e.target.value)}
                                    placeholder="Like 1042.5"
                                />
                                {lower && <p className="text-destructive text-sm">This is lower than the previous reading.</p>}
                                <InputError message={errors.current_reading} />
                            </div>
                            <p className="bg-muted rounded-lg p-3 text-sm" aria-live="polite">
                                {preview ?? 'The amount shows here once both readings are typed.'}
                            </p>
                            <InputError message={errors.rate} />
                        </>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="rd-amount">Master bill amount (pesos)</Label>
                            <Input
                                id="rd-amount"
                                className={bigInput}
                                inputMode="decimal"
                                autoFocus
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                placeholder="Like 1,250.50"
                            />
                            <p className="text-muted-foreground text-sm">{amountCents ? formatCents(amountCents) : 'Like 1000 or 1,250.50'}</p>
                            <InputError message={errors.amount} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="rd-date">Reading date (optional)</Label>
                        <Input
                            id="rd-date"
                            type="date"
                            value={form.data.reading_date}
                            onChange={(e) => form.setData('reading_date', e.target.value)}
                        />
                        <InputError message={errors.reading_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="rd-evidence">Photo of the meter or bill (optional)</Label>
                        <Input
                            id="rd-evidence"
                            type="file"
                            accept="image/*,application/pdf"
                            onChange={(e) => form.setData('evidence', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={errors.evidence} />
                    </div>
                    <InputError message={errors.type ?? errors.bill_month} />
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            className="h-12"
                            disabled={form.processing || (metered ? previous == null || current == null || lower : !amountCents)}
                        >
                            {form.processing ? 'Saving...' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Owner: how this room pays for one utility. */
function ConfigDialog({ unit, type, onClose }: { unit: UnitRow; type: UtilityType; onClose: () => void }) {
    const u = unit.utilities[type];
    const initialShares: Record<number, string> = {};
    unit.agreements.forEach((a) => {
        const s = u.shares.find((x) => x.rental_agreement_id === a.id);
        initialShares[a.id] = s
            ? u.split_method === 'percent'
                ? ((s.percent_bp ?? 0) / 100).toString()
                : ((s.manual_cents ?? 0) / 100).toFixed(2)
            : '';
    });
    const form = useForm({
        mode: u.mode as Mode,
        fixed: u.fixed_cents ? (u.fixed_cents / 100).toFixed(2) : '',
        rate: u.rate ?? '',
        base_charge: u.base_charge_cents ? (u.base_charge_cents / 100).toFixed(2) : '',
        split_method: u.split_method ?? 'equal',
        shares: initialShares,
    });
    const errors = form.errors as Err;
    const needsSplit = unit.per_bed && (form.data.mode === 'metered' || form.data.mode === 'shared');
    const split = form.data.split_method;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({
            mode: d.mode,
            fixed: d.mode === 'fixed' ? d.fixed : undefined,
            rate: d.mode === 'metered' ? d.rate : undefined,
            base_charge: d.mode === 'metered' ? d.base_charge || '0' : undefined,
            split_method: needsSplit ? d.split_method : undefined,
            shares:
                needsSplit && (split === 'percent' || split === 'manual')
                    ? unit.agreements.map((a) =>
                          split === 'percent'
                              ? { agreement_id: a.id, percent_bp: Math.round(Number(d.shares[a.id] || 0) * 100) }
                              : { agreement_id: a.id, manual_cents: parsePesos(d.shares[a.id] || '0') ?? 0 },
                      )
                    : undefined,
        }));
        form.put(route('utilities.configure', { unit: unit.id, type }), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            {names[type]} setup - Room {unit.code}
                        </DialogTitle>
                        <DialogDescription>Applies to bills made from now on.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="cf-mode">How is it billed?</Label>
                        <SearchSelect
                            id="cf-mode"
                            value={form.data.mode}
                            onChange={(v) => form.setData('mode', v as Mode)}
                            options={(Object.keys(modeLabels) as Mode[]).map((m) => ({ value: m, label: modeLabels[m] }))}
                        />
                    </div>
                    {form.data.mode === 'fixed' && (
                        <div className="grid gap-2">
                            <Label htmlFor="cf-fixed">Fixed amount each month (pesos)</Label>
                            <Input
                                id="cf-fixed"
                                inputMode="decimal"
                                value={form.data.fixed}
                                onChange={(e) => form.setData('fixed', e.target.value)}
                            />
                            <InputError message={errors.fixed} />
                        </div>
                    )}
                    {form.data.mode === 'metered' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="cf-rate">Rate per {measure[type]} (pesos)</Label>
                                <Input
                                    id="cf-rate"
                                    inputMode="decimal"
                                    placeholder="Like 11.5432"
                                    value={form.data.rate}
                                    onChange={(e) => form.setData('rate', e.target.value)}
                                />
                                <InputError message={errors.rate} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cf-base">Base charge (optional)</Label>
                                <Input
                                    id="cf-base"
                                    inputMode="decimal"
                                    value={form.data.base_charge}
                                    onChange={(e) => form.setData('base_charge', e.target.value)}
                                />
                                <InputError message={errors.base_charge} />
                            </div>
                        </div>
                    )}
                    {needsSplit && (
                        <div className="grid gap-2">
                            <Label htmlFor="cf-split">How is it shared between tenants?</Label>
                            <SearchSelect
                                id="cf-split"
                                value={form.data.split_method}
                                onChange={(v) => form.setData('split_method', v as typeof split)}
                                options={Object.entries(splitLabels).map(([k, label]) => ({ value: k, label }))}
                            />
                            <InputError message={errors.split_method} />
                        </div>
                    )}
                    {needsSplit && (split === 'percent' || split === 'manual') && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                {split === 'percent' ? 'Percent for each tenant (must total 100)' : 'Pesos for each tenant (must equal the bill)'}
                            </legend>
                            {unit.agreements.map((a) => (
                                <div key={a.id} className="flex items-center gap-2">
                                    <Label htmlFor={`cf-share-${a.id}`} className="flex-1 text-sm font-normal">
                                        {a.label}
                                    </Label>
                                    <Input
                                        id={`cf-share-${a.id}`}
                                        className="w-28"
                                        inputMode="decimal"
                                        value={form.data.shares[a.id] ?? ''}
                                        onChange={(e) => form.setData('shares', { ...form.data.shares, [a.id]: e.target.value })}
                                    />
                                </div>
                            ))}
                            <InputError message={errors.shares} />
                        </fieldset>
                    )}
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>{form.processing ? 'Saving...' : 'Save'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function UtilityRow({
    unit,
    type,
    canConfigure,
    onEnter,
    onConfigure,
}: {
    unit: UnitRow;
    type: UtilityType;
    canConfigure: boolean;
    onEnter: () => void;
    onConfigure: () => void;
}) {
    const u = unit.utilities[type];
    const needsBill = u.mode === 'metered' || u.mode === 'shared';
    const detail = !needsBill
        ? u.mode === 'fixed' && u.fixed_cents
            ? `${formatCents(u.fixed_cents)} per tenant each month`
            : 'Nothing is billed'
        : u.bill
          ? `${u.bill.amount}${u.bill.current_e3 != null && u.bill.previous_e3 != null ? ` (${formatFixed(u.bill.current_e3 - u.bill.previous_e3, 3)} ${measure[type]})` : ''}`
          : 'Waiting for this month';

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
            <div>
                <p className="font-medium">
                    {names[type]} <span className="text-muted-foreground font-normal">- {modeLabels[u.mode]}</span>
                </p>
                <p className="text-muted-foreground">{detail}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {needsBill && (
                    <Badge variant={u.bill ? 'outline' : 'destructive'}>
                        {u.bill ? (u.bill.status === 'billed' ? 'Billed' : 'Entered') : 'Missing'}
                    </Badge>
                )}
                {needsBill && u.bill?.has_evidence && (
                    <Button asChild size="sm" variant="outline">
                        <a href={route('utility-bills.evidence', u.bill.id)}>Photo</a>
                    </Button>
                )}
                {needsBill && unit.can_enter && u.bill?.status !== 'billed' && (
                    <Button size="sm" onClick={onEnter}>
                        {u.bill ? 'Correct' : metered(u) ? 'Enter reading' : 'Enter bill'}
                    </Button>
                )}
                {canConfigure && (
                    <Button size="sm" variant="outline" onClick={onConfigure}>
                        Set up
                    </Button>
                )}
            </div>
        </li>
    );
}
const metered = (u: Utility) => u.mode === 'metered';

export default function Utilities({ month, canConfigure, units }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [entering, setEntering] = useState<{ unit: UnitRow; type: UtilityType } | null>(null);
    const [configuring, setConfiguring] = useState<{ unit: UnitRow; type: UtilityType } | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Electricity" />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Zap}
                    title="Electricity"
                    description="Enter each room's electric meter reading or bill. Not entered yet? The bill shows “not yet entered” and the electricity is added to the next bill. Water is free and never billed."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={route('charges.index')}>
                                <ArrowLeft /> Back to charges
                            </Link>
                        </Button>
                    }
                />

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <div className="grid max-w-xs gap-2">
                    <Label htmlFor="ut-month">Billing month</Label>
                    <Input
                        id="ut-month"
                        type="month"
                        className="h-12"
                        value={month}
                        onChange={(e) =>
                            e.target.value &&
                            router.get(route('utilities.index'), { month: e.target.value }, { preserveScroll: true, preserveState: true })
                        }
                    />
                </div>

                {units.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No rooms yet</p>
                ) : (
                    units.map((unit) => (
                        <section key={unit.id} aria-label={`Room ${unit.code}`} className="space-y-2">
                            <h2 className="font-medium">
                                Room {unit.code} <span className="text-muted-foreground text-sm font-normal">{unit.property}</span>
                            </h2>
                            <ul className="divide-y rounded-lg border">
                                {(['electricity'] as const).map((type) => (
                                    <UtilityRow
                                        key={type}
                                        unit={unit}
                                        type={type}
                                        canConfigure={canConfigure}
                                        onEnter={() => setEntering({ unit, type })}
                                        onConfigure={() => setConfiguring({ unit, type })}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
            {entering && <ReadingDialog unit={entering.unit} type={entering.type} month={month} onClose={() => setEntering(null)} />}
            {configuring && <ConfigDialog unit={configuring.unit} type={configuring.type} onClose={() => setConfiguring(null)} />}
        </AppLayout>
    );
}
