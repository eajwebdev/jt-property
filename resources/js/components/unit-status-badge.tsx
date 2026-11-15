import { Badge } from '@/components/ui/badge';
import { unitStatusLabels } from '@/lib/property-labels';
import { cn } from '@/lib/utils';

const styles: Record<string, string> = {
    vacant: 'border-transparent bg-muted text-muted-foreground',
    available: 'border-transparent bg-success text-success-foreground',
    full: 'border-transparent bg-brand text-brand-foreground',
    maintenance: 'border-transparent bg-warning text-warning-foreground',
    unavailable: 'border-transparent bg-warning text-warning-foreground',
};

export default function UnitStatusBadge({ status }: { status: string }) {
    return <Badge className={cn('whitespace-nowrap', styles[status])}>{unitStatusLabels[status] ?? status}</Badge>;
}
