import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import PropertyFormDialog, { type PropertyFormData } from '@/components/property-form-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useUrlModal } from '@/hooks/use-url-modal';
import AppLayout from '@/layouts/app-layout';
import { propertyTypeLabels } from '@/lib/property-labels';
import { type BreadcrumbItem, type PropertySummary, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Building2, Globe, ImageOff, MapPin, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Properties', href: '/properties' }];

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="font-mono text-lg font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

export default function Index({ properties, canManage, form }: { properties: PropertySummary[]; canManage: boolean; form: PropertyFormData | null }) {
    const { flash } = usePage<SharedData>().props;
    const [creating, setCreating] = useUrlModal('create');
    const add = (label: string) => (
        <Button onClick={() => setCreating(true)}>
            <Plus /> {label}
        </Button>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Properties" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Building2}
                    title="Properties"
                    description="Your buildings, their rooms and how full they are today."
                    actions={canManage && add('Add property')}
                />

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {properties.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No properties yet"
                        description={
                            canManage
                                ? 'Add your first property, then add its rooms. You can add tenants once there are rooms.'
                                : 'You have not been given any properties yet. Ask the owner to assign you to one.'
                        }
                        action={canManage && add('Add your first property')}
                    />
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {properties.map((property) => {
                            const rate = property.capacity > 0 ? Math.round((property.occupied / property.capacity) * 100) : 0;
                            return (
                                <li key={property.id}>
                                    <article className="surface group focus-within:ring-ring hover:shadow-lift relative flex h-full flex-col gap-4 overflow-hidden p-5 transition-shadow duration-200 focus-within:ring-2">
                                        <div className="bg-muted relative -mx-5 -mt-5 aspect-[16/9] overflow-hidden border-b">
                                            {property.cover ? (
                                                <img
                                                    src={property.cover}
                                                    alt=""
                                                    loading="lazy"
                                                    className="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                                                />
                                            ) : (
                                                <div className="text-muted-foreground grid size-full place-items-center">
                                                    <ImageOff className="size-8" aria-hidden />
                                                </div>
                                            )}
                                            {property.show_on_website && (
                                                <Badge variant="success" className="bg-card/90 absolute top-3 left-3 backdrop-blur">
                                                    <Globe className="size-3" aria-hidden /> On website
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <h2 className="truncate text-base font-semibold">
                                                    <Link
                                                        href={route('properties.show', property.id)}
                                                        className="after:absolute after:inset-0 focus-visible:outline-none"
                                                    >
                                                        {property.name}
                                                    </Link>
                                                </h2>
                                                <p className="text-muted-foreground mt-1 flex items-start gap-1 text-sm">
                                                    <MapPin className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                                    <span className="line-clamp-2">{property.address}</span>
                                                </p>
                                            </div>
                                            <ArrowUpRight
                                                className="text-muted-foreground group-hover:text-primary size-4 shrink-0 transition-colors"
                                                aria-hidden
                                            />
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Badge variant="muted">{propertyTypeLabels[property.type] ?? property.type}</Badge>
                                            <Badge variant="outline" className="font-mono">
                                                {property.code}
                                            </Badge>
                                        </div>
                                        <div className="mt-auto space-y-3">
                                            <div>
                                                <div className="mb-1 flex justify-between text-xs">
                                                    <span className="text-muted-foreground">Occupancy</span>
                                                    <span className="font-mono font-medium tabular-nums">{rate}%</span>
                                                </div>
                                                <div
                                                    className="bg-muted h-2 overflow-hidden rounded-full"
                                                    role="progressbar"
                                                    aria-valuenow={rate}
                                                    aria-valuemin={0}
                                                    aria-valuemax={100}
                                                    aria-label={`${property.name} occupancy`}
                                                >
                                                    <div
                                                        className="bg-primary h-full rounded-full transition-[width] duration-300"
                                                        style={{ width: `${rate}%` }}
                                                    />
                                                </div>
                                            </div>
                                            <dl className="grid grid-cols-4 gap-2 border-t pt-3">
                                                <Stat label="Rooms" value={property.rooms} />
                                                <Stat label="Places" value={property.capacity} />
                                                <Stat label="Occupied" value={property.occupied} />
                                                <Stat label="Vacant" value={property.vacant} />
                                            </dl>
                                        </div>
                                    </article>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
            {form && <PropertyFormDialog open={creating} onOpenChange={setCreating} form={form} />}
        </AppLayout>
    );
}
