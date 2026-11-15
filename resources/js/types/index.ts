import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success?: string | null };
    [key: string]: unknown;
}

export interface PropertySummary {
    id: number;
    name: string;
    code: string;
    type: string;
    address: string;
    rooms: number;
    capacity: number;
    occupied: number;
    vacant: number;
}

export interface PropertyDetail {
    id: number;
    name: string;
    code: string;
    type: string;
    address: string;
    description: string | null;
    amenities: string | null;
}

export interface UnitRow {
    id: number;
    code: string;
    building: string | null;
    floor: string | null;
    capacity: number;
    rent_mode: 'whole' | 'per_bed';
    status: 'available' | 'maintenance' | 'unavailable';
    description: string | null;
    occupied: number;
    display_status: string;
    occupants: { id: number; name: string; bed: string | null }[];
}

export interface TenantRow {
    id: number;
    name: string;
    email: string | null;
    mobile: string | null;
    property: string;
    room: string | null;
    rent: string | null;
    due_day: number | null;
    paid_by: string | null;
    status: 'active' | 'upcoming' | 'no_room' | 'archived';
    moving_in: string | null;
}

export interface Paginated<T> {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: 'owner' | 'staff' | 'accountant';
    appearance: 'light' | 'dark' | 'system';
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
