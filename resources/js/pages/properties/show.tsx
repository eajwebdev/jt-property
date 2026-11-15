import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import UnitDialog from '@/components/unit-dialog';
import UnitStatusBadge from '@/components/unit-status-badge';
import AppLayout from '@/layouts/app-layout';
import { propertyTypeLabels, rentModeLabels } from '@/lib/property-labels';
import { type BreadcrumbItem, type PropertyDetail, type SharedData, type UnitRow } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { BedDouble, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

interface Props {
    property: PropertyDetail;
    units: UnitRow[];
    staff: { id: number; name: string }[];
    canManage: boolean;
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

export default function Show({ property, units, staff, canManage }: Props) {
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
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{property.name}</h1>
                        <p className="text-sm text-muted-foreground">
                            {propertyTypeLabels[property.type] ?? property.type} &middot; {property.code}
                        </p>
                        <p className="mt-1 text-sm">{property.address}</p>
                        {canManage && (
                            <p className="mt-1 text-sm text-muted-foreground">Staff: {staff.length ? staff.map((s) => s.name).join(', ') : 'none assigned'}</p>
                        )}
                    </div>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="lg">
                                <Link href={route('properties.edit', property.id)}>
                                    <Pencil /> Edit property
                                </Link>
                            </Button>
                            <Button size="lg" onClick={() => edit(null)}>
                                <Plus /> Add room
                            </Button>
                        </div>
                    )}
                </div>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {units.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <BedDouble className="size-10 text-muted-foreground" aria-hidden />
                        <h2 className="text-lg font-medium">No rooms yet</h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            {canManage ? 'Add the rooms people can rent. Tenants move into rooms.' : 'The owner has not added any rooms here yet.'}
                        </p>
                        {canManage && (
                            <Button size="lg" onClick={() => edit(null)}>
                                Add the first room
                            </Button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Rooms in {property.name}</caption>
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 font-medium">Room</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Building / floor</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Rent</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Occupied</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Who lives here</th>
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
                                            <th scope="row" className="px-3 py-2 text-left font-medium">{unit.code}</th>
                                            <td className="px-3 py-2">{place(unit)}</td>
                                            <td className="px-3 py-2">{rentModeLabels[unit.rent_mode]}</td>
                                            <td className="px-3 py-2">{unit.occupied} of {unit.capacity}</td>
                                            <td className="px-3 py-2"><UnitStatusBadge status={unit.display_status} /></td>
                                            <td className="px-3 py-2"><Occupants unit={unit} /></td>
                                            {canManage && (
                                                <td className="px-3 py-2 text-right">
                                                    <Button variant="outline" size="sm" onClick={() => edit(unit)} aria-label={`Edit room ${unit.code}`}>
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
                                <li key={unit.id} className="space-y-2 rounded-lg border p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <h2 className="text-lg font-semibold">Room {unit.code}</h2>
                                        <UnitStatusBadge status={unit.display_status} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {place(unit)} &middot; {rentModeLabels[unit.rent_mode]} &middot; {unit.occupied} of {unit.capacity} occupied
                                    </p>
                                    <div className="text-sm"><Occupants unit={unit} /></div>
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

            {canManage && (
                <UnitDialog propertyId={property.id} unit={dialog.unit} open={dialog.open} onOpenChange={(open) => setDialog((d) => ({ ...d, open }))} />
            )}
        </AppLayout>
    );
}
