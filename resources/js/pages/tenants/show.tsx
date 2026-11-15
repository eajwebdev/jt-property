import TenantStatusBadge, { NoEmailBadge } from '@/components/tenant-status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { agreementStatusLabels, deliveryLabels, depositStatusLabels, formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface Rate {
    id: number;
    rent: string;
    effective_from: string;
    reason: string | null;
}
interface Agreement {
    id: number;
    room: string;
    due_day: number;
    start_date: string;
    end_date: string | null;
    status: string;
    rates: Rate[];
}
interface Deposit {
    id: number;
    amount: string;
    received_on: string;
    status: string;
    notes: string | null;
}
interface Account {
    id: number;
    account_no: string;
    status: string;
    agreements: Agreement[];
    deposits: Deposit[];
}
interface Props {
    tenant: {
        id: number;
        full_name: string;
        email: string | null;
        mobile: string | null;
        property: string;
        emergency_contact_name: string | null;
        emergency_contact_phone: string | null;
        preferred_delivery: string;
        email_opt_in: boolean;
        remarks: string | null;
        archived_at: string | null;
    };
    accounts: Account[];
    occupancies: { id: number; room: string; move_in_date: string; move_out_date: string | null; is_payer: boolean }[];
    canEdit: boolean;
    canArchive: boolean;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

export default function Show({ tenant, accounts, occupancies, canEdit, canArchive }: Props) {
    const { flash } = usePage<SharedData>().props;
    const errors = usePage().props.errors as Record<string, string>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tenants', href: '/tenants' },
        { title: tenant.full_name, href: `/tenants/${tenant.id}` },
    ];

    const archive = () => {
        if (confirm(`Archive ${tenant.full_name}? They will be hidden from the main list but their history is kept.`)) {
            router.patch(route('tenants.archive', tenant.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={tenant.full_name} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{tenant.full_name}</h1>
                        <p className="text-sm text-muted-foreground">{tenant.property}</p>
                        {tenant.archived_at && <TenantStatusBadge status="archived" date={tenant.archived_at} />}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canEdit && (
                            <Button asChild variant="outline" size="lg">
                                <Link href={route('tenants.edit', tenant.id)}>
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {canArchive && (
                            <Button variant="outline" size="lg" onClick={archive}>
                                Archive
                            </Button>
                        )}
                    </div>
                </div>

                {flash.success && (
                    <Alert role="status">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}
                {errors.archive && (
                    <Alert variant="destructive" role="alert">
                        <AlertDescription>{errors.archive}</AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Contact</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Field label="Mobile">{tenant.mobile ?? '-'}</Field>
                            <Field label="Email">{tenant.email ?? <NoEmailBadge />}</Field>
                            <Field label="Statements sent by">
                                {deliveryLabels[tenant.preferred_delivery] ?? tenant.preferred_delivery}
                                {!tenant.email_opt_in && ' (emails turned off)'}
                            </Field>
                            <Field label="Emergency contact">
                                {tenant.emergency_contact_name || tenant.emergency_contact_phone
                                    ? [tenant.emergency_contact_name, tenant.emergency_contact_phone].filter(Boolean).join(', ')
                                    : '-'}
                            </Field>
                            {tenant.remarks && <Field label="Notes">{tenant.remarks}</Field>}
                        </dl>
                    </CardContent>
                </Card>

                <section aria-labelledby="rentals" className="space-y-3">
                    <h2 id="rentals" className="text-lg font-semibold">
                        Rentals and rent history
                    </h2>
                    {accounts.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No rental yet</p>
                    ) : (
                        accounts.map((account) => (
                            <Card key={account.id}>
                                <CardHeader>
                                    <CardTitle className="text-base">Account {account.account_no}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {account.agreements.length === 0 && <p className="text-sm text-muted-foreground">No rental yet</p>}
                                    {account.agreements.map((a) => (
                                        <div key={a.id} className="space-y-2 rounded-lg border p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="font-medium">Room {a.room}</p>
                                                <Badge variant="outline">{agreementStatusLabels[a.status] ?? a.status}</Badge>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                From {formatDate(a.start_date)}
                                                {a.end_date && ` to ${formatDate(a.end_date)}`}, due on day {a.due_day} of the month
                                            </p>
                                            <table className="w-full text-sm">
                                                <caption className="sr-only">Rent history for room {a.room}</caption>
                                                <thead className="text-left text-muted-foreground">
                                                    <tr>
                                                        <th scope="col" className="py-1 pr-3 font-medium">Rent</th>
                                                        <th scope="col" className="py-1 pr-3 font-medium">From</th>
                                                        <th scope="col" className="py-1 font-medium">Reason</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {a.rates.map((r) => (
                                                        <tr key={r.id} className="border-t">
                                                            <td className="py-1 pr-3">{r.rent}</td>
                                                            <td className="py-1 pr-3">{formatDate(r.effective_from)}</td>
                                                            <td className="py-1">{r.reason ?? '-'}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ))}
                                    <div>
                                        <h3 className="mb-1 text-sm font-medium">Deposits</h3>
                                        {account.deposits.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No deposit recorded</p>
                                        ) : (
                                            <ul className="space-y-1 text-sm">
                                                {account.deposits.map((d) => (
                                                    <li key={d.id}>
                                                        {d.amount} received {formatDate(d.received_on)} - {depositStatusLabels[d.status] ?? d.status}
                                                        {d.notes && <span className="text-muted-foreground"> ({d.notes})</span>}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </section>

                <section aria-labelledby="stays" className="space-y-3">
                    <h2 id="stays" className="text-lg font-semibold">
                        Rooms lived in
                    </h2>
                    {occupancies.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No rental yet</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {occupancies.map((o) => (
                                <li key={o.id} className="flex flex-wrap justify-between gap-2 p-3 text-sm">
                                    <span className="font-medium">
                                        Room {o.room}
                                        {o.is_payer && <span className="font-normal text-muted-foreground"> (pays the rent)</span>}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(o.move_in_date)} to {o.move_out_date ? formatDate(o.move_out_date) : 'now'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
