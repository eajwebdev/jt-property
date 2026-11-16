import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { Textarea } from '@/components/ui/textarea';
import { propertyTypeLabels } from '@/lib/property-labels';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export interface PropertyFormData {
    property: {
        id: number;
        name: string;
        code: string;
        type: string;
        address: string;
        dti_registration_no: string | null;
        business_permit_no: string | null;
        description: string | null;
        amenities: string | null;
        auto_send_email: boolean;
        show_on_website: boolean;
        has_billing: boolean;
    } | null;
    staff_ids: number[];
    types: string[];
    staff: { id: number; name: string }[];
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: PropertyFormData;
}

/** Add or edit a property in a modal. Server rules (StorePropertyRequest) decide what is valid. */
export default function PropertyFormDialog({ open, onOpenChange, form }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">{open && <PropertyForm form={form} onDone={() => onOpenChange(false)} />}</DialogContent>
        </Dialog>
    );
}

function PropertyForm({ form: { property, staff_ids, types, staff }, onDone }: { form: PropertyFormData; onDone: () => void }) {
    const editing = property !== null;
    const { data, setData, post, put, processing, errors } = useForm({
        name: property?.name ?? '',
        code: property?.code ?? '',
        type: property?.type ?? 'boarding_house',
        address: property?.address ?? '',
        dti_registration_no: property?.dti_registration_no ?? '',
        business_permit_no: property?.business_permit_no ?? '',
        description: property?.description ?? '',
        amenities: property?.amenities ?? '',
        auto_send_email: property?.auto_send_email ?? false,
        show_on_website: property?.show_on_website ?? false,
        staff_ids,
    });

    const toggleStaff = (id: number, checked: boolean) =>
        setData('staff_ids', checked ? [...data.staff_ids, id] : data.staff_ids.filter((s) => s !== id));
    const codeLocked = editing && property.has_billing;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (editing) put(route('properties.update', property.id), options);
        else post(route('properties.store'), options);
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <DialogHeader>
                <DialogTitle>{editing ? `Edit ${property.name}` : 'Add a property'}</DialogTitle>
                <DialogDescription>
                    {editing ? 'Changes apply to new bills and statements.' : 'After saving you can add its rooms.'}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
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
                        className="font-mono uppercase"
                        aria-describedby="code-help"
                        readOnly={codeLocked}
                        required
                    />
                    <InputError message={errors.code} />
                </div>
                <p id="code-help" className="text-muted-foreground -mt-2 text-xs sm:col-span-2">
                    Code: 2 to 10 capital letters or numbers, like RIV. It appears on every invoice number
                    {codeLocked ? ' and can no longer be changed.' : ', so choose carefully.'}
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="type">Property type</Label>
                <SearchSelect
                    id="type"
                    value={data.type}
                    onChange={(value) => setData('type', value)}
                    options={types.map((type) => ({ value: type, label: propertyTypeLabels[type] ?? type }))}
                />
                <InputError message={errors.type} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address">Address</Label>
                <Textarea id="address" rows={2} value={data.address} onChange={(e) => setData('address', e.target.value)} required />
                <InputError message={errors.address} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="dti_registration_no">DTI registration no.</Label>
                    <Input
                        id="dti_registration_no"
                        value={data.dti_registration_no}
                        onChange={(e) => setData('dti_registration_no', e.target.value)}
                        placeholder="Optional"
                    />
                    <InputError message={errors.dti_registration_no} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="business_permit_no">Business (Mayor's) permit no.</Label>
                    <Input
                        id="business_permit_no"
                        value={data.business_permit_no}
                        onChange={(e) => setData('business_permit_no', e.target.value)}
                        placeholder="Optional"
                    />
                    <InputError message={errors.business_permit_no} />
                </div>
                <p className="text-muted-foreground -mt-2 text-xs sm:col-span-2">Printed on the Statement of Accounts header.</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="amenities">What is included?</Label>
                    <Textarea
                        id="amenities"
                        placeholder="Optional: water, wifi, parking"
                        value={data.amenities}
                        onChange={(e) => setData('amenities', e.target.value)}
                    />
                    <InputError message={errors.amenities} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="description">Notes</Label>
                    <Textarea
                        id="description"
                        placeholder="Optional"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    <InputError message={errors.description} />
                </div>
            </div>

            <div className="bg-muted/40 flex items-start gap-3 rounded-xl border p-4">
                <Checkbox
                    id="show_on_website"
                    checked={data.show_on_website}
                    onCheckedChange={(checked) => setData('show_on_website', checked === true)}
                />
                <div>
                    <Label htmlFor="show_on_website">Show on the public website</Label>
                    <p className="text-muted-foreground text-sm">
                        Visitors see its name, address, photos, what is included and how many places are free. Never tenant details.
                    </p>
                    <InputError message={errors.show_on_website} />
                </div>
            </div>

            <div className="bg-muted/40 flex items-start gap-3 rounded-xl border p-4">
                <Checkbox
                    id="auto_send_email"
                    checked={data.auto_send_email}
                    onCheckedChange={(checked) => setData('auto_send_email', checked === true)}
                />
                <div>
                    <Label htmlFor="auto_send_email">Email new bills automatically for this property</Label>
                    <p className="text-muted-foreground text-sm">
                        Also requires the master email switch in Billing Settings. Both switches start off.
                    </p>
                    <InputError message={errors.auto_send_email} />
                </div>
            </div>

            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-medium">Staff who can work on this property</legend>
                {staff.length === 0 ? (
                    <p className="text-muted-foreground text-sm">You have no staff users yet. Add one under Settings, Users.</p>
                ) : (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {staff.map((member) => (
                            <label
                                key={member.id}
                                htmlFor={`staff-${member.id}`}
                                className="hover:bg-muted/50 flex items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors"
                            >
                                <Checkbox
                                    id={`staff-${member.id}`}
                                    checked={data.staff_ids.includes(member.id)}
                                    onCheckedChange={(checked) => toggleStaff(member.id, checked === true)}
                                />
                                {member.name}
                            </label>
                        ))}
                    </div>
                )}
                <InputError message={errors.staff_ids} />
            </fieldset>

            <DialogFooter className="bg-card sticky -bottom-6 -mx-6 -mb-6 border-t px-6 py-4">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button disabled={processing}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    {editing ? 'Save changes' : 'Add property'}
                </Button>
            </DialogFooter>
        </form>
    );
}
