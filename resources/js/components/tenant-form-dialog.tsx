import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { Textarea } from '@/components/ui/textarea';
import { deliveryLabels } from '@/lib/tenant-labels';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface TenantFormData {
    tenant: {
        id: number;
        property_id: number;
        full_name: string;
        email: string | null;
        mobile: string | null;
        emergency_contact_name: string | null;
        emergency_contact_phone: string | null;
        preferred_delivery: string;
        email_opt_in: boolean;
        remarks: string | null;
    } | null;
    properties: { id: number; name: string }[];
    deliveryMethods: string[];
    propertyLocked: boolean;
    /** Rooms a new tenant can move into right away (empty when editing or not allowed to start rentals). */
    rooms?: RoomOption[];
    today?: string;
}

export interface RoomOption {
    unit_id: number;
    property_id: number;
    building: string | null;
    floor: string | null;
    code: string;
    per_bed: boolean;
    capacity: number;
    occupied: number;
    /** Free beds only. */
    beds: { id: number; label: string }[];
}

const NO_BUILDING = '__none__';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: TenantFormData;
}

/** Add or edit a tenant in a modal. Server rules (StoreTenantRequest) decide what is valid. */
export default function TenantFormDialog({ open, onOpenChange, form }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">{open && <TenantForm form={form} onDone={() => onOpenChange(false)} />}</DialogContent>
        </Dialog>
    );
}

function TenantForm({
    form: { tenant, properties, deliveryMethods, propertyLocked, rooms: allRooms = [], today = '' },
    onDone,
}: {
    form: TenantFormData;
    onDone: () => void;
}) {
    const editing = tenant !== null;
    const { data, setData, post, put, processing, errors } = useForm({
        property_id: tenant?.property_id ?? (properties.length === 1 ? properties[0].id : 0),
        full_name: tenant?.full_name ?? '',
        email: tenant?.email ?? '',
        mobile: tenant?.mobile ?? '',
        emergency_contact_name: tenant?.emergency_contact_name ?? '',
        emergency_contact_phone: tenant?.emergency_contact_phone ?? '',
        preferred_delivery: tenant?.preferred_delivery ?? 'email',
        email_opt_in: tenant?.email_opt_in ?? true,
        remarks: tenant?.remarks ?? '',
        // Optional move-in (new tenant only): starts the rental in the same save.
        unit_id: '',
        bed_id: '',
        rent: '',
        start_date: today,
        due_day: '5',
        deposit: '',
    });
    const [building, setBuilding] = useState('');
    const rooms = allRooms.filter((r) => r.property_id === Number(data.property_id));
    const buildings = [...new Set(rooms.map((r) => r.building ?? NO_BUILDING))];
    const roomsInBuilding = rooms.filter((r) => (r.building ?? NO_BUILDING) === building);
    const room = rooms.find((r) => String(r.unit_id) === data.unit_id) ?? null;
    // Room rules checked when saving (bed taken, other property, archived...) come back under these keys.
    const allErrors = errors as Record<string, string | undefined>;
    const moveErrors = { unit: allErrors.unit, bed: allErrors.bed };
    const shown = new Set([
        'property_id',
        'full_name',
        'email',
        'mobile',
        'emergency_contact_name',
        'emergency_contact_phone',
        'preferred_delivery',
        'email_opt_in',
        'remarks',
        'unit_id',
        'bed_id',
        'rent',
        'start_date',
        'due_day',
        'deposit',
        'unit',
        'bed',
    ]);
    const otherMoveErrors = Object.entries(allErrors)
        .filter(([key, message]) => !shown.has(key) && message)
        .map(([, message]) => message as string);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (editing) put(route('tenants.update', tenant.id), options);
        else post(route('tenants.store'), options);
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <DialogHeader>
                <DialogTitle>{editing ? `Edit ${tenant.full_name}` : 'Add a tenant'}</DialogTitle>
                <DialogDescription>
                    {editing ? 'Contact details and statement delivery.' : 'Add their details, and choose their room to start the rental right away.'}
                </DialogDescription>
            </DialogHeader>
            {properties.length === 0 ? (
                <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                    You have no properties yet. Ask the owner to assign you to one.
                </p>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="property_id">Property</Label>
                    <SearchSelect
                        id="property_id"
                        aria-describedby={propertyLocked ? 'property-help' : undefined}
                        value={data.property_id ? String(data.property_id) : ''}
                        onChange={(v) => {
                            setData((d) => ({ ...d, property_id: Number(v), unit_id: '', bed_id: '' }));
                            setBuilding('');
                        }}
                        disabled={propertyLocked}
                        placeholder="Choose a property"
                        options={properties.map((p) => ({ value: String(p.id), label: p.name }))}
                    />
                    {propertyLocked && (
                        <p id="property-help" className="text-muted-foreground text-xs">
                            This tenant has rentals at their current property. Start a transfer instead.
                        </p>
                    )}
                    <InputError message={errors.property_id} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="full_name">Full name</Label>
                <Input
                    id="full_name"
                    value={data.full_name}
                    onChange={(e) => setData('full_name', e.target.value)}
                    placeholder="e.g. DELA CRUZ, Juan"
                    required
                    autoFocus
                />
                <InputError message={errors.full_name} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="mobile">Mobile number</Label>
                    <Input
                        id="mobile"
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        placeholder="09171234567"
                        value={data.mobile}
                        onChange={(e) => setData('mobile', e.target.value)}
                        aria-describedby="mobile-help"
                    />
                    <p id="mobile-help" className="text-muted-foreground text-xs">
                        Optional. Like 09171234567 or +639171234567.
                    </p>
                    <InputError message={errors.mobile} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="email"
                        placeholder="Optional"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 sm:items-end">
                <div className="grid gap-2">
                    <Label htmlFor="preferred_delivery">Send statements by</Label>
                    <SearchSelect
                        id="preferred_delivery"
                        value={data.preferred_delivery}
                        onChange={(v) => setData('preferred_delivery', v)}
                        options={deliveryMethods.map((m) => ({ value: m, label: deliveryLabels[m] ?? m }))}
                    />
                    <InputError message={errors.preferred_delivery} />
                </div>
                <label
                    htmlFor="email_opt_in"
                    className="hover:bg-muted/50 flex h-10 items-center gap-3 rounded-lg border px-3 text-sm transition-colors"
                >
                    <Checkbox id="email_opt_in" checked={data.email_opt_in} onCheckedChange={(c) => setData('email_opt_in', c === true)} />
                    Okay to email this tenant
                </label>
            </div>
            <fieldset className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                <legend className="px-1 text-sm font-medium">Emergency contact (optional)</legend>
                <div className="grid gap-2">
                    <Label htmlFor="emergency_contact_name">Name</Label>
                    <Input
                        id="emergency_contact_name"
                        value={data.emergency_contact_name}
                        onChange={(e) => setData('emergency_contact_name', e.target.value)}
                    />
                    <InputError message={errors.emergency_contact_name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="emergency_contact_phone">Phone number</Label>
                    <Input
                        id="emergency_contact_phone"
                        type="tel"
                        inputMode="tel"
                        value={data.emergency_contact_phone}
                        onChange={(e) => setData('emergency_contact_phone', e.target.value)}
                    />
                    <InputError message={errors.emergency_contact_phone} />
                </div>
            </fieldset>
            {!editing && rooms.length > 0 && (
                <fieldset className="grid gap-4 rounded-xl border p-4">
                    <legend className="px-1 text-sm font-medium">Where will they stay? (optional)</legend>
                    <p className="text-muted-foreground -mt-2 text-xs">
                        Choose the room to start their rental now. Leave it empty to add only the tenant.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="building">Building</Label>
                            <SearchSelect
                                id="building"
                                value={building}
                                onChange={(v) => {
                                    setBuilding(v);
                                    setData((d) => ({ ...d, unit_id: '', bed_id: '' }));
                                }}
                                placeholder="Choose a building"
                                options={buildings.map((b) => ({ value: b, label: b === NO_BUILDING ? 'No building' : b }))}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="unit_id">Room</Label>
                            <SearchSelect
                                id="unit_id"
                                value={data.unit_id}
                                onChange={(v) => setData((d) => ({ ...d, unit_id: v, bed_id: '' }))}
                                disabled={building === ''}
                                placeholder={building === '' ? 'Choose a building first' : 'Choose a room'}
                                options={roomsInBuilding.map((r) => {
                                    const full = r.per_bed ? r.beds.length === 0 : r.occupied >= r.capacity;
                                    return {
                                        value: String(r.unit_id),
                                        label: r.code,
                                        hint: [r.floor, `${r.occupied}/${r.capacity} ${r.per_bed ? 'beds taken' : 'staying'}`, full ? 'full' : null]
                                            .filter(Boolean)
                                            .join(' · '),
                                        disabled: full,
                                    };
                                })}
                            />
                            <InputError message={errors.unit_id ?? moveErrors.unit} />
                        </div>
                    </div>

                    {room?.per_bed && (
                        <div className="grid gap-2">
                            <Label htmlFor="bed_id">Bed</Label>
                            <SearchSelect
                                id="bed_id"
                                value={data.bed_id}
                                onChange={(v) => setData('bed_id', v)}
                                placeholder="Choose a free bed"
                                options={room.beds.map((b) => ({ value: String(b.id), label: b.label }))}
                            />
                            <InputError message={errors.bed_id ?? moveErrors.bed} />
                        </div>
                    )}

                    {room && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="rent">Monthly rent (₱)</Label>
                                <Input
                                    id="rent"
                                    inputMode="decimal"
                                    placeholder="e.g. 700"
                                    value={data.rent}
                                    onChange={(e) => setData('rent', e.target.value)}
                                    required
                                />
                                <InputError message={errors.rent} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="start_date">Move-in date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={data.start_date}
                                    onChange={(e) => setData('start_date', e.target.value)}
                                    required
                                />
                                <InputError message={errors.start_date} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="due_day">Rent due every month on day</Label>
                                <SearchSelect
                                    id="due_day"
                                    value={data.due_day}
                                    onChange={(v) => setData('due_day', v)}
                                    options={Array.from({ length: 31 }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))}
                                />
                                <InputError message={errors.due_day} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="deposit">Deposit received (₱)</Label>
                                <Input
                                    id="deposit"
                                    inputMode="decimal"
                                    placeholder="Optional"
                                    value={data.deposit}
                                    onChange={(e) => setData('deposit', e.target.value)}
                                />
                                <InputError message={errors.deposit} />
                            </div>
                        </div>
                    )}
                    {otherMoveErrors.length > 0 && (
                        <ul className="text-destructive space-y-1 text-sm" role="alert">
                            {otherMoveErrors.map((m) => (
                                <li key={m}>{m}</li>
                            ))}
                        </ul>
                    )}
                </fieldset>
            )}{' '}
            <div className="grid gap-2">
                <Label htmlFor="remarks">Notes</Label>
                <Textarea id="remarks" placeholder="Optional" value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                <InputError message={errors.remarks} />
            </div>
            <DialogFooter className="bg-card sticky -bottom-6 -mx-6 -mb-6 border-t px-6 py-4">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button disabled={processing || properties.length === 0}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    {editing ? 'Save changes' : data.unit_id ? 'Add tenant and move in' : 'Add tenant'}
                </Button>
            </DialogFooter>
        </form>
    );
}
