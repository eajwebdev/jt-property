import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/tenant-labels';
import { cn } from '@/lib/utils';

const config: Record<string, { label: string; className: string }> = {
    active: { label: 'Living here', className: 'bg-success text-success-foreground' },
    upcoming: { label: 'Moving in soon', className: 'bg-brand text-brand-foreground' },
    no_room: { label: 'No room now', className: 'bg-muted text-muted-foreground' },
    archived: { label: 'Archived', className: 'bg-muted text-muted-foreground' },
};

export default function TenantStatusBadge({ status, date }: { status: string; date?: string | null }) {
    const found = config[status] ?? { label: status, className: 'bg-muted text-muted-foreground' };
    const { className } = found;
    const label =
        status === 'upcoming' && date
            ? `Moving in ${formatDate(date)}`
            : status === 'archived' && date
              ? `Archived on ${formatDate(date)}`
              : found.label;
    return <Badge className={cn('border-transparent whitespace-nowrap', className)}>{label}</Badge>;
}

export function NoEmailBadge() {
    return <Badge className="bg-warning text-warning-foreground border-transparent whitespace-nowrap">No email</Badge>;
}
