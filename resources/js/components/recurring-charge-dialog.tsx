import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents, parsePesos } from '@/lib/money';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface Category {
    id: number;
    name: string;
    kind: string;
    is_system: boolean;
}

export const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs focus-visible:ring-ring/50 focus-visible:ring-[3px] focus-visible:outline-none';

/** Adds a monthly add-on (like a rice cooker) to one agreement. */
export function AddRecurringChargeDialog({
    agreementId,
    room,
    categories,
    today,
    open,
    onOpenChange,
}: {
    agreementId: number;
    room: string;
    categories: Category[];
    today: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ charge_category_id: '', label: '', amount: '', start_date: today, end_date: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const cents = parsePesos(form.data.amount);

    useEffect(() => {
        if (!open) return;
        form.setData({ charge_category_id: '', label: '', amount: '', start_date: today, end_date: '' });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('agreements.recurring-charges.store', agreementId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Add recurring charge for room {room}</DialogTitle>
                        <DialogDescription>Added to every monthly bill from the start date until you end it.</DialogDescription>
                    </DialogHeader>
                    {errors.agreement && (
                        <p role="alert" className="text-destructive text-sm">
                            {errors.agreement}
                        </p>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="rc-category">What is it for?</Label>
                        <select
                            id="rc-category"
                            className={selectClass}
                            value={form.data.charge_category_id}
                            onChange={(e) => {
                                const id = e.target.value;
                                const name = categories.find((c) => String(c.id) === id)?.name ?? '';
                                form.setData((d) => ({ ...d, charge_category_id: id, label: d.label || name }));
                            }}
                            required
                        >
                            <option value="">Choose...</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.charge_category_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="rc-label">Name on the bill</Label>
                        <Input id="rc-label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} required />
                        <InputError message={errors.label} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="rc-amount">Amount each month (pesos)</Label>
                        <Input
                            id="rc-amount"
                            inputMode="decimal"
                            className="max-w-48"
                            placeholder="200"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            required
                        />
                        <p className="text-muted-foreground text-sm">
                            {form.data.amount.trim() === ''
                                ? 'Type the amount, like 200 or 1,500.00.'
                                : cents
                                  ? `Will be saved as ${formatCents(cents)} a month.`
                                  : 'This does not look like a peso amount.'}
                        </p>
                        <InputError message={errors.amount} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="rc-start">Starts on</Label>
                            <Input
                                id="rc-start"
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) => form.setData('start_date', e.target.value)}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rc-end">Last day (optional)</Label>
                            <Input id="rc-end" type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing || !cents || !form.data.charge_category_id || !form.data.label.trim()}>
                            {form.processing ? 'Saving...' : 'Add charge'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
