import { ChangeRentDialog, MoveOutDialog, MoveOutResidentDialog, RecordDepositDialog, TransferDialog } from '@/components/agreement-action-dialogs';
import StartRentalDialog, { type RentalOptions } from '@/components/start-rental-dialog';
import TenantStatusBadge, { NoEmailBadge } from '@/components/tenant-status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { agreementStatusLabels, deliveryLabels, depositStatusLabels, formatDate } from '@/lib/tenant-labels';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRightLeft, Banknote, DoorOpen, KeyRound, Pencil, PiggyBank } from 'lucide-react';
import { useState } from 'react';

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
    open: boolean;
    whole: boolean;
    replaced: boolean;
    current_rent_cents: number | null;
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
    occupancies: { id: number; room: string; move_in_date: string; move_out_date: string | null; is_payer: boolean; open: boolean }[];
    canEdit: boolean;
    canArchive: boolean;
    canStartRental: boolean;
    rentalOptions: RentalOptions | null;
    canManageRentals: boolean;
    today: string;
}

type Action = { kind: 'rent' | 'move' | 'transfer'; agreement: Agreement } | { kind: 'deposit'; account: Account };

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

export default function Show({ tenant, accounts, occupancies, canEdit, canArchive, canStartRental, rentalOptions, canManageRentals, today }: Props) {
    const [rentalOpen, setRentalOpen] = useState(false);
    // The last chosen action stays mounted while the dialog closes so its exit animation can play.
    const [action, setAction] = useState<Action | null>(null);
    const [actionOpen, setActionOpen] = useState(false);
    const [resident, setResident] = useState<Props['occupancies'][number] | null>(null);
    const [residentOpen, setResidentOpen] = useState(false);
    const openAction = (a: Action) => {
        setAction(a);
        setActionOpen(true);
    };
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
                        <p className="text-muted-foreground text-sm">{tenant.property}</p>
                        {tenant.archived_at && <TenantStatusBadge status="archived" date={tenant.archived_at} />}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canStartRental && rentalOptions && (
                            <Button size="lg" onClick={() => setRentalOpen(true)}>
                                <KeyRound /> Start rental
                            </Button>
                        )}
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
                        <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No rental yet</p>
                    ) : (
                        accounts.map((account) => (
                            <Card key={account.id}>
                                <CardHeader>
                                    <CardTitle className="text-base">Account {account.account_no}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {account.agreements.length === 0 && <p className="text-muted-foreground text-sm">No rental yet</p>}
                                    {account.agreements.map((a) => (
                                        <div key={a.id} className="space-y-2 rounded-lg border p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="font-medium">Room {a.room}</p>
                                                <Badge variant="outline">{agreementStatusLabels[a.status] ?? a.status}</Badge>
                                            </div>
                                            <p className="text-muted-foreground text-sm">
                                                From {formatDate(a.start_date)}
                                                {a.end_date && ` to ${formatDate(a.end_date)}`}, due on day {a.due_day} of the month
                                            </p>
                                            {a.replaced && (
                                                <p className="text-muted-foreground text-sm">
                                                    Moved to another room. Changes are made on the new rental.
                                                </p>
                                            )}
                                            {canManageRentals && a.open && !a.replaced && (
                                                <div className="flex flex-wrap gap-2">
                                                    <Button variant="outline" size="sm" onClick={() => openAction({ kind: 'rent', agreement: a })}>
                                                        <Banknote /> Change rent
                                                    </Button>
                                                    {rentalOptions && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openAction({ kind: 'transfer', agreement: a })}
                                                        >
                                                            <ArrowRightLeft /> Transfer
                                                        </Button>
                                                    )}
                                                    <Button variant="outline" size="sm" onClick={() => openAction({ kind: 'move', agreement: a })}>
                                                        <DoorOpen /> Move out
                                                    </Button>
                                                </div>
                                            )}
                                            <table className="w-full text-sm">
                                                <caption className="sr-only">Rent history for room {a.room}</caption>
                                                <thead className="text-muted-foreground text-left">
                                                    <tr>
                                                        <th scope="col" className="py-1 pr-3 font-medium">
                                                            Rent
                                                        </th>
                                                        <th scope="col" className="py-1 pr-3 font-medium">
                                                            From
                                                        </th>
                                                        <th scope="col" className="py-1 font-medium">
                                                            Reason
                                                        </th>
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
                                        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                                            <h3 className="text-sm font-medium">Deposits</h3>
                                            {canManageRentals && (
                                                <Button variant="outline" size="sm" onClick={() => openAction({ kind: 'deposit', account })}>
                                                    <PiggyBank /> Record deposit
                                                </Button>
                                            )}
                                        </div>
                                        {account.deposits.length === 0 ? (
                                            <p className="text-muted-foreground text-sm">No deposit recorded</p>
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
                        <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">No rental yet</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {occupancies.map((o) => (
                                <li key={o.id} className="flex flex-wrap justify-between gap-2 p-3 text-sm">
                                    <span className="font-medium">
                                        Room {o.room}
                                        {o.is_payer && <span className="text-muted-foreground font-normal"> (pays the rent)</span>}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(o.move_in_date)} to {o.move_out_date ? formatDate(o.move_out_date) : 'now'}
                                    </span>
                                    {canManageRentals && o.open && !o.is_payer && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setResident(o);
                                                setResidentOpen(true);
                                            }}
                                        >
                                            <DoorOpen /> Move out
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {canStartRental && rentalOptions && (
                <StartRentalDialog
                    tenantId={tenant.id}
                    tenantName={tenant.full_name}
                    options={rentalOptions}
                    open={rentalOpen}
                    onOpenChange={setRentalOpen}
                />
            )}

            {canManageRentals && resident && (
                <MoveOutResidentDialog
                    occupancy={{ id: resident.id, room: resident.room, move_in_date: resident.move_in_date, tenant: tenant.full_name }}
                    open={residentOpen}
                    onOpenChange={setResidentOpen}
                    today={today}
                />
            )}
            {canManageRentals && action?.kind === 'rent' && (
                <ChangeRentDialog agreement={action.agreement} open={actionOpen} onOpenChange={setActionOpen} today={today} />
            )}
            {canManageRentals && action?.kind === 'move' && (
                <MoveOutDialog agreement={action.agreement} open={actionOpen} onOpenChange={setActionOpen} today={today} />
            )}
            {canManageRentals && action?.kind === 'transfer' && rentalOptions && (
                <TransferDialog agreement={action.agreement} options={rentalOptions} open={actionOpen} onOpenChange={setActionOpen} today={today} />
            )}
            {canManageRentals && action?.kind === 'deposit' && (
                <RecordDepositDialog
                    accountId={action.account.id}
                    accountNo={action.account.account_no}
                    agreements={action.account.agreements}
                    open={actionOpen}
                    onOpenChange={setActionOpen}
                    today={today}
                />
            )}
        </AppLayout>
    );
}
