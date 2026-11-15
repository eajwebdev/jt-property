import InputError from '@/components/input-error';
import { type RentalOptions } from '@/components/start-rental-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCents, parsePesos } from '@/lib/money';
import { formatDate } from '@/lib/tenant-labels';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, ReactNode, useEffect, useState } from 'react';

export interface ActionAgreement {
    id: number;
    room: string;
    start_date: string;
    end_date: string | null;
    whole: boolean;
    current_rent_cents: number | null;
}

interface BaseProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    today: string;
}

/** Adds days to a YYYY-MM-DD date without timezone drift. */
function addDays(iso: string, days: number): string {
    const d = new Date(iso + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

function pesoHelp(input: string, emptyText: string, suffix = ''): string {
    if (input.trim() === '') return emptyText;
    const cents = parsePesos(input);
    return cents === null || cents === 0
        ? 'This does not look like a peso amount. Use digits, with commas only between thousands.'
        : `Will be saved as ${formatCents(cents)}${suffix}.`;
}

/** Shared frame: form fields first, then a "please check" step, then save. */
function ActionDialog({
    open,
    onOpenChange,
    title,
    description,
    confirming,
    setConfirming,
    processing,
    canReview,
    confirmLabel,
    summary,
    alerts,
    onSave,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirming: boolean;
    setConfirming: (v: boolean) => void;
    processing: boolean;
    canReview: boolean;
    confirmLabel: string;
    summary: [string, ReactNode][];
    alerts: (string | undefined)[];
    onSave: () => void;
    children: ReactNode;
}) {
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (confirming) onSave();
        else setConfirming(true);
    };
    const messages = alerts.filter(Boolean) as string[];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {messages.length > 0 && (
                        <Alert variant="destructive" role="alert">
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {messages.map((m) => (
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
                                {summary.map(([label, value]) => (
                                    <div key={label} className="contents">
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    ) : (
                        children
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
                        <Button disabled={processing || !canReview}>{processing ? 'Saving...' : confirming ? confirmLabel : 'Review'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** useForm plus the reset-on-open and confirm-step state every dialog needs. */
function useActionForm<T extends Record<string, string>>(open: boolean, initial: T) {
    const form = useForm<T>(initial);
    const [confirming, setConfirming] = useState(false);

    useEffect(() => {
        if (!open) return;
        form.setData(initial);
        form.clearErrors();
        setConfirming(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const save = (url: string, onDone: () => void) =>
        form.post(url, { preserveScroll: true, onSuccess: onDone, onError: () => setConfirming(false) });

    return { ...form, errors: form.errors as Record<string, string | undefined>, confirming, setConfirming, save };
}

function ReasonField({
    id,
    value,
    onChange,
    error,
    label = 'Reason',
}: {
    id: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    label?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} value={value} onChange={(e) => onChange(e.target.value)} required />
            <p className="text-muted-foreground text-sm">Saved in the activity log.</p>
            <InputError message={error} />
        </div>
    );
}

export function ChangeRentDialog({ agreement, open, onOpenChange, today }: BaseProps & { agreement: ActionAgreement }) {
    const from = today < agreement.start_date ? agreement.start_date : today;
    const f = useActionForm(open, { rent: '', effective_from: from, reason: '' });
    const cents = parsePesos(f.data.rent);

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Change rent for room ${agreement.room}`}
            description="The new rent applies to bills from the date you choose. Earlier months are not changed."
            confirming={f.confirming}
            setConfirming={f.setConfirming}
            processing={f.processing}
            canReview={!!cents && f.data.reason.trim() !== ''}
            confirmLabel="Yes, change rent"
            alerts={[f.errors.agreement]}
            onSave={() => f.save(route('agreements.rent.store', agreement.id), () => onOpenChange(false))}
            summary={[
                ['Room', agreement.room],
                ['Current rent', agreement.current_rent_cents === null ? '-' : formatCents(agreement.current_rent_cents)],
                ['New rent', <span className="font-semibold">{cents ? formatCents(cents) : f.data.rent}</span>],
                ['Takes effect on', formatDate(f.data.effective_from)],
                ['Reason', f.data.reason],
            ]}
        >
            <p className="text-sm">
                Current rent:{' '}
                <span className="font-semibold">{agreement.current_rent_cents === null ? '-' : formatCents(agreement.current_rent_cents)}</span> a
                month
            </p>
            <div className="grid gap-2">
                <Label htmlFor="change-rent">New monthly rent (pesos)</Label>
                <Input
                    id="change-rent"
                    inputMode="decimal"
                    value={f.data.rent}
                    onChange={(e) => f.setData('rent', e.target.value)}
                    placeholder="2,800.00"
                    className="max-w-48"
                    aria-describedby="change-rent-help"
                    required
                />
                <p id="change-rent-help" className="text-muted-foreground text-sm">
                    {pesoHelp(f.data.rent, 'Type the amount, like 2800 or 2,800.00.', ' a month')}
                </p>
                <InputError message={f.errors.rent} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="change-from">Takes effect on</Label>
                <Input
                    id="change-from"
                    type="date"
                    value={f.data.effective_from}
                    min={agreement.start_date}
                    max={agreement.end_date ?? undefined}
                    onChange={(e) => f.setData('effective_from', e.target.value)}
                    className="max-w-48"
                    aria-describedby="change-from-help"
                    required
                />
                <p id="change-from-help" className="text-muted-foreground text-sm">
                    Bills already issued keep their old amount.
                </p>
                <InputError message={f.errors.effective_from} />
            </div>
            <ReasonField id="change-reason" value={f.data.reason} onChange={(v) => f.setData('reason', v)} error={f.errors.reason} />
        </ActionDialog>
    );
}

export function MoveOutDialog({ agreement, open, onOpenChange, today }: BaseProps & { agreement: ActionAgreement }) {
    const initial =
        agreement.end_date && agreement.end_date < today ? agreement.end_date : today < agreement.start_date ? agreement.start_date : today;
    const f = useActionForm(open, { move_out_date: initial, reason: '' });

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Move out of room ${agreement.room}`}
            description="Choose the last day they live in the room. The room is free for someone else from the next day. Past bills are kept."
            confirming={f.confirming}
            setConfirming={f.setConfirming}
            processing={f.processing}
            canReview={f.data.move_out_date !== '' && f.data.reason.trim() !== ''}
            confirmLabel="Yes, save move-out"
            alerts={[]}
            onSave={() => f.save(route('agreements.move-out', agreement.id), () => onOpenChange(false))}
            summary={[
                ['Room', agreement.room],
                ['Last day in the room', formatDate(f.data.move_out_date)],
                ['Room free from', f.data.move_out_date ? formatDate(addDays(f.data.move_out_date, 1)) : '-'],
                ['Reason', f.data.reason],
            ]}
        >
            <div className="grid gap-2">
                <Label htmlFor="moveout-date">Last day in the room</Label>
                <Input
                    id="moveout-date"
                    type="date"
                    value={f.data.move_out_date}
                    min={agreement.start_date}
                    max={agreement.end_date ?? addDays(today, 365)}
                    onChange={(e) => f.setData('move_out_date', e.target.value)}
                    className="max-w-48"
                    required
                />
                <InputError message={f.errors.move_out_date} />
            </div>
            <ReasonField id="moveout-reason" value={f.data.reason} onChange={(v) => f.setData('reason', v)} error={f.errors.reason} />
        </ActionDialog>
    );
}

/** A resident leaving on their own; the rental and the payer carry on. */
export function MoveOutResidentDialog({
    occupancy,
    open,
    onOpenChange,
    today,
}: BaseProps & { occupancy: { id: number; room: string; move_in_date: string; tenant: string } }) {
    const f = useActionForm(open, { move_out_date: today < occupancy.move_in_date ? occupancy.move_in_date : today, reason: '' });

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Move ${occupancy.tenant} out of room ${occupancy.room}`}
            description="Choose their last day in the room. The rental and the person paying carry on, and their place is free from the next day."
            confirming={f.confirming}
            setConfirming={f.setConfirming}
            processing={f.processing}
            canReview={f.data.move_out_date !== '' && f.data.reason.trim() !== ''}
            confirmLabel="Yes, save move-out"
            alerts={[f.errors.occupancy, f.errors.agreement]}
            onSave={() => f.save(route('occupancies.move-out', occupancy.id), () => onOpenChange(false))}
            summary={[
                ['Person', occupancy.tenant],
                ['Room', occupancy.room],
                ['Last day in the room', formatDate(f.data.move_out_date)],
                ['Place free from', f.data.move_out_date ? formatDate(addDays(f.data.move_out_date, 1)) : '-'],
                ['Reason', f.data.reason],
            ]}
        >
            <div className="grid gap-2">
                <Label htmlFor="resident-moveout-date">Last day in the room</Label>
                <Input
                    id="resident-moveout-date"
                    type="date"
                    value={f.data.move_out_date}
                    min={occupancy.move_in_date}
                    max={addDays(today, 365)}
                    onChange={(e) => f.setData('move_out_date', e.target.value)}
                    className="max-w-48"
                    required
                />
                <InputError message={f.errors.move_out_date} />
            </div>
            <ReasonField id="resident-moveout-reason" value={f.data.reason} onChange={(v) => f.setData('reason', v)} error={f.errors.reason} />
        </ActionDialog>
    );
}

// Rule errors from the shared start-rental checks that do not belong to one input.
const transferAlertKeys = ['tenant', 'property', 'unit', 'capacity', 'unit_status', 'bed', 'resident_tenant_ids', 'end_date'];

export function TransferDialog({
    agreement,
    options,
    open,
    onOpenChange,
    today,
}: BaseProps & { agreement: ActionAgreement; options: RentalOptions }) {
    const f = useActionForm(open, { unit_id: '', bed_id: '', transfer_date: today, rent: '', reason: '' });
    const unit = options.units.find((u) => String(u.id) === f.data.unit_id);
    const bed = unit?.beds.find((b) => String(b.id) === f.data.bed_id);
    const newRent = f.data.rent.trim() === '' ? null : parsePesos(f.data.rent);
    const rentOk = f.data.rent.trim() === '' || !!newRent;
    const rentShown = newRent ?? agreement.current_rent_cents;

    f.transform((d) => ({ ...d, bed_id: unit?.rent_mode === 'per_bed' ? d.bed_id || null : null }));

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Move from room ${agreement.room} to another room`}
            description="The current rental ends the day before the move. A new rental starts on the move date with the same account, due day and billing settings. Anyone living with them moves too."
            confirming={f.confirming}
            setConfirming={f.setConfirming}
            processing={f.processing}
            canReview={!!unit && (unit.rent_mode !== 'per_bed' || !!bed) && f.data.transfer_date !== '' && rentOk && f.data.reason.trim() !== ''}
            confirmLabel="Yes, move rooms"
            alerts={transferAlertKeys.map((k) => f.errors[k])}
            onSave={() => f.save(route('agreements.transfer', agreement.id), () => onOpenChange(false))}
            summary={[
                ['From', `${agreement.room}, last day ${f.data.transfer_date ? formatDate(addDays(f.data.transfer_date, -1)) : '-'}`],
                ['To', `${unit?.code ?? ''}${bed ? ` / ${bed.label}` : ''}${unit?.rent_mode === 'whole' ? ' (whole room)' : ''}`],
                ['Move date', formatDate(f.data.transfer_date)],
                ['Monthly rent', <span className="font-semibold">{rentShown === null ? '-' : formatCents(rentShown)}</span>],
                ['Reason', f.data.reason],
            ]}
        >
            <div className="grid gap-2">
                <Label htmlFor="transfer-unit">New room</Label>
                <Select value={f.data.unit_id} onValueChange={(v) => f.setData((d) => ({ ...d, unit_id: v, bed_id: '' }))}>
                    <SelectTrigger id="transfer-unit">
                        <SelectValue placeholder="Choose a room" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.units.map((u) => (
                            <SelectItem key={u.id} value={String(u.id)}>
                                {u.code} - {u.rent_mode === 'whole' ? 'whole room' : 'by bed'}, {u.occupied} of {u.capacity} people
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={f.errors.unit_id} />
            </div>
            {unit?.rent_mode === 'per_bed' && (
                <div className="grid gap-2">
                    <Label htmlFor="transfer-bed">Bed</Label>
                    {unit.beds.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Every bed in this room is taken.</p>
                    ) : (
                        <Select value={f.data.bed_id} onValueChange={(v) => f.setData('bed_id', v)}>
                            <SelectTrigger id="transfer-bed">
                                <SelectValue placeholder="Choose a free bed" />
                            </SelectTrigger>
                            <SelectContent>
                                {unit.beds.map((b) => (
                                    <SelectItem key={b.id} value={String(b.id)}>
                                        {b.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <InputError message={f.errors.bed_id} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="transfer-date">Move date (first day in the new room)</Label>
                <Input
                    id="transfer-date"
                    type="date"
                    value={f.data.transfer_date}
                    min={addDays(agreement.start_date, 1)}
                    max={agreement.end_date ? addDays(agreement.end_date, 1) : undefined}
                    onChange={(e) => f.setData('transfer_date', e.target.value)}
                    className="max-w-48"
                    required
                />
                <InputError message={f.errors.transfer_date} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="transfer-rent">New monthly rent (optional, pesos)</Label>
                <Input
                    id="transfer-rent"
                    inputMode="decimal"
                    value={f.data.rent}
                    onChange={(e) => f.setData('rent', e.target.value)}
                    className="max-w-48"
                    aria-describedby="transfer-rent-help"
                />
                <p id="transfer-rent-help" className="text-muted-foreground text-sm">
                    {pesoHelp(
                        f.data.rent,
                        `Leave empty to keep the current rent${agreement.current_rent_cents === null ? '' : ` of ${formatCents(agreement.current_rent_cents)}`}.`,
                        ' a month',
                    )}
                </p>
                <InputError message={f.errors.rent} />
            </div>
            <ReasonField id="transfer-reason" value={f.data.reason} onChange={(v) => f.setData('reason', v)} error={f.errors.reason} />
        </ActionDialog>
    );
}

export function RecordDepositDialog({
    accountId,
    accountNo,
    agreements,
    open,
    onOpenChange,
    today,
}: BaseProps & { accountId: number; accountNo: string; agreements: ActionAgreement[] }) {
    const f = useActionForm(open, {
        amount: '',
        received_on: today,
        rental_agreement_id: agreements.length === 1 ? String(agreements[0].id) : '',
        notes: '',
    });
    const cents = parsePesos(f.data.amount);
    const agreement = agreements.find((a) => String(a.id) === f.data.rental_agreement_id);

    f.transform((d) => ({ ...d, rental_agreement_id: d.rental_agreement_id || null }));

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Record a deposit for account ${accountNo}`}
            description="A deposit is money you hold for the tenant. It is kept separately and does not change the rent or any bill."
            confirming={f.confirming}
            setConfirming={f.setConfirming}
            processing={f.processing}
            canReview={!!cents && f.data.received_on !== ''}
            confirmLabel="Yes, record deposit"
            alerts={[]}
            onSave={() => f.save(route('accounts.deposits.store', accountId), () => onOpenChange(false))}
            summary={[
                ['Amount', <span className="font-semibold">{cents ? formatCents(cents) : f.data.amount}</span>],
                ['Received on', formatDate(f.data.received_on)],
                ['For room', agreement?.room ?? 'Not linked to a room'],
                ['Notes', f.data.notes || '-'],
            ]}
        >
            <div className="grid gap-2">
                <Label htmlFor="deposit-amount">Amount received (pesos)</Label>
                <Input
                    id="deposit-amount"
                    inputMode="decimal"
                    value={f.data.amount}
                    onChange={(e) => f.setData('amount', e.target.value)}
                    placeholder="5,000.00"
                    className="max-w-48"
                    aria-describedby="deposit-amount-help"
                    required
                />
                <p id="deposit-amount-help" className="text-muted-foreground text-sm">
                    {pesoHelp(f.data.amount, 'Type the amount, like 5000 or 5,000.00.')}
                </p>
                <InputError message={f.errors.amount} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="deposit-date">Date received</Label>
                <Input
                    id="deposit-date"
                    type="date"
                    value={f.data.received_on}
                    max={today}
                    onChange={(e) => f.setData('received_on', e.target.value)}
                    className="max-w-48"
                    required
                />
                <InputError message={f.errors.received_on} />
            </div>
            {agreements.length > 0 && (
                <div className="grid gap-2">
                    <Label htmlFor="deposit-agreement">For room (optional)</Label>
                    <Select value={f.data.rental_agreement_id} onValueChange={(v) => f.setData('rental_agreement_id', v)}>
                        <SelectTrigger id="deposit-agreement">
                            <SelectValue placeholder="Not linked to a room" />
                        </SelectTrigger>
                        <SelectContent>
                            {agreements.map((a) => (
                                <SelectItem key={a.id} value={String(a.id)}>
                                    Room {a.room} (from {formatDate(a.start_date)})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={f.errors.rental_agreement_id} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="deposit-notes">Notes (optional)</Label>
                <Input id="deposit-notes" value={f.data.notes} onChange={(e) => f.setData('notes', e.target.value)} placeholder="Cash, two months" />
                <p className="text-muted-foreground text-sm">Saved in the activity log.</p>
                <InputError message={f.errors.notes} />
            </div>
        </ActionDialog>
    );
}
