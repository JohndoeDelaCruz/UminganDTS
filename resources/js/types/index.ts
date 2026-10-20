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
    unreadActivity?: WorkspaceActivityCounts | null;
    staffNotifications?: StaffNotificationFeed | null;
    [key: string]: unknown;
}

export interface WorkspaceActivityCounts {
    documents: number;
    inbox: number;
    outgoing: number;
}

export interface StaffNotification {
    id: number;
    kind: 'assigned' | 'incoming' | 'returned';
    reference: string;
    title: string;
    message: string;
    created_at: string;
    read_at: string | null;
    open_url: string;
}

export interface StaffNotificationFeed {
    items: StaffNotification[];
    unread: number;
    through_id: number;
    page: number;
    last_page: number;
    total: number;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    role: 'admin' | 'staff';
    department_id: number | null;
    department?: { id: number; name: string } | null;
    is_active: boolean;
    [key: string]: unknown; // This allows for additional properties...
}
