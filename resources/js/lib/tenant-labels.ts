export const statusFilterLabels: Record<string, string> = {
    active: 'Living here now',
    no_email: 'No email address',
    upcoming: 'Moving in soon',
    vacated: 'Moved out',
    archived: 'Archived',
};

export const deliveryLabels: Record<string, string> = {
    email: 'Email',
    print: 'Printed copy',
};

export const depositStatusLabels: Record<string, string> = {
    held: 'Held',
    applied: 'Used for charges',
    refunded: 'Refunded',
};

export const agreementStatusLabels: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
};

export function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}