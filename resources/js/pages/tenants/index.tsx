import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import TenantFormDialog, { type TenantFormData } from '@/components/tenant-form-dialog';
import TenantStatusBadge, { NoEmailBadge } from '@/components/tenant-status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { useUrlModal } from '@/hooks/use-url-modal';
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
    form: TenantFormData | null;
}

export default function Index({ tenants, filters, properties, canCreate, form }: Props) {
    const { flash } = usePage<SharedData>().props;
    const [creating, setCreating] = useUrlModal('create');
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
                <PageHeader
                    icon={Users}
                    title="Tenants"
                    description="The people who rent from you."
                    actions={
                        canCreate && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus /> Add tenant
                            </Button>
                        )
                    }
                />

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                <form
                    role="search"
                    onSubmit={(e) => e.preventDefault()}
                    className="surface grid gap-3 p-4 md:grid-cols-[2fr_1fr_1fr_auto] md:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="q">Search</Label>
                        <Input id="q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, email or mobile number" />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="property">Property</Label>
                        <SearchSelect
                            id="property"
                            value={filters.property ? String(filters.property) : ALL}
                            onChange={(v) => visit({ property: v })}
                            options={[{ value: ALL, label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Show</Label>
                        <SearchSelect
                            id="status"
                            value={filters.status || ALL}
                            onChange={(v) => visit({ status: v })}
                            options={[
                                { value: ALL, label: 'All current tenants' },
                                ...Object.entries(statusFilterLabels).map(([value, label]) => ({ value, label })),
                            ]}
                        />
                    </div>
                    {filtered && (
                        <Button type="button" variant="outline" onClick={clear}>
                            Clear filters
                        </Button>
                    )}
                </form>

                <p className="text-muted-foreground text-sm" aria-live="polite">
                    {tenants.total === 0 ? 'No tenants found' : `Showing ${tenants.from} to ${tenants.to} of ${tenants.total}`}
                </p>

                {tenants.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={filtered ? 'No tenants match' : 'No tenants yet'}
                        description={
                            filtered
                                ? 'Try a different spelling or clear the filters.'
                                : canCreate
                                  ? 'Add your first tenant. You can give them a room afterwards.'
                                  : 'No tenants have been added to your properties yet.'
                        }
                        action={
                            filtered ? (
                                <Button variant="outline" onClick={clear}>
                                    Clear filters
                                </Button>
                            ) : (
                                canCreate && (
                                    <Button onClick={() => setCreating(true)}>
                                        <Plus /> Add your first tenant
                                    </Button>
                                )
                            )
                        }
                    />
                ) : (
                    <>
                        <div className="surface hidden overflow-x-auto md:block">
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
                                                {t.mobile && <div className="text-muted-foreground font-normal">{t.mobile}</div>}
                                            </th>
                                            <td className="px-3 py-2">{t.property}</td>
                                            <td className="px-3 py-2">{t.room ?? '-'}</td>
                                            {t.paid_by ? (
                                                <td colSpan={2} className="text-muted-foreground px-3 py-2">
                                                    Resident (paid by {t.paid_by})
                                                </td>
                                            ) : (
                                                <>
                                                    <td className="px-3 py-2">{t.rent ?? '-'}</td>
                                                    <td className="px-3 py-2">{t.due_day ?? '-'}</td>
                                                </>
                                            )}
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
                                <li key={t.id} className="surface focus-within:ring-ring relative space-y-2 p-4 focus-within:ring-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <Link
                                            href={route('tenants.show', t.id)}
                                            className="font-medium after:absolute after:inset-0 focus-visible:outline-none"
                                        >
                                            {t.name}
                                        </Link>
                                        <TenantStatusBadge status={t.status} date={t.moving_in} />
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {t.property}
                                        {t.room && ` - ${t.room}`}
                                    </p>
                                    {t.paid_by && <p className="text-muted-foreground text-sm">Resident (paid by {t.paid_by})</p>}
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
            {form && <TenantFormDialog open={creating} onOpenChange={setCreating} form={form} />}
        </AppLayout>
    );
}
