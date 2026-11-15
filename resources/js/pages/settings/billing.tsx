import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing settings', href: '/settings/billing' }];

type Method = 'bank' | 'gcash' | 'maya' | 'other';
type PaymentRow = { method: Method; label: string; account_name: string; account_number: string; notes: string };
type Policy = 'none' | 'fixed' | 'percent';

type Settings = {
    company_name: string;
    company_address: string;
    company_phone: string;
    company_email: string;
    payment_instructions: PaymentRow[];
    // Owner only:
    statement_notes?: string;
    auto_send_email?: boolean;
    auto_generate?: boolean;
    generate_lead_days?: number;
    reminder_days_before?: number;
    reminder_on_due?: boolean;
    reminder_days_after?: number;
    late_fee_policy?: Policy;
    late_fee_value?: number;
    late_fee_grace_days?: number;
};

const methodLabels: Record<Method, string> = { bank: 'Bank transfer', gcash: 'GCash', maya: 'Maya', other: 'Other' };

/** Stored centavos (fixed) or basis points (percent) back to what the owner types. */
function valueForInput(policy: Policy, stored: number): string {
    if (policy === 'none') return '';
    const whole = Math.floor(stored / 100);
    const rest = String(stored % 100).padStart(2, '0');
    return rest === '00' ? String(whole) : `${whole}.${rest}`;
}

const blankRow: PaymentRow = { method: 'bank', label: '', account_name: '', account_number: '', notes: '' };

function Field({ id, label, help, error, children }: { id: string; label: string; help?: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
            <InputError message={error} />
        </div>
    );
}

export default function Billing({ settings, canEdit }: { settings: Settings; canEdit: boolean }) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        company_name: settings.company_name,
        company_address: settings.company_address,
        company_phone: settings.company_phone,
        company_email: settings.company_email,
        payment_instructions: settings.payment_instructions,
        statement_notes: settings.statement_notes ?? '',
        auto_send_email: settings.auto_send_email ?? false,
        auto_generate: settings.auto_generate ?? false,
        generate_lead_days: String(settings.generate_lead_days ?? 7),
        reminder_days_before: String(settings.reminder_days_before ?? 3),
        reminder_on_due: settings.reminder_on_due ?? true,
        reminder_days_after: String(settings.reminder_days_after ?? 3),
        late_fee_policy: (settings.late_fee_policy ?? 'none') as Policy,
        late_fee_value: valueForInput(settings.late_fee_policy ?? 'none', settings.late_fee_value ?? 0),
        late_fee_grace_days: String(settings.late_fee_grace_days ?? 0),
    });

    // Laravel validation errors for nested fields come back keyed like "payment_instructions.0.label".
    const err = (key: string) => (errors as Record<string, string>)[key];
    const ro = !canEdit;

    const setRow = (i: number, patch: Partial<PaymentRow>) =>
        setData('payment_instructions', data.payment_instructions.map((r, n) => (n === i ? { ...r, ...patch } : r)));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('billing-settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing settings" />

            <SettingsLayout>
                <form onSubmit={submit} className="space-y-10">
                    {ro && <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">You can view these details. Only an owner can change them.</p>}

                    <section className="space-y-4">
                        <HeadingSmall title="Company" description="Shown on statements and emails." />
                        <Field id="company_name" label="Company name" error={err('company_name')}>
                            <Input id="company_name" value={data.company_name} disabled={ro} onChange={(e) => setData('company_name', e.target.value)} />
                        </Field>
                        <Field id="company_address" label="Address" error={err('company_address')}>
                            <Input id="company_address" value={data.company_address} disabled={ro} onChange={(e) => setData('company_address', e.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="company_phone" label="Phone" error={err('company_phone')}>
                                <Input id="company_phone" value={data.company_phone} disabled={ro} onChange={(e) => setData('company_phone', e.target.value)} />
                            </Field>
                            <Field id="company_email" label="Email" error={err('company_email')}>
                                <Input id="company_email" type="email" value={data.company_email} disabled={ro} onChange={(e) => setData('company_email', e.target.value)} />
                            </Field>
                        </div>
                    </section>

                    <section className="space-y-4">
                        <HeadingSmall title="Payment instructions" description="Tenants see these on their statements: where to send payment." />
                        {data.payment_instructions.length === 0 && <p className="text-sm text-muted-foreground">No payment methods added yet.</p>}
                        {data.payment_instructions.map((row, i) => (
                            <div key={i} className="space-y-3 rounded-md border p-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field id={`pi-method-${i}`} label="Method" error={err(`payment_instructions.${i}.method`)}>
                                        <Select value={row.method} disabled={ro} onValueChange={(v) => setRow(i, { method: v as Method })}>
                                            <SelectTrigger id={`pi-method-${i}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(Object.keys(methodLabels) as Method[]).map((m) => (
                                                    <SelectItem key={m} value={m}>
                                                        {methodLabels[m]}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                    <Field id={`pi-label-${i}`} label="Label" help="For example: BDO savings" error={err(`payment_instructions.${i}.label`)}>
                                        <Input id={`pi-label-${i}`} value={row.label} disabled={ro} onChange={(e) => setRow(i, { label: e.target.value })} />
                                    </Field>
                                    <Field id={`pi-name-${i}`} label="Account name" error={err(`payment_instructions.${i}.account_name`)}>
                                        <Input id={`pi-name-${i}`} value={row.account_name} disabled={ro} onChange={(e) => setRow(i, { account_name: e.target.value })} />
                                    </Field>
                                    <Field id={`pi-number-${i}`} label="Account or mobile number" error={err(`payment_instructions.${i}.account_number`)}>
                                        <Input id={`pi-number-${i}`} value={row.account_number} disabled={ro} onChange={(e) => setRow(i, { account_number: e.target.value })} />
                                    </Field>
                                </div>
                                <Field id={`pi-notes-${i}`} label="Notes (optional)" error={err(`payment_instructions.${i}.notes`)}>
                                    <Input id={`pi-notes-${i}`} value={row.notes} disabled={ro} onChange={(e) => setRow(i, { notes: e.target.value })} />
                                </Field>
                                {canEdit && (
                                    <Button type="button" variant="outline" size="sm" onClick={() => setData('payment_instructions', data.payment_instructions.filter((_, n) => n !== i))}>
                                        Remove
                                    </Button>
                                )}
                            </div>
                        ))}
                        {canEdit && (
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('payment_instructions', [...data.payment_instructions, { ...blankRow }])}>
                                Add payment method
                            </Button>
                        )}
                    </section>

                    {canEdit && (
                        <>
                            <section className="space-y-4">
                                <HeadingSmall title="Statement note" description="Printed at the bottom of every statement." />
                                <Field id="statement_notes" label="Note" error={err('statement_notes')}>
                                    <Input id="statement_notes" value={data.statement_notes} onChange={(e) => setData('statement_notes', e.target.value)} />
                                </Field>
                            </section>

                            <section className="space-y-4">
                                <HeadingSmall title="Billing & email automation" description="Automatic sending stays off until you've tested your email settings." />
                                <div className="flex items-start gap-3">
                                    <Checkbox id="auto_generate" checked={data.auto_generate} onCheckedChange={(v) => setData('auto_generate', v === true)} />
                                    <div className="grid gap-1">
                                        <Label htmlFor="auto_generate">Create bills automatically</Label>
                                        <p className="text-xs text-muted-foreground">Draft bills are created for you ahead of each billing date. You still review them before they go out.</p>
                                    </div>
                                </div>
                                <InputError message={err('auto_generate')} />
                                <Field id="generate_lead_days" label="Create bills this many days before the billing date" help="0 to 31 days." error={err('generate_lead_days')}>
                                    <Input id="generate_lead_days" type="number" min={0} max={31} value={data.generate_lead_days} onChange={(e) => setData('generate_lead_days', e.target.value)} />
                                </Field>
                                <div className="flex items-start gap-3">
                                    <Checkbox id="auto_send_email" checked={data.auto_send_email} onCheckedChange={(v) => setData('auto_send_email', v === true)} />
                                    <div className="grid gap-1">
                                        <Label htmlFor="auto_send_email">Email bills to tenants automatically</Label>
                                        <p className="text-xs text-muted-foreground">Automatic sending stays off until you've tested your email settings.</p>
                                    </div>
                                </div>
                                <InputError message={err('auto_send_email')} />
                            </section>

                            <section className="space-y-4">
                                <HeadingSmall title="Reminders & late fees" description="Reminders go out only when automatic email is on." />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field id="reminder_days_before" label="Remind this many days before due" help="0 turns it off. Up to 31." error={err('reminder_days_before')}>
                                        <Input id="reminder_days_before" type="number" min={0} max={31} value={data.reminder_days_before} onChange={(e) => setData('reminder_days_before', e.target.value)} />
                                    </Field>
                                    <Field id="reminder_days_after" label="Remind this many days after due" help="0 turns it off. Up to 60." error={err('reminder_days_after')}>
                                        <Input id="reminder_days_after" type="number" min={0} max={60} value={data.reminder_days_after} onChange={(e) => setData('reminder_days_after', e.target.value)} />
                                    </Field>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Checkbox id="reminder_on_due" checked={data.reminder_on_due} onCheckedChange={(v) => setData('reminder_on_due', v === true)} />
                                    <Label htmlFor="reminder_on_due">Also remind on the due date</Label>
                                </div>
                                <InputError message={err('reminder_on_due')} />

                                <Field id="late_fee_policy" label="Late fee" help="Off by default. Late fees are never added without this being on." error={err('late_fee_policy')}>
                                    <Select value={data.late_fee_policy} onValueChange={(v) => setData('late_fee_policy', v as Policy)}>
                                        <SelectTrigger id="late_fee_policy">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">No late fee</SelectItem>
                                            <SelectItem value="fixed">Fixed amount</SelectItem>
                                            <SelectItem value="percent">Percentage of the unpaid amount</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                                {data.late_fee_policy !== 'none' && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id="late_fee_value"
                                            label={data.late_fee_policy === 'fixed' ? 'Late fee amount (pesos)' : 'Late fee (percent)'}
                                            help={data.late_fee_policy === 'fixed' ? 'For example 500 or 1,500.50.' : 'For example 2 or 2.5, up to 100.'}
                                            error={err('late_fee_value')}
                                        >
                                            <Input id="late_fee_value" inputMode="decimal" value={data.late_fee_value} onChange={(e) => setData('late_fee_value', e.target.value)} />
                                        </Field>
                                        <Field id="late_fee_grace_days" label="Grace days after due date" help="No fee is charged within these days." error={err('late_fee_grace_days')}>
                                            <Input id="late_fee_grace_days" type="number" min={0} max={60} value={data.late_fee_grace_days} onChange={(e) => setData('late_fee_grace_days', e.target.value)} />
                                        </Field>
                                    </div>
                                )}
                            </section>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save settings</Button>
                                {recentlySuccessful && <p className="text-sm text-muted-foreground">Saved</p>}
                            </div>
                        </>
                    )}
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}