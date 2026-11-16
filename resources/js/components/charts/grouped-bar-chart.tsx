import { formatCents, formatCentsCompact } from '@/lib/money';
import { cn } from '@/lib/utils';
import { useState } from 'react';

export interface BarSeries {
    key: string;
    label: string;
    /** Tailwind background class for the mark, e.g. bg-series-1. Text never uses it. */
    swatch: string;
}

interface Props {
    data: ({ label: string; month: string } & Record<string, number | string>)[];
    series: BarSeries[];
    /** Accessible name; also the caption of the table view. */
    title: string;
    footer?: (row: Props['data'][number]) => React.ReactNode;
}

/** "Nice" round top for the axis so gridlines land on readable values. */
function niceMax(value: number): number {
    if (value <= 0) return 100_00;
    const exp = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((m) => m * exp >= value) ?? 10;
    return step * exp;
}

/**
 * Grouped columns on ONE shared axis (both series are pesos). Thin marks, 4px rounded tops anchored to the
 * baseline, 2px gap between paired bars, recessive grid, legend always shown, hover/focus tooltip per month,
 * and a table view so nothing depends on color alone.
 */
export function GroupedBarChart({ data, series, title, footer }: Props) {
    const [active, setActive] = useState<number | null>(null);
    const [asTable, setAsTable] = useState(false);
    const max = niceMax(Math.max(0, ...data.flatMap((row) => series.map((s) => Number(row[s.key]) || 0))));
    const ticks = [1, 0.75, 0.5, 0.25, 0];

    return (
        <figure className="space-y-4" aria-label={title}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <ul className="flex flex-wrap gap-4 text-sm" aria-label="Legend">
                    {series.map((s) => (
                        <li key={s.key} className="text-muted-foreground flex items-center gap-2">
                            <span className={cn('size-3 rounded-[3px]', s.swatch)} aria-hidden />
                            {s.label}
                        </li>
                    ))}
                </ul>
                <button type="button" onClick={() => setAsTable((v) => !v)} className="text-primary text-sm font-medium underline-offset-4 hover:underline">
                    {asTable ? 'Show chart' : 'View as table'}
                </button>
            </div>

            {asTable ? (
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">{title}</caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2">Month</th>
                                {series.map((s) => (
                                    <th key={s.key} className="px-3 py-2 text-right">
                                        {s.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((row) => (
                                <tr key={row.month} className="border-t">
                                    <td className="px-3 py-2">{row.month}</td>
                                    {series.map((s) => (
                                        <td key={s.key} className="px-3 py-2 text-right font-mono">
                                            {formatCents(Number(row[s.key]) || 0)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="grid grid-cols-[auto_1fr] gap-x-2">
                    <div className="text-muted-foreground relative h-56 w-12 text-right font-mono text-[11px]" aria-hidden>
                        {ticks.map((t) => (
                            <span key={t} className="absolute right-0 -translate-y-1/2" style={{ top: `${(1 - t) * 100}%` }}>
                                {formatCentsCompact(max * t)}
                            </span>
                        ))}
                    </div>
                    <div className="relative h-56" onMouseLeave={() => setActive(null)}>
                        {ticks.map((t) => (
                            <div key={t} className={cn('absolute inset-x-0 border-t', t === 0 ? 'border-border' : 'border-border/50 border-dashed')} style={{ top: `${(1 - t) * 100}%` }} aria-hidden />
                        ))}
                        <div className="absolute inset-0 flex">
                            {data.map((row, i) => (
                                <div
                                    key={row.month}
                                    tabIndex={0}
                                    role="img"
                                    aria-label={`${row.month}: ${series.map((s) => `${s.label} ${formatCents(Number(row[s.key]) || 0)}`).join(', ')}`}
                                    onMouseEnter={() => setActive(i)}
                                    onFocus={() => setActive(i)}
                                    onBlur={() => setActive(null)}
                                    className={cn('relative flex flex-1 cursor-default items-end justify-center gap-[2px] rounded-md px-[12%] transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring', active === i && 'bg-muted/60')}
                                >
                                    {series.map((s) => {
                                        const value = Number(row[s.key]) || 0;
                                        return (
                                            <div
                                                key={s.key}
                                                className={cn('w-full max-w-5 rounded-t-[4px] transition-[height] duration-300', s.swatch)}
                                                style={{ height: value > 0 ? `max(2px, ${(value / max) * 100}%)` : 0 }}
                                            />
                                        );
                                    })}
                                    {active === i && (
                                        <div
                                            role="tooltip"
                                            className={cn(
                                                'bg-popover text-popover-foreground shadow-lift pointer-events-none absolute bottom-full z-10 mb-2 min-w-44 rounded-lg border p-3 text-xs',
                                                i < 2 ? 'left-0' : i > data.length - 3 ? 'right-0' : 'left-1/2 -translate-x-1/2',
                                            )}
                                        >
                                            <p className="mb-1.5 font-semibold">{row.month}</p>
                                            {series.map((s) => (
                                                <p key={s.key} className="flex items-center justify-between gap-4">
                                                    <span className="text-muted-foreground flex items-center gap-1.5">
                                                        <span className={cn('size-2 rounded-[2px]', s.swatch)} aria-hidden />
                                                        {s.label}
                                                    </span>
                                                    <span className="font-mono font-medium">{formatCents(Number(row[s.key]) || 0)}</span>
                                                </p>
                                            ))}
                                            {footer && <div className="text-muted-foreground mt-1.5 border-t pt-1.5">{footer(row)}</div>}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                    <span />
                    <div className="text-muted-foreground mt-2 flex text-[11px]" aria-hidden>
                        {data.map((row) => (
                            <span key={row.month} className="flex-1 text-center">
                                {row.label}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </figure>
    );
}
