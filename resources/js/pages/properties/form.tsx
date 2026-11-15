import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { propertyTypeLabels } from '@/lib/property-labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    property: { id: number; name: string; code: string; type: string; address: string; description: string | null; amenities: string | null; has_billing: boolean } | null;
    staff_ids: number[];
    types: string[];
    staff: { id: number; name: string }[];
}

const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm';

export default function Form({ property, staff_ids, types, staff }: Props) {
    const editing = property !== null;
    const { data, setData, post, put, processing, errors } = useForm({
        name: property?.name ?? '',
        code: property?.code ?? '',
        type: property?.type ?? 'boarding_house',
        address: property?.address ?? '',
        description: property?.description ?? '',
        amenities: property?.amenities ?? '',
        staff_ids,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Properties', href: '/properties' },
        { title: editing ? `Edit ${property.name}` : 'Add property', href: '#' },
    ];

    const toggleStaff = (id: number, checked: boolean) => setData('staff_ids', checked ? [...data.staff_ids, id] : data.staff_ids.filter((s) => s !== id));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) put(route('properties.update', property.id));
        else post(route('properties.store'));
    };

    const codeLocked = editing && property.has_billing;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? 'Edit property' : 'Add property'} />

            <form onSubmit={submit} className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">{editing ? `Edit ${property.name}` : 'Add a property'}</h1>

                <div className="grid gap-2">
                    <Label htmlFor="name">Property name</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Short code</Label>
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        maxLength={10}
                        className="max-w-40 uppercase"
                        aria-describedby="code-help"
                        readOnly={codeLocked}
                        required
                    />
                    <p id="code-help" className="text-sm text-muted-foreground">
                        2 to 10 capital letters or numbers, like RIV. It appears on every invoice number
                        {codeLocked ? ' and can no longer be changed.' : ', so choose carefully.'}
                    </p>
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="type">What kind of property is it?</Label>
                    <Select value={data.type} onValueChange={(value) => setData('type', value)}>
                        <SelectTrigger id="type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {propertyTypeLabels[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="address">Address</Label>
                    <textarea id="address" className={textareaClass} value={data.address} onChange={(e) => setData('address', e.target.value)} required />
                    <InputError message={errors.address} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">Notes (optional)</Label>
                    <textarea id="description" className={textareaClass} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="amenities">What is included? (optional)</Label>
                    <textarea
                        id="amenities"
                        className={textareaClass}
                        placeholder="For example: water, wifi, parking"
                        value={data.amenities}
                        onChange={(e) => setData('amenities', e.target.value)}
                    />
                    <InputError message={errors.amenities} />
                </div>

                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-sm font-medium">Staff who can work on this property</legend>
                    {staff.length === 0 ? (
                        <p className="text-sm text-muted-foreground">You have no staff users yet. Add one under Settings, Users.</p>
                    ) : (
                        staff.map((member) => (
                            <div key={member.id} className="flex items-center gap-3">
                                <Checkbox
                                    id={`staff-${member.id}`}
                                    checked={data.staff_ids.includes(member.id)}
                                    onCheckedChange={(checked) => toggleStaff(member.id, checked === true)}
                                />
                                <Label htmlFor={`staff-${member.id}`} className="text-base font-normal">
                                    {member.name}
                                </Label>
                            </div>
                        ))
                    )}
                    <InputError message={errors.staff_ids} />
                </fieldset>

                <div className="flex flex-wrap gap-3">
                    <Button size="lg" disabled={processing}>
                        {processing ? 'Saving...' : editing ? 'Save changes' : 'Add property'}
                    </Button>
                    <Button asChild size="lg" variant="outline">
                        <Link href={editing ? route('properties.show', property.id) : route('properties.index')}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
