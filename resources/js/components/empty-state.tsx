import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="bg-card/50 flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center">
            <span className="bg-muted text-muted-foreground grid size-12 place-items-center rounded-full" aria-hidden>
                <Icon className="size-6" />
            </span>
            <h2 className="text-base font-semibold">{title}</h2>
            {description && <p className="text-muted-foreground max-w-md text-sm">{description}</p>}
            {action}
        </div>
    );
}
