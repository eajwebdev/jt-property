import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { formatCents, parsePesos } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface RentalOptions {
    units: {
        id: number;
        code: string;
        rent_mode: 'whole' | 'per_bed';
        capacity: number;
        occupied: number;
        status: string;
        beds: { id: number; label: string }[];
    }[];
    residents: { id: number; name: string }[];
    today: string;
}

interface Props {
    tenantId: number;
    tenantName: string;
    options: RentalOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const statusText: Record<string, string> = {
    vacant: 'empty',
    available: 'has space',
    full: 'full',
    maintenance: 'under repair',
    unavailable: 'not available',
};

// Errors from the server-side rules that do not belong to a single input.
const ruleKeys = ['tenant', 'property', 'unit', 'capacity', 'unit_status'] as const;

export default function StartRentalDialog({ tenantId, tenantName, options, open, onOpenChange }: Props) {
    const blank = {
        unit_id: '',
        bed_id: '',
        rent: '',
        due_day: '5',
        cycle_start_day: '1',
        issue_lead_days: '7',
        prorate: false,
        start_date: options.today,
        end_date: '',
        resident_tenant_ids: [] as number[],
        deposit: '',
        override_reason: '',
    };
    const { data, setData, transform, post, processing, errors, clearErrors, reset } = useForm(blank);
    const [confirming, setConfirming] = useState(false);
    const [showOverride, setShowOverride] = useState(false);

    useEffect(() => {
        if (!open) return;
        reset();
        clearErrors();
        setConfirming(false);
        setShowOverride(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const unit = options.units.find((u) => String(u.id) === data.unit_id);
    const bed = unit?.beds.find((b) => String(b.id) === data.bed_id);
    const rentCents = parsePesos(data.rent);
    const depositCents = data.deposit.trim() === '' ? null : parsePesos(data.deposit);
    const residents = options.residents.filter((r) => data.resident_tenant_ids.includes(r.id));

    transform((d) => ({
        ...d,
        bed_id: unit?.rent_mode === 'per_bed' ? d.bed_id || null : null,
        resident_tenant_ids: unit?.rent_mode === 'whole' ? d.resident_tenant_ids : [],
    }));

    const toggleResident = (id: number, checked: boolean) =>
        setData('resident_tenant_ids', checked ? [...data.resident_tenant_ids, id] : data.resident_tenant_ids.filter((r) => r !== id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!confirming) {
            setConfirming(true);
            return;
        }
        post(route('tenants.agreements.store', tenantId), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errs) => {
                setConfirming(false);
                if (errs.unit) setShowOverride(true);
            },
        });
    };

    // The server also returns keys that are not form fields (bed, unit, capacity, ...).
    const serverErrors = errors as Record<string, string | undefined>;
    const ruleErrors = ruleKeys.map((k) => serverErrors[k]).filter(Boolean);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Start a rental for {tenantName}</DialogTitle>
                        <DialogDescription>
                            {tenantName} will be the person who pays. Their rent is billed every month on the due day you choose.
                        </DialogDescription>
                    </DialogHeader>

                    {ruleErrors.length > 0 && (
                        <Alert variant="destructive" role="alert">
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {ruleErrors.map((m) => (
                                        <li key={m}>{m}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {confirming ? (
                        <div className="bg-muted/40 space-y-3 rounded-lg border p-4 text-sm" aria-live="polite">
                            <p className="font-medium">Please check before saving:</p>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                <dt className="text-muted-foreground">Room</dt>
                                <dd>
                                    {unit?.code}
                                    {bed && ` / ${bed.label}`}
                                    {unit?.rent_mode === 'whole' && ' (whole room)'}
                                </dd>
                                <dt className="text-muted-foreground">Monthly rent</dt>
                                <dd className="font-semibold">{rentCents === null ? data.rent : formatCents(rentCents)}</dd>
                                <dt className="text-muted-foreground">Due</dt>
                                <dd>Day {data.due_day} of every month</dd>
                                <dt className="text-muted-foreground">Starts</dt>
                                <dd>
                                    {formatDate(data.start_date)}
                                    {data.end_date ? `, ends ${formatDate(data.end_date)}` : ', no end date'}
                                </dd>
                                {residents.length > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">Also living there</dt>
                                        <dd>{residents.map((r) => r.name).join(', ')}</dd>
                                    </>
                                )}
                                {depositCents !== null && (
                                    <>
                                        <dt className="text-muted-foreground">Deposit</dt>
                                        <dd>{formatCents(depositCents)}</dd>
                                    </>
                                )}
                                {data.override_reason.trim() && (
                                    <>
                                        <dt className="text-muted-foreground">Override</dt>
                                        <dd>{data.override_reason}</dd>
                                    </>
                                )}
                            </dl>
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="rental-unit">Room</Label>
                                <SearchSelect
                                    id="rental-unit"
                                    value={String(data.unit_id)}
                                    onChange={(v) => setData((d) => ({ ...d, unit_id: v, bed_id: '' }))}
                                    placeholder="Search room…"
                                    options={options.units.map((u) => ({
                                        value: String(u.id),
                                        label: u.code,
                                        hint: `${u.rent_mode === 'whole' ? 'whole room' : 'by bed'}, ${u.occupied} of ${u.capacity} people, ${statusText[u.status] ?? u.status}`,
                                    }))}
                                />
                                <InputError message={errors.unit_id} />
                            </div>

                            {unit?.rent_mode === 'per_bed' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-bed">Bed</Label>
                                    {unit.beds.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">Every bed in this room is taken.</p>
                                    ) : (
                                        <SearchSelect
                                            id="rental-bed"
                                            value={String(data.bed_id)}
                                            onChange={(v) => setData('bed_id', v)}
                                            placeholder="Choose a free bed"
                                            options={unit.beds.map((b) => ({ value: String(b.id), label: b.label }))}
                                        />
                                    )}
                                    <InputError message={serverErrors.bed ?? errors.bed_id} />
                                </div>
                            )}
                            {unit?.rent_mode !== 'per_bed' && <InputError message={serverErrors.bed} />}

                            <div className="grid gap-2">
                                <Label htmlFor="rental-rent">Monthly rent (pesos)</Label>
                                <Input
                                    id="rental-rent"
                                    inputMode="decimal"
                                    value={data.rent}
                                    onChange={(e) => setData('rent', e.target.value)}
                                    placeholder="2,500.00"
                                    className="max-w-48"
                                    aria-describedby="rental-rent-help"
                                    required
                                />
                                <p id="rental-rent-help" className="text-muted-foreground text-sm">
                                    {data.rent.trim() === ''
                                        ? 'Type the amount, like 2500 or 2,500.00.'
                                        : rentCents === null || rentCents === 0
                                          ? 'This does not look like a peso amount. Use digits, with commas only between thousands.'
                                          : `Will be saved as ${formatCents(rentCents)} a month.`}
                                </p>
                                <InputError message={errors.rent} />
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-due">Due day</Label>
                                    <Input
                                        id="rental-due"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={31}
                                        value={data.due_day}
                                        onChange={(e) => setData('due_day', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.due_day} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-cycle">Billing month starts on day</Label>
                                    <Input
                                        id="rental-cycle"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={28}
                                        value={data.cycle_start_day}
                                        onChange={(e) => setData('cycle_start_day', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.cycle_start_day} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-lead">Send bill days before due</Label>
                                    <Input
                                        id="rental-lead"
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={31}
                                        value={data.issue_lead_days}
                                        onChange={(e) => setData('issue_lead_days', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.issue_lead_days} />
                                </div>
                            </div>
                            <p className="text-muted-foreground -mt-2 text-sm">If the due day is 29, 30 or 31, shorter months use their last day.</p>

                            <div className="flex items-start gap-3">
                                <Checkbox id="rental-prorate" checked={data.prorate} onCheckedChange={(c) => setData('prorate', c === true)} />
                                <div className="grid gap-1">
                                    <Label htmlFor="rental-prorate">Charge only for the days stayed in the first month</Label>
                                    <p className="text-muted-foreground text-sm">Leave unticked to charge the full month.</p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-start">Move-in date</Label>
                                    <Input
                                        id="rental-start"
                                        type="date"
                                        value={data.start_date}
                                        onChange={(e) => setData('start_date', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.start_date} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="rental-end">Last day (optional)</Label>
                                    <Input
                                        id="rental-end"
                                        type="date"
                                        value={data.end_date}
                                        min={data.start_date}
                                        onChange={(e) => setData('end_date', e.target.value)}
                                    />
                                    <InputError message={errors.end_date} />
                                </div>
                            </div>

                            {unit?.rent_mode === 'whole' && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">Others living in the room (they do not pay)</legend>
                                    {options.residents.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            No other tenants without a room in this property. Add them as tenants first.
                                        </p>
                                    ) : (
                                        <div className="grid max-h-40 gap-2 overflow-y-auto rounded-md border p-3">
                                            {options.residents.map((r) => (
                                                <div key={r.id} className="flex items-center gap-3">
                                                    <Checkbox
                                                        id={`resident-${r.id}`}
                                                        checked={data.resident_tenant_ids.includes(r.id)}
                                                        onCheckedChange={(c) => toggleResident(r.id, c === true)}
                                                    />
                                                    <Label htmlFor={`resident-${r.id}`} className="font-normal">
                                                        {r.name}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <InputError message={errors.resident_tenant_ids} />
                                </fieldset>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="rental-deposit">Security deposit received (optional, pesos)</Label>
                                <Input
                                    id="rental-deposit"
                                    inputMode="decimal"
                                    value={data.deposit}
                                    onChange={(e) => setData('deposit', e.target.value)}
                                    className="max-w-48"
                                    aria-describedby="rental-deposit-help"
                                />
                                {data.deposit.trim() !== '' && (
                                    <p id="rental-deposit-help" className="text-muted-foreground text-sm">
                                        {depositCents === null || depositCents === 0
                                            ? 'This does not look like a peso amount.'
                                            : `Will be saved as ${formatCents(depositCents)}.`}
                                    </p>
                                )}
                                <InputError message={errors.deposit} />
                            </div>

                            {showOverride ? (
                                <div className="grid gap-2 rounded-lg border border-dashed p-3">
                                    <Label htmlFor="rental-override">Reason to allow overlapping dates (owner only)</Label>
                                    <Input
                                        id="rental-override"
                                        value={data.override_reason}
                                        onChange={(e) => setData('override_reason', e.target.value)}
                                    />
                                    <p className="text-muted-foreground text-sm">
                                        Only use this if you are sure. It is recorded in the activity log. It never allows more people than the room
                                        fits.
                                    </p>
                                    <InputError message={errors.override_reason} />
                                </div>
                            ) : (
                                <InputError message={errors.override_reason} />
                            )}
                        </>
                    )}

                    <DialogFooter className="gap-2">
                        {confirming ? (
                            <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                                Back to edit
                            </Button>
                        ) : (
                            <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                        )}
                        <Button disabled={processing || !unit || rentCents === null || rentCents === 0}>
                            {processing ? 'Saving...' : confirming ? 'Yes, start rental' : 'Review'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
