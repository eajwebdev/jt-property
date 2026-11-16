import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import PropertyFormDialog, { type PropertyFormData } from '@/components/property-form-dialog';
import PropertyPhotos from '@/components/property-photos';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import UnitDialog from '@/components/unit-dialog';
import UnitStatusBadge from '@/components/unit-status-badge';
import { useUrlModal } from '@/hooks/use-url-modal';
import AppLayout from '@/layouts/app-layout';
import { propertyTypeLabels, rentModeLabels } from '@/lib/property-labels';
import { type BreadcrumbItem, type PropertyDetail, type PropertyPhoto, type SharedData, type UnitRow } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { BedDouble, Building2, ExternalLink, Globe, MapPin, Pencil, Plus, Users } from 'lucide-react';
import { useState } from 'react';

interface Props {
    property: PropertyDetail;
    units: UnitRow[];
    staff: { id: number; name: string }[];
    canManage: boolean;
    form: PropertyFormData | null;
    photos: PropertyPhoto[];
    maxPhotos: number;
}

function Occupants({ unit }: { unit: UnitRow }) {
    if (unit.occupants.length === 0) return <span className="text-muted-foreground">Nobody yet</span>;
    return (
        <ul className="space-y-0.5">
            {unit.occupants.map((o) => (
                <li key={o.id}>
                    {o.name}
                    {o.bed && <span className="text-muted-foreground"> ({o.bed})</span>}
                </li>
            ))}
        </ul>
    );
}

const place = (unit: UnitRow) => [unit.building, unit.floor && `Floor ${unit.floor}`].filter(Boolean).join(', ') || '-';

export default function Show({ property, units, staff, canManage, form, photos, maxPhotos }: Props) {
    const [editing, setEditing] = useUrlModal('edit');
    const { flash } = usePage<SharedData>().props;
    const [dialog, setDialog] = useState<{ open: boolean; unit: UnitRow | null }>({ open: false, unit: null });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Properties', href: '/properties' },
        { title: property.name, href: `/properties/${property.id}` },
    ];

    const edit = (unit: UnitRow | null) => setDialog({ open: true, unit });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={property.name} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <PageHeader
                    icon={Building2}
                    title={property.name}
                    description={
                        <span className="flex items-start gap-1">
                            <MapPin className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            {property.address}
                        </span>
                    }
                    actions={
                        canManage && (
                            <>
                                {property.show_on_website && (
                                    <Button asChild variant="ghost">
                                        <a href={route('listings.show', property.code)} target="_blank" rel="noopener noreferrer">
                                            <ExternalLink /> View public page
                                        </a>
                                    </Button>
                                )}
                                {form && (
                                    <Button variant="outline" onClick={() => setEditing(true)}>
                                        <Pencil /> Edit property
                                    </Button>
                                )}
                                <Button onClick={() => edit(null)}>
                                    <Plus /> Add room
                                </Button>
                            </>
                        )
                    }
                >
                    <div className="mt-3 flex flex-wrap items-center gap-1.5">
                        <Badge variant="muted">{propertyTypeLabels[property.type] ?? property.type}</Badge>
                        <Badge variant="outline" className="font-mono">
                            {property.code}
                        </Badge>
                        {property.show_on_website ? (
                            <Badge variant="success">
                                <Globe className="size-3" aria-hidden /> Listed on website
                            </Badge>
                        ) : (
                            <Badge variant="muted">
                                <Globe className="size-3" aria-hidden /> Not on website
                            </Badge>
                        )}
                        {canManage && (
                            <Badge variant="info">
                                <Users className="size-3" aria-hidden /> {staff.length ? staff.map((s) => s.name).join(', ') : 'No staff assigned'}
                            </Badge>
                        )}
                    </div>
                </PageHeader>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <PropertyPhotos propertyId={property.id} photos={photos} max={maxPhotos} canManage={canManage} listed={property.show_on_website} />

                <h2 className="text-lg font-semibold">Rooms</h2>
                {units.length === 0 ? (
                    <EmptyState
                        icon={BedDouble}
                        title="No rooms yet"
                        description={
                            canManage ? 'Add the rooms people can rent. Tenants move into rooms.' : 'The owner has not added any rooms here yet.'
                        }
                        action={
                            canManage && (
                                <Button onClick={() => edit(null)}>
                                    <Plus /> Add the first room
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        <div className="surface hidden overflow-x-auto md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Rooms in {property.name}</caption>
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Room
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Building / floor
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Rent
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Occupied
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Status
                                        </th>
                                        <th scope="col" className="px-3 py-2 font-medium">
                                            Who lives here
                                        </th>
                                        {canManage && (
                                            <th scope="col" className="px-3 py-2">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {units.map((unit) => (
                                        <tr key={unit.id} className="border-t align-top">
                                            <th scope="row" className="px-3 py-2 text-left font-medium">
                                                {unit.code}
                                            </th>
                                            <td className="px-3 py-2">{place(unit)}</td>
                                            <td className="px-3 py-2">{rentModeLabels[unit.rent_mode]}</td>
                                            <td className="px-3 py-2">
                                                {unit.occupied} of {unit.capacity}
                                            </td>
                                            <td className="px-3 py-2">
                                                <UnitStatusBadge status={unit.display_status} />
                                            </td>
                                            <td className="px-3 py-2">
                                                <Occupants unit={unit} />
                                            </td>
                                            {canManage && (
                                                <td className="px-3 py-2 text-right">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => edit(unit)}
                                                        aria-label={`Edit room ${unit.code}`}
                                                    >
                                                        Edit
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-3 md:hidden">
                            {units.map((unit) => (
                                <li key={unit.id} className="surface space-y-2 p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <h2 className="text-lg font-semibold">Room {unit.code}</h2>
                                        <UnitStatusBadge status={unit.display_status} />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {place(unit)} &middot; {rentModeLabels[unit.rent_mode]} &middot; {unit.occupied} of {unit.capacity} occupied
                                    </p>
                                    <div className="text-sm">
                                        <Occupants unit={unit} />
                                    </div>
                                    {canManage && (
                                        <Button variant="outline" onClick={() => edit(unit)} aria-label={`Edit room ${unit.code}`}>
                                            Edit room
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>

            {form && <PropertyFormDialog open={editing} onOpenChange={setEditing} form={form} />}
            {canManage && (
                <UnitDialog
                    propertyId={property.id}
                    unit={dialog.unit}
                    open={dialog.open}
                    onOpenChange={(open) => setDialog((d) => ({ ...d, open }))}
                />
            )}
        </AppLayout>
    );
}
