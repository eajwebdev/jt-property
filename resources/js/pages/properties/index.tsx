import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { propertyTypeLabels } from '@/lib/property-labels';
import { type BreadcrumbItem, type PropertySummary, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Properties', href: '/properties' }];

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-xl font-semibold">{value}</dd>
        </div>
    );
}

export default function Index({ properties, canManage }: { properties: PropertySummary[]; canManage: boolean }) {
    const { flash } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Properties" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Properties</h1>
                        <p className="text-sm text-muted-foreground">Your buildings, their rooms and how full they are today.</p>
                    </div>
                    {canManage && (
                        <Button asChild size="lg">
                            <Link href={route('properties.create')}>
                                <Plus /> Add property
                            </Link>
                        </Button>
                    )}
                </div>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {properties.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <Building2 className="size-10 text-muted-foreground" aria-hidden />
                        <h2 className="text-lg font-medium">No properties yet</h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            {canManage
                                ? 'Add your first property, then add its rooms. You can add tenants once there are rooms.'
                                : 'You have not been given any properties yet. Ask the owner to assign you to one.'}
                        </p>
                        {canManage && (
                            <Button asChild size="lg">
                                <Link href={route('properties.create')}>Add your first property</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {properties.map((property) => (
                            <li key={property.id}>
                                <Card className="relative h-full transition-shadow focus-within:ring-2 focus-within:ring-ring hover:shadow-md">
                                    <CardHeader>
                                        <CardTitle className="text-lg">
                                            <Link href={route('properties.show', property.id)} className="after:absolute after:inset-0 focus-visible:outline-none">
                                                {property.name}
                                            </Link>
                                        </CardTitle>
                                        <p className="text-sm text-muted-foreground">
                                            {propertyTypeLabels[property.type] ?? property.type} &middot; {property.code}
                                        </p>
                                        <p className="text-sm">{property.address}</p>
                                    </CardHeader>
                                    <CardContent>
                                        <dl className="grid grid-cols-4 gap-2">
                                            <Stat label="Rooms" value={property.rooms} />
                                            <Stat label="Places" value={property.capacity} />
                                            <Stat label="Occupied" value={property.occupied} />
                                            <Stat label="Vacant" value={property.vacant} />
                                        </dl>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
