import { Link } from '@inertiajs/react';

/** Preview / Bills switch shown at the top of every billing screen. */
export function BillingTabs({ active }: { active: 'preview' | 'invoices' }) {
    const tab = (key: string, label: string, href: string) => (
        <Link
            href={href}
            aria-current={active === key ? 'page' : undefined}
            className={
                'inline-flex h-10 items-center rounded-md px-4 text-sm font-medium ' +
                (active === key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted border')
            }
        >
            {label}
        </Link>
    );
    return (
        <nav aria-label="Billing sections" className="flex gap-2">
            {tab('preview', 'Monthly Bills', route('billing.index'))}
            {tab('invoices', 'All Bills', route('invoices.index'))}
        </nav>
    );
}

export const paymentStatusLabels: Record<string, string> = {
    paid: 'Paid',
    partial: 'Partly paid',
    unpaid: 'Unpaid',
    overdue: 'Overdue',
    void: 'Voided',
};
export const paymentStatusVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    paid: 'default',
    partial: 'secondary',
    unpaid: 'outline',
    overdue: 'destructive',
    void: 'destructive',
};
