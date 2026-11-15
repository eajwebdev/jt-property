import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const config: Record<string, { label: string; className: string }> = {
    active: { label: 'Living here', className: 'bg-success text-success-foreground' },
    no_room: { label: 'No room now', className: 'bg-muted text-muted-foreground' },
    archived: { label: 'Archived', className: 'bg-muted text-muted-foreground' },
};

export default function TenantStatusBadge({ status }: { status: string }) {
    const { label, className } = config[status] ?? { label: status, className: 'bg-muted text-muted-foreground' };
    return <Badge className={cn('border-transparent whitespace-nowrap', className)}>{label}</Badge>;
}

export function NoEmailBadge() {
    return <Badge className="border-transparent bg-warning whitespace-nowrap text-warning-foreground">No email</Badge>;
}