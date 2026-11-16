import { SearchSelect } from '@/components/ui/search-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, LogIn, LogOut, Wallet } from 'lucide-react';

interface Event {
    date: string;
    type: 'due' | 'move-in' | 'move-out';
    label: string;
    account_id: number | null;
}
interface Props {
    month: string;
    propertyId: number | null;
    properties: { id: number; name: string }[];
    events: Event[];
}
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calendar', href: '/calendar' }];

// Icon + label carry the meaning; color only reinforces it.
const kinds = {
    due: { label: 'Rent due', icon: Wallet, className: 'bg-primary/10 text-primary' },
    'move-in': { label: 'Move in', icon: LogIn, className: 'bg-info/12 text-info' },
    'move-out': { label: 'Move out', icon: LogOut, className: 'bg-warning/12 text-warning' },
} as const;

export default function Calendar({ month, propertyId, properties, events }: Props) {
    const start = new Date(`${month}-01T12:00:00`);
    const days = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    const offset = start.getDay();
    const dates = Array.from({ length: Math.ceil((offset + days) / 7) * 7 }, (_, index) => index - offset + 1);
    const go = (nextMonth: string, nextProperty: string) =>
        router.get(route('calendar.index'), { month: nextMonth, property_id: nextProperty || undefined }, { preserveState: true, replace: true });
    const shift = (delta: number) => {
        const date = new Date(start.getFullYear(), start.getMonth() + delta, 1);
        go(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`, String(propertyId ?? ''));
    };
    const label = start.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
    const now = new Date();
    const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={CalendarDays}
                    title="Due dates and moves"
                    description="Scheduled rent due dates and recorded move dates."
                    actions={
                        <SearchSelect aria-label="Property" className="w-56" value={String(propertyId ?? '')} onChange={(v) => go(month, v)} options={[{ value: '', label: 'All properties' }, ...properties.map((p) => ({ value: String(p.id), label: p.name }))]} />
                    }
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="icon" onClick={() => shift(-1)} aria-label="Previous month">
                            <ChevronLeft />
                        </Button>
                        <h2 className="min-w-40 text-center text-lg font-semibold">{label}</h2>
                        <Button variant="outline" size="icon" onClick={() => shift(1)} aria-label="Next month">
                            <ChevronRight />
                        </Button>
                    </div>
                    <ul className="flex flex-wrap gap-2 text-xs" aria-label="Legend">
                        {Object.values(kinds).map((k) => (
                            <li key={k.label} className={cn('flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium', k.className)}>
                                <k.icon className="size-3.5" aria-hidden /> {k.label}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="surface overflow-hidden">
                    <div className="bg-border grid grid-cols-7 gap-px text-xs sm:text-sm">
                        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                            <div key={day} className="bg-muted/70 text-muted-foreground px-2 py-2 text-xs font-semibold tracking-wide uppercase">
                                {day}
                            </div>
                        ))}
                        {dates.map((day, index) => {
                            const date = day > 0 && day <= days ? `${month}-${String(day).padStart(2, '0')}` : null;
                            const items = date ? events.filter((event) => event.date === date) : [];
                            return (
                                <div key={index} className={cn('bg-card min-h-24 p-1.5 sm:min-h-32', !date && 'bg-muted/30')}>
                                    {date && (
                                        <span className={cn('grid size-6 place-items-center rounded-full text-xs font-medium', date === todayKey && 'bg-primary text-primary-foreground')}>{day}</span>
                                    )}
                                    <ul className="mt-1 space-y-1">
                                        {items.map((event, eventIndex) => {
                                            const kind = kinds[event.type];
                                            return (
                                                <li key={eventIndex} className={cn('flex items-start gap-1 rounded-md px-1.5 py-1 text-[11px] leading-tight sm:text-xs', kind.className)}>
                                                    <kind.icon className="mt-px size-3 shrink-0" aria-label={kind.label} />
                                                    {event.type === 'due' && event.account_id ? (
                                                        <Link href={route('accounts.statement', event.account_id)} className="truncate hover:underline">
                                                            {event.label}
                                                        </Link>
                                                    ) : (
                                                        <span className="truncate">{event.label}</span>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>
                </div>
                {events.length === 0 && <p className="text-muted-foreground text-sm">No due dates or moves in this month.</p>}
            </div>
        </AppLayout>
    );
}
