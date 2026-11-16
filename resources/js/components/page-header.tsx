import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    title: ReactNode;
    description?: ReactNode;
    icon?: LucideIcon;
    actions?: ReactNode;
    className?: string;
    children?: ReactNode;
}

/** The one page title block every module uses: icon, title, short description, actions on the right. */
export function PageHeader({ title, description, icon: Icon, actions, className, children }: PageHeaderProps) {
    return (
        <div className={cn('flex flex-wrap items-start justify-between gap-4', className)}>
            <div className="flex min-w-0 items-start gap-3">
                {Icon && (
                    <span className="bg-primary/10 text-primary mt-0.5 grid size-10 shrink-0 place-items-center rounded-xl" aria-hidden>
                        <Icon className="size-5" />
                    </span>
                )}
                <div className="min-w-0">
                    <h1 className="text-2xl leading-tight font-semibold tracking-tight">{title}</h1>
                    {description && <div className="text-muted-foreground mt-1 text-sm">{description}</div>}
                    {children}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
