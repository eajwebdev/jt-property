import TenantStatusBadge, { NoEmailBadge } from '@/components/tenant-status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { statusFilterLabels } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type Paginated, type SharedData, type TenantRow } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tenants', href: '/tenants' }];
const ALL = 'all';

interface Props {
    tenants: Paginated<TenantRow>;
    filters: { q: string; status: string; property: number | null };
    properties: { id: number; name: string }[];
    canCreate: boolean;
}

export default function Index({ tenants, filters, properties, canCreate }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);

    const visit = (next: Partial<{ q: string; status: string; property: string }>) => {
        const params = {
            q: filters.q,
            status: filters.status,
            property: filters.property ? String(filters.property) : '',
            ...next,
        };
        const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== ALL));
        router.get(route('tenants.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Wait for a pause in typing before searching.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = setTimeout(() => visit({ q }), 300);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const filtered = filters.q !== '' || filters.status !== '' || filters.property !== null;
    const clear = () => {
        setQ('');
        router.get(route('tenants.index'), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tenants" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Tenants</h1>
                        <p className="text-sm text-muted-foreground">The people who rent from you.</p>
                    </div>
                    {canCreate && (
                        <Button asChild size="lg">
                            <Link href={route('tenants.create')}>
                                <Plus /> Add tenant
                            </Link>
                        </Button>
                    )}
                </div>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <form role="search" onSubmit={(e) => e.preventDefault()} className="grid gap-3 md:grid-cols-[2fr_1fr_1fr_auto] md:items-end">
                    <div className="grid gap-2">
                        <Label htmlFor="q">Search</Label>
                        <Input id="q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, email or mobile number" />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="property">Property</Label>
                        <Select value={filters.property ? String(filters.property) : ALL} onValueChange={(v) => visit({ property: v })}>
                            <SelectTrigger id="property">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All properties</SelectItem>
                                {properties.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Show</Label>
                        <Select value={filters.status || ALL} onValueChange={(v) => visit({ status: v })}>
                            <SelectTrigger id="status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All current tenants</SelectItem>
                                {Object.entries(statusFilterLabels).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {filtered && (
                        <Button type="button" variant="outline" onClick={clear}>
                            Clear filters
                        </Button>
                    )}
                </form>

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {tenants.total === 0 ? 'No tenants found' : `Showing ${tenants.from} to ${tenants.to} of ${tenants.total}`}
                </p>

                {tenants.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <Users className="size-10 text-muted-foreground" aria-hidden />
                        <h2 className="text-lg font-medium">{filtered ? 'No tenants match' : 'No tenants yet'}</h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            {filtered
                                ? 'Try a different spelling or clear the filters.'
                                : canCreate
                                  ? 'Add your first tenant. You can give them a room afterwards.'
                                  : 'No tenants have been added to your properties yet.'}
                        </p>
                        {filtered ? (
                            <Button variant="outline" onClick={clear}>
                                Clear filters
                            </Button>
                        ) : (
                            canCreate && (
                                <Button asChild size="lg">
                                    <Link href={route('tenants.create')}>Add your first tenant</Link>
                                </Button>
                            )
                        )}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Tenants</caption>
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        {['Name', 'Property', 'Room', 'Rent now', 'Due day', 'Email', 'Status'].map((h) => (
                                            <th key={h} scope="col" className="px-3 py-2 font-medium">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {tenants.data.map((t) => (
                                        <tr key={t.id} className="border-t">
                                            <th scope="row" className="px-3 py-2 text-left font-medium">
                                                <Link href={route('tenants.show', t.id)} className="underline-offset-4 hover:underline">
                                                    {t.name}
                                                </Link>
                                                {t.mobile && <div className="font-normal text-muted-foreground">{t.mobile}</div>}
                                            </th>
                                            <td className="px-3 py-2">{t.property}</td>
                                            <td className="px-3 py-2">{t.room ?? '-'}</td>
                                            <td className="px-3 py-2">{t.rent ?? '-'}</td>
                                            <td className="px-3 py-2">{t.due_day ?? '-'}</td>
                                            <td className="px-3 py-2">{t.email ?? <NoEmailBadge />}</td>
                                            <td className="px-3 py-2">
                                                <TenantStatusBadge status={t.status} date={t.moving_in} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-3 md:hidden">
                            {tenants.data.map((t) => (
                                <li key={t.id} className="relative space-y-2 rounded-lg border p-4 focus-within:ring-2 focus-within:ring-ring">
                                    <div className="flex items-start justify-between gap-2">
                                        <Link href={route('tenants.show', t.id)} className="font-medium after:absolute after:inset-0 focus-visible:outline-none">
                                            {t.name}
                                        </Link>
                                        <TenantStatusBadge status={t.status} date={t.moving_in} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {t.property}
                                        {t.room && ` - ${t.room}`}
                                    </p>
                                    {t.rent && (
                                        <p className="text-sm">
                                            {t.rent} a month{t.due_day && `, due on day ${t.due_day}`}
                                        </p>
                                    )}
                                    <div className="text-sm">{t.email ?? <NoEmailBadge />}</div>
                                </li>
                            ))}
                        </ul>

                        {(tenants.prev_page_url || tenants.next_page_url) && (
                            <nav aria-label="Pages" className="flex justify-between gap-3">
                                {tenants.prev_page_url ? (
                                    <Button asChild variant="outline">
                                        <Link href={tenants.prev_page_url} preserveScroll>
                                            Previous
                                        </Link>
                                    </Button>
                                ) : (
                                    <span />
                                )}
                                {tenants.next_page_url && (
                                    <Button asChild variant="outline">
                                        <Link href={tenants.next_page_url} preserveScroll>
                                            Next
                                        </Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
