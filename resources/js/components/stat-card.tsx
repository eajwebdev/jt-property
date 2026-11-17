import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

const tones = {
    default: 'bg-primary/10 text-primary',
    brand: 'bg-brand/10 text-brand',
    success: 'bg-success/12 text-success',
    warning: 'bg-warning/12 text-warning',
    danger: 'bg-destructive/12 text-destructive',
    info: 'bg-info/12 text-info',
} as const;

interface StatCardProps {
    label: string;
    value: ReactNode;
    icon?: LucideIcon;
    tone?: keyof typeof tones;
    hint?: ReactNode;
    className?: string;
    /** Makes the whole tile a link to the page behind the figure. */
    href?: string;
}

/** A single KPI tile. Figures use tabular mono digits so columns of tiles line up. */
export function StatCard({ label, value, icon: Icon, tone = 'default', hint, className, href }: StatCardProps) {
    const body = (
        <>
            <div className="flex items-center justify-between gap-2">
                <p className="text-muted-foreground text-sm font-medium">{label}</p>
                {Icon && (
                    <span className={cn('grid size-8 place-items-center rounded-lg', tones[tone])} aria-hidden>
                        <Icon className="size-4" />
                    </span>
                )}
            </div>
            <p className="font-mono text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
            {hint && <p className="text-muted-foreground -mt-1 text-xs">{hint}</p>}
        </>
    );
    const base = cn('surface flex flex-col gap-3 p-4', className);
    return href ? (
        <Link
            href={href}
            className={cn(
                base,
                'hover:border-primary/40 hover:shadow-lift focus-visible:ring-ring transition-shadow focus-visible:ring-2 focus-visible:outline-none',
            )}
        >
            {body}
        </Link>
    ) : (
        <div className={base}>{body}</div>
    );
}
