import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type PropertyPhoto } from '@/types';
import { Check } from 'lucide-react';

/** Shared by the public landing page and listing page (pages must not import each other: Vite needs one entry per page). */
export interface ListingCard {
    code: string;
    name: string;
    type: string;
    address: string;
    amenities: string[];
    cover: PropertyPhoto | null;
    photo_count: number;
    rooms: number;
    capacity: number;
    available: number;
}

export function AvailabilityBadge({ available, className }: { available: number; className?: string }) {
    return available > 0 ? (
        <Badge variant="success" className={cn('bg-card/95 backdrop-blur', className)}>
            <Check className="size-3" aria-hidden /> {available} {available === 1 ? 'place' : 'places'} available
        </Badge>
    ) : (
        <Badge variant="muted" className={cn('bg-card/95 backdrop-blur', className)}>
            Fully occupied
        </Badge>
    );
}
