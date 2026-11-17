import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Mail, MailCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    paymentId: number;
    receiptNumber: string;
    /** The boarder's email on file (pre-filled; can be changed). */
    defaultEmail: string | null;
    /** Where it was last emailed, if ever. */
    emailedTo?: string | null;
    size?: 'sm' | 'default' | 'lg';
    variant?: 'outline' | 'ghost' | 'default';
    label?: string;
}

/** Emails one payment's receipt (PDF attached) to the boarder, or to another address typed in. */
export function EmailReceiptButton({ paymentId, receiptNumber, defaultEmail, emailedTo, size = 'sm', variant = 'outline', label }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ email: defaultEmail ?? '' });
    const errors = form.errors as Record<string, string | undefined>;
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('payments.receipt.email', paymentId), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <>
            <Button
                type="button"
                size={size}
                variant={variant}
                onClick={() => {
                    form.setData('email', defaultEmail ?? emailedTo ?? '');
                    form.clearErrors();
                    setOpen(true);
                }}
                title={emailedTo ? `Last emailed to ${emailedTo}` : 'Email this receipt'}
                aria-label={`Email receipt ${receiptNumber}`}
            >
                {emailedTo ? <MailCheck /> : <Mail />} {label ?? (emailedTo ? 'Email again' : 'Email')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Email receipt {receiptNumber}</DialogTitle>
                            <DialogDescription>
                                The receipt is sent as a PDF attachment.{emailedTo ? ` It was last sent to ${emailedTo}.` : ''}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor={`receipt-email-${paymentId}`}>Send to</Label>
                            <Input
                                id={`receipt-email-${paymentId}`}
                                type="email"
                                autoComplete="email"
                                placeholder="boarder@example.com"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                                autoFocus
                            />
                            {!defaultEmail && (
                                <p className="text-muted-foreground text-xs">This boarder has no email on file. Type one to send to.</p>
                            )}
                            <InputError message={errors.email ?? errors.payment} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing || !form.data.email.trim()}>
                                <Mail /> Send receipt
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
