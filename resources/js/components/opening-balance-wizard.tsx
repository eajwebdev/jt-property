import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchSelect } from '@/components/ui/search-select';
import { formatCents, parsePesos } from '@/lib/money';
import { useForm } from '@inertiajs/react';
import { Plus, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface WizardAccount {
    account_id: number;
    account_no: string;
    tenant: string;
    first_charge_date: string | null;
    account_status: string;
}

type Line = { month: string; category: 'rent' | 'electric' | 'other'; amount: string; note: string };

// The Statement of Accounts column each line lands in: BH (Monthly Due), EB (Actual Electric Consumption) or Others.
const CATEGORIES: { value: Line['category']; label: string }[] = [
    { value: 'rent', label: 'BH (rent)' },
    { value: 'electric', label: 'EB (electric)' },
    { value: 'other', label: 'Other' },
];

const STEPS = ['Amount & date', 'Monthly breakdown', 'Review'];

/** Amount & date -> optional per-month breakdown -> review -> approve. Nothing is posted until the last button. */
export function OpeningBalanceWizard({ account, today, onClose }: { account: WizardAccount | null; today: string; onClose: () => void }) {
    const form = useForm({ billing_account_id: 0, amount: '', as_of: today, notes: '', approve: true, breakdown: [] as Line[] });
    const errors = form.errors as Record<string, string | undefined>;
    const [step, setStep] = useState(0);
    const [lines, setLines] = useState<Line[]>([]);

    useEffect(() => {
        if (account) {
            form.setData({ billing_account_id: account.account_id, amount: '', as_of: today, notes: '', approve: true, breakdown: [] });
            form.clearErrors();
            setStep(0);
            setLines([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [account?.account_id]);

    if (!account) return null;

    const cents = parsePesos(form.data.amount);
    const breakdownCents = lines.map((l) => parsePesos(l.amount));
    const breakdownOk =
        lines.length === 0 ||
        (breakdownCents.every((c) => c !== null && c > 0) && breakdownCents.reduce<number>((s, c) => s + (c ?? 0), 0) === cents);
    const breakdownSum = breakdownCents.reduce<number>((s, c) => s + (c ?? 0), 0);
    const hasEarlierBills = account.first_charge_date !== null && account.first_charge_date <= form.data.as_of;
    const dateOk = /^\d{4}-\d{2}-\d{2}$/.test(form.data.as_of) && form.data.as_of <= today;

    const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    const submit = () => {
        form.transform((d) => ({ ...d, breakdown: lines.filter((l) => l.month || l.amount) }));
        form.post(route('opening-balances.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        Opening balance for {account.tenant} ({account.account_no})
                    </DialogTitle>
                    <DialogDescription>
                        Step {step + 1} of 3: {STEPS[step]}. Unpaid debt from before the JT system. It is posted once and is never billed as rent.
                    </DialogDescription>
                </DialogHeader>

                {step === 0 && (
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="ob-amount">Amount owed (pesos)</Label>
                            <Input
                                id="ob-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                placeholder="Like 1800 or 1,800.00"
                                autoFocus
                            />
                            <p className="text-muted-foreground text-sm">{cents ? formatCents(cents) : 'Enter a number above zero.'}</p>
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ob-asof">Owed as of (date)</Label>
                            <Input
                                id="ob-asof"
                                type="date"
                                max={today}
                                value={form.data.as_of}
                                onChange={(e) => form.setData('as_of', e.target.value)}
                            />
                            <p className="text-muted-foreground text-sm">
                                The last day of the old system, such as 2026-10-31. Cannot be in the future.
                            </p>
                            <InputError message={errors.as_of} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ob-notes">Notes (optional)</Label>
                            <Input id="ob-notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                    </div>
                )}

                {step === 1 && (
                    <div className="space-y-3">
                        <p className="text-muted-foreground text-sm">
                            Optional, but recommended. Enter each unpaid month like the paper Statement of Accounts: one line per month and type (BH
                            rent or EB electric). The lines must add up to {cents ? formatCents(cents) : 'the total'}.
                        </p>
                        {lines.map((l, i) => (
                            <div key={i} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[9rem_8.5rem_1fr_1fr_auto]">
                                <div className="grid gap-1">
                                    <Label htmlFor={`ob-m-${i}`} className="text-xs">
                                        Month
                                    </Label>
                                    <Input id={`ob-m-${i}`} type="month" value={l.month} onChange={(e) => setLine(i, { month: e.target.value })} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`ob-c-${i}`} className="text-xs">
                                        Type
                                    </Label>
                                    <SearchSelect
                                        id={`ob-c-${i}`}
                                        value={l.category}
                                        onChange={(v) => setLine(i, { category: v as Line['category'] })}
                                        options={CATEGORIES.map((c) => ({ value: c.value, label: c.label }))}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`ob-a-${i}`} className="text-xs">
                                        Amount
                                    </Label>
                                    <Input
                                        id={`ob-a-${i}`}
                                        inputMode="decimal"
                                        value={l.amount}
                                        onChange={(e) => setLine(i, { amount: e.target.value })}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`ob-n-${i}`} className="text-xs">
                                        Note
                                    </Label>
                                    <Input id={`ob-n-${i}`} value={l.note} onChange={(e) => setLine(i, { note: e.target.value })} />
                                </div>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Remove month"
                                    onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                                >
                                    <X />
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setLines((ls) => [
                                    ...ls,
                                    { month: ls.at(-1)?.month ?? '', category: ls.at(-1)?.category ?? 'rent', amount: '', note: '' },
                                ])
                            }
                        >
                            <Plus /> Add a line
                        </Button>
                        {lines.length > 0 && (
                            <p className={breakdownOk ? 'text-sm text-green-700 dark:text-green-400' : 'text-destructive text-sm'}>
                                Months add up to {formatCents(breakdownSum)} of {cents ? formatCents(cents) : '...'}
                                {breakdownOk ? '.' : ' - they must match exactly, and every amount must be above zero.'}
                            </p>
                        )}
                        <InputError message={errors.breakdown} />
                    </div>
                )}

                {step === 2 && cents !== null && (
                    <div className="space-y-4">
                        <p className="rounded-lg border p-4 text-sm">
                            This will post <strong>{formatCents(cents)}</strong> as Balance Brought Forward on <strong>{account.account_no}</strong>.
                            It will not be billed as rent.
                        </p>
                        <p className="text-muted-foreground text-sm">
                            Owed as of {form.data.as_of}.{lines.length > 0 && ` ${lines.length} month(s) listed.`}
                        </p>
                        {account.account_status === 'closed' && <Badge variant="secondary">Closed account</Badge>}
                        {hasEarlierBills && (
                            <Alert>
                                <TriangleAlert />
                                <AlertDescription>
                                    This account already has bills before this date - make sure this debt isn't already on them.
                                </AlertDescription>
                            </Alert>
                        )}
                        <InputError message={errors.billing_account_id} />
                        <InputError message={errors.amount} />
                        <InputError message={errors.notes} />
                        <InputError message={errors.opening_balance} />
                        <InputError message={errors.as_of} />
                        <InputError message={errors.breakdown} />
                    </div>
                )}

                <DialogFooter className="gap-2">
                    {step === 0 ? (
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                    ) : (
                        <Button type="button" variant="secondary" onClick={() => setStep(step - 1)}>
                            Back
                        </Button>
                    )}
                    {step < 2 ? (
                        <Button type="button" disabled={step === 0 ? !cents || !dateOk : !breakdownOk} onClick={() => setStep(step + 1)}>
                            {step === 1 && lines.length === 0 ? 'Skip' : 'Next'}
                        </Button>
                    ) : (
                        <Button type="button" disabled={form.processing || !cents} onClick={submit}>
                            {form.processing ? 'Posting...' : 'Approve and post'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
