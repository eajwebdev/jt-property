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
import { FormEventHandler } from 'react';

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
}

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

function TenantForm({ form: { tenant, properties, deliveryMethods, propertyLocked }, onDone }: { form: TenantFormData; onDone: () => void }) {
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
    });

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
                    {editing ? 'Contact details and statement delivery.' : 'You can start a rental for them right after saving.'}
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
                        onChange={(v) => setData('property_id', Number(v))}
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
                    {editing ? 'Save changes' : 'Add tenant'}
                </Button>
            </DialogFooter>
        </form>
    );
}
