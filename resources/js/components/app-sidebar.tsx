import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BarChart3, Building2, CalendarDays, FileText, History, LayoutGrid, Receipt, ScrollText, Users, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const groups: { label: string; items: NavItem[]; ownerOnly?: NavItem[] }[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
            { title: 'Calendar', url: '/calendar', icon: CalendarDays },
        ],
    },
    {
        label: 'Rentals',
        items: [
            { title: 'Properties', url: '/properties', icon: Building2 },
            { title: 'Tenants', url: '/tenants', icon: Users },
        ],
    },
    {
        label: 'Money',
        items: [
            { title: 'Charges & Utilities', url: '/charges', icon: Receipt },
            { title: 'Billing', url: '/billing', icon: FileText },
            { title: 'Collections', url: '/collections', icon: Wallet },
            { title: 'Opening Balances', url: '/opening-balances', icon: History },
        ],
    },
    {
        label: 'Insights',
        items: [{ title: 'Reports', url: '/reports', icon: BarChart3 }],
        ownerOnly: [{ title: 'Audit log', url: '/audit', icon: ScrollText }],
    },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const isOwner = (auth.user as { role?: string }).role === 'owner';
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map((group) => (
                    <NavMain
                        key={group.label}
                        label={group.label}
                        items={isOwner && group.ownerOnly ? [...group.items, ...group.ownerOnly] : group.items}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
