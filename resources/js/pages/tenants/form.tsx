import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { deliveryLabels } from '@/lib/tenant-labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
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

const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm';

export default function Form({ tenant, properties, deliveryMethods, propertyLocked }: Props) {
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

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tenants', href: '/tenants' },
        { title: editing ? `Edit ${tenant.full_name}` : 'Add tenant', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) put(route('tenants.update', tenant.id));
        else post(route('tenants.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? 'Edit tenant' : 'Add tenant'} />

            <form onSubmit={submit} className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">{editing ? `Edit ${tenant.full_name}` : 'Add a tenant'}</h1>

                {properties.length === 0 ? (
                    <p className="text-sm text-muted-foreground">You have no properties yet. Ask the owner to assign you to one.</p>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="property_id">Property</Label>
                        <Select value={data.property_id ? String(data.property_id) : ''} onValueChange={(v) => setData('property_id', Number(v))} disabled={propertyLocked}>
                            <SelectTrigger id="property_id" aria-describedby={propertyLocked ? 'property-help' : undefined}>
                                <SelectValue placeholder="Choose a property" />
                            </SelectTrigger>
                            <SelectContent>
                                {properties.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {propertyLocked && (
                            <p id="property-help" className="text-sm text-muted-foreground">
                                This tenant has rentals at their current property. Start a transfer instead.
                            </p>
                        )}
                        <InputError message={errors.property_id} />
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="full_name">Full name</Label>
                    <Input id="full_name" value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} required autoFocus />
                    <InputError message={errors.full_name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="mobile">Mobile number (optional)</Label>
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
                    <p id="mobile-help" className="text-sm text-muted-foreground">Like 09171234567 or +639171234567.</p>
                    <InputError message={errors.mobile} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email (optional)</Label>
                    <Input id="email" type="email" autoComplete="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="preferred_delivery">How should statements be sent?</Label>
                    <Select value={data.preferred_delivery} onValueChange={(v) => setData('preferred_delivery', v)}>
                        <SelectTrigger id="preferred_delivery">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {deliveryMethods.map((m) => (
                                <SelectItem key={m} value={m}>
                                    {deliveryLabels[m] ?? m}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.preferred_delivery} />
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox id="email_opt_in" checked={data.email_opt_in} onCheckedChange={(c) => setData('email_opt_in', c === true)} />
                    <Label htmlFor="email_opt_in" className="text-base font-normal">
                        Okay to send emails to this tenant
                    </Label>
                </div>

                <fieldset className="grid gap-4">
                    <legend className="mb-1 text-sm font-medium">Emergency contact (optional)</legend>
                    <div className="grid gap-2">
                        <Label htmlFor="emergency_contact_name">Name</Label>
                        <Input id="emergency_contact_name" value={data.emergency_contact_name} onChange={(e) => setData('emergency_contact_name', e.target.value)} />
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
                    <Label htmlFor="remarks">Notes (optional)</Label>
                    <textarea id="remarks" className={textareaClass} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                    <InputError message={errors.remarks} />
                </div>

                <div className="flex flex-wrap gap-3">
                    <Button size="lg" disabled={processing || properties.length === 0}>
                        {processing ? 'Saving...' : editing ? 'Save changes' : 'Add tenant'}
                    </Button>
                    <Button asChild size="lg" variant="outline">
                        <Link href={editing ? route('tenants.show', tenant.id) : route('tenants.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
