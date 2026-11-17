import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    BedDouble,
    CornerDownLeft,
    FileText,
    HandCoins,
    History,
    type LucideIcon,
    Printer,
    Receipt,
    Route as RouteIcon,
    Trash2,
    Users,
    Zap,
} from 'lucide-react';
import { type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Monthly guide', href: '/guide' }];

type Tone = 'prepare' | 'check' | 'bill' | 'after';
const tones: Record<Tone, { badge: string; ring: string; label: string }> = {
    prepare: { badge: 'bg-info/12 text-info', ring: 'border-info/40', label: 'Before billing' },
    check: { badge: 'bg-warning/12 text-warning', ring: 'border-warning/50', label: 'Check' },
    bill: { badge: 'bg-primary text-primary-foreground', ring: 'border-primary/50', label: 'Bill' },
    after: { badge: 'bg-brand/10 text-brand', ring: 'border-brand/40', label: 'After billing' },
};

/** One box in the flow: a numbered action that opens the screen where it is done. */
function Step({
    n,
    tone,
    icon: Icon,
    title,
    text,
    href,
    where,
}: {
    n: number;
    tone: Tone;
    icon: LucideIcon;
    title: string;
    text: ReactNode;
    href: string;
    where: string;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'bg-card hover:shadow-lift focus-visible:ring-ring group block rounded-2xl border-2 p-4 transition-shadow focus-visible:ring-2 focus-visible:outline-none',
                tones[tone].ring,
            )}
        >
            <div className="flex items-start gap-3">
                <span className={cn('grid size-9 shrink-0 place-items-center rounded-full text-base font-bold', tones[tone].badge)}>{n}</span>
                <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 font-semibold">
                        <Icon className="text-primary size-4 shrink-0" aria-hidden /> {title}
                    </p>
                    <div className="text-muted-foreground mt-1 text-sm leading-snug">{text}</div>
                    <p className="text-primary mt-2 inline-flex items-center gap-1 text-xs font-medium group-hover:underline">
                        Open {where} <ArrowRight className="size-3" aria-hidden />
                    </p>
                </div>
            </div>
        </Link>
    );
}

/** A yes/no check. "Yes" continues down the flow; "No" sends you back to the step that fixes it. */
function Decision({ question, no, noHref }: { question: string; no: string; noHref: string }) {
    return (
        <div className="grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
            <div className="hidden md:block" />
            {/* A rotated square needs ~1.42x its side; the outer box reserves that so diamonds never overlap the arrows. */}
            <div className="mx-auto grid size-48 place-items-center">
                <div className="border-warning/60 bg-warning/10 grid size-32 rotate-45 place-items-center rounded-2xl border-2">
                    <p className="w-32 -rotate-45 text-center text-sm leading-tight font-semibold">{question}</p>
                </div>
            </div>
            <Link
                href={noHref}
                className="border-destructive/40 bg-destructive/5 hover:bg-destructive/10 focus-visible:ring-ring flex items-start gap-2 rounded-xl border border-dashed p-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            >
                <CornerDownLeft className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden />
                <span>
                    <span className="text-destructive font-semibold">No:</span> {no}
                </span>
            </Link>
        </div>
    );
}

function Down({ label }: { label?: string }) {
    return (
        <div className="text-muted-foreground flex flex-col items-center py-1" aria-hidden>
            <span className="bg-border h-4 w-0.5" />
            {label && <span className="text-success text-xs font-semibold">{label}</span>}
            <ArrowDown className="size-5" />
        </div>
    );
}

function Phase({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <div className="flex items-center gap-3 pt-2">
            <span className={cn('rounded-full px-3 py-1 text-xs font-semibold tracking-wide uppercase', tones[tone].badge)}>{tones[tone].label}</span>
            <span className="text-muted-foreground text-xs">{children}</span>
            <span className="bg-border h-px flex-1" />
        </div>
    );
}

export default function Guide() {
    const now = new Date();
    const bills = route('billing.index', { year: now.getFullYear(), month: now.getMonth() + 1 });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Monthly guide" />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 md:p-6">
                <PageHeader
                    icon={RouteIcon}
                    title="What to do every month"
                    description="Follow the boxes from top to bottom. Click any box to open the screen where you do it. Nothing is saved on the bills until step 5."
                />

                <ul className="flex flex-wrap gap-2 text-xs" aria-label="Legend">
                    {(Object.keys(tones) as Tone[]).map((t) => (
                        <li key={t} className={cn('rounded-full px-2.5 py-1 font-medium', tones[t].badge)}>
                            {tones[t].label}
                        </li>
                    ))}
                    <li className="border-warning/60 bg-warning/10 rounded-full border px-2.5 py-1 font-medium">◆ Yes / No check</li>
                </ul>

                <ol className="space-y-0" aria-label="Monthly steps">
                    <li>
                        <Phase tone="prepare">first days of the month, or before the due date</Phase>
                    </li>
                    <li className="pt-3">
                        <Step
                            n={1}
                            tone="prepare"
                            icon={Users}
                            title="Update who is staying"
                            text="Record new boarders (move-in, room or bed, rent amount) and anyone who left (move-out date)."
                            href={route('tenants.index')}
                            where="Tenants"
                        />
                    </li>
                    <li>
                        <Down />
                        <Step
                            n={2}
                            tone="prepare"
                            icon={History}
                            title="First month only: old debts"
                            text="If a boarder owed money before you used this system, enter and approve it once. Skip this in later months."
                            href={route('opening-balances.index')}
                            where="Opening balances"
                        />
                    </li>
                    <li>
                        <Down />
                        <Step
                            n={3}
                            tone="prepare"
                            icon={Receipt}
                            title="Add extra charges"
                            text="Every-month add-ons (rice cooker, Wi-Fi) or one-time fees and discounts (repair, lost key) for this month."
                            href={route('charges.index')}
                            where="Extra charges"
                        />
                    </li>
                    <li>
                        <Down />
                        <Step
                            n={4}
                            tone="prepare"
                            icon={Zap}
                            title="Enter electricity"
                            text={
                                <>
                                    On <b>Electricity</b>, type each room's total electric bill. It is split equally between the boarders in the room
                                    (₱1,000 with 4 boarders = ₱250 each). Use "Needs reading" to see which rooms are still missing.
                                </>
                            }
                            href={route('utilities.index')}
                            where="Electricity"
                        />
                    </li>

                    <li className="pt-2">
                        <Phase tone="check">look at the Bills screen before you generate</Phase>
                    </li>
                    <li>
                        <Down />
                        <Decision
                            question={'"No electric bill yet" shows 0?'}
                            no='Go back to step 4: on Electricity, choose "Needs reading" and enter each room still missing. (If you bill anyway, their share moves to next month’s bill.)'
                            noHref={route('utilities.index')}
                        />
                    </li>
                    <li>
                        <Down label="Yes" />
                        <Decision
                            question={'"Needs attention" shows 0?'}
                            no='Open the "Needs attention" filter and fix each boarder (often a missing rent amount, or nobody in the room). They are left out until fixed.'
                            noHref={bills}
                        />
                    </li>
                    <li>
                        <Down label="Yes" />
                        <Decision
                            question="Preview totals look right?"
                            no="Click ▾ on a boarder to see each line. Fix the charge or the tenant record, then check again."
                            noHref={bills}
                        />
                    </li>

                    <li>
                        <Down label="Yes" />
                        <Phase tone="bill">this saves the bills</Phase>
                    </li>
                    <li className="pt-3">
                        <Step
                            n={5}
                            tone="bill"
                            icon={FileText}
                            title="Press Generate bills"
                            text="Creates every ready bill for the month. Safe to press again later: boarders already billed are skipped."
                            href={bills}
                            where="Bills"
                        />
                    </li>

                    <li className="pt-2">
                        <Phase tone="after">send the bills and collect</Phase>
                    </li>
                    <li>
                        <Down />
                        <Step
                            n={6}
                            tone="after"
                            icon={Printer}
                            title="Print or email the statements"
                            text={
                                <>
                                    <b>All statements</b> prints every bill in one PDF. <b>Send all</b> emails each boarder their statement.
                                </>
                            }
                            href={bills}
                            where="Bills"
                        />
                    </li>
                    <li>
                        <Down />
                        <Step
                            n={7}
                            tone="after"
                            icon={HandCoins}
                            title="Record payments as boarders pay"
                            text="Each payment updates the balance and gives a receipt. Late boarders show on the Dashboard."
                            href={route('collections.index')}
                            where="Payments"
                        />
                    </li>
                    <li>
                        <Down />
                        <div className="bg-success/10 text-success flex items-center justify-center gap-2 rounded-2xl p-4 text-center font-semibold">
                            <BedDouble className="size-5" aria-hidden /> Month done. Next month, start again at step 1.
                        </div>
                    </li>
                </ol>

                <section
                    className="border-destructive/30 bg-destructive/5 mt-6 rounded-2xl border border-dashed p-4 text-sm"
                    aria-label="If you made a mistake"
                >
                    <p className="flex items-center gap-2 font-semibold">
                        <Trash2 className="text-destructive size-4" aria-hidden /> Made a mistake after generating?
                    </p>
                    <p className="text-muted-foreground mt-1">
                        While it is still the latest billed month, open <b>Bills</b> and use <b>Delete month</b>. The bills are voided (your reason is
                        kept), payments go back as credit, and you can fix things and generate again from step 4. Once the next month is billed, this
                        month is locked: add a correction as an extra charge or discount instead.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
