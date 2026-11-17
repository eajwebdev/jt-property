import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CalendarDays,
    FileText,
    History,
    LayoutGrid,
    Receipt,
    Route as RouteIcon,
    ScrollText,
    Users,
    Wallet,
    Zap,
} from 'lucide-react';
import AppLogo from './app-logo';

// Grouped in the order of the monthly routine: set up -> charges -> bill -> collect.
const groups: { label: string; items: NavItem[]; ownerOnly?: NavItem[] }[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid, hint: 'Today at a glance and the monthly checklist' },
            { title: 'Monthly guide', url: '/guide', icon: RouteIcon, hint: 'Step-by-step flowchart of what to do each month' },
            { title: 'Calendar', url: '/calendar', icon: CalendarDays, hint: 'Rent due dates, move-ins and move-outs' },
        ],
    },
    {
        label: 'Boarding house',
        items: [
            { title: 'Properties & rooms', url: '/properties', icon: Building2, hint: 'Buildings, rooms and beds' },
            { title: 'Tenants', url: '/tenants', icon: Users, hint: 'Boarders, move-in and move-out' },
        ],
    },
    {
        label: 'Monthly billing',
        items: [
            { title: 'Extra charges', url: '/charges', icon: Receipt, hint: 'Add-ons, one-time fees and credits on top of rent' },
            { title: 'Electricity', url: '/utilities', icon: Zap, hint: 'Meter readings and how each room pays electricity' },
            { title: 'Bills', url: '/billing', icon: FileText, hint: 'Generate, print and email monthly statements' },
            { title: 'Payments', url: '/collections', icon: Wallet, hint: 'Record payments and see who still owes' },
        ],
    },
    {
        label: 'Records',
        items: [
            { title: 'Reports', url: '/reports', icon: BarChart3, hint: 'Income, balances and statements of account' },
            { title: 'Opening balances', url: '/opening-balances', icon: History, hint: 'Old unpaid debt from before this system' },
        ],
        ownerOnly: [{ title: 'Audit log', url: '/audit', icon: ScrollText, hint: 'Who changed what, and when' }],
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
