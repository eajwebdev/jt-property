import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ScrollText, Search } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Log {
    id: number;
    action: string;
    user: string;
    subject: string;
    ip: string | null;
    at: string | null;
}
interface Props {
    logs: { data: Log[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { search?: string; from?: string; to?: string };
}
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/audit' }];

export default function AuditLog({ logs, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('audit.index'), { search, from, to }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
                <PageHeader icon={ScrollText} title="Audit log" description="Every change to money, tenants and settings, with who made it and when." />

                <form onSubmit={submit} className="surface grid gap-3 p-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                    <div className="grid gap-1.5">
                        <Label htmlFor="search">Action or user</Label>
                        <Input id="search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="e.g. payment.approved" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                    <Button>
                        <Search /> Search
                    </Button>
                </form>

                {logs.data.length === 0 ? (
                    <EmptyState icon={ScrollText} title="No matching activity" description="Try a wider date range or a different search." />
                ) : (
                    <div className="surface overflow-x-auto">
                        <table className="w-full min-w-[650px] text-sm">
                            <caption className="sr-only">Audit log</caption>
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3">When</th>
                                    <th className="px-4 py-3">Actor</th>
                                    <th className="px-4 py-3">Action</th>
                                    <th className="px-4 py-3">Record</th>
                                    <th className="px-4 py-3">IP</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td className="text-muted-foreground px-4 py-3 font-mono text-xs whitespace-nowrap">{log.at}</td>
                                        <td className="px-4 py-3 font-medium">{log.user}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant="muted" className="font-mono">
                                                {log.action}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">{log.subject}</td>
                                        <td className="text-muted-foreground px-4 py-3 font-mono text-xs">{log.ip ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <nav aria-label="Pages" className="flex flex-wrap gap-1.5">
                    {logs.links.map((link, index) => {
                        const label = link.label.replace('&laquo;', '«').replace('&raquo;', '»');
                        return link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                className={cn('hover:bg-muted grid h-9 min-w-9 place-items-center rounded-lg border px-3 text-sm transition-colors', link.active && 'bg-primary text-primary-foreground hover:bg-primary/90 border-transparent')}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span key={index} className="text-muted-foreground grid h-9 min-w-9 place-items-center rounded-lg border px-3 text-sm opacity-60">
                                {label}
                            </span>
                        );
                    })}
                </nav>
            </div>
        </AppLayout>
    );
}
