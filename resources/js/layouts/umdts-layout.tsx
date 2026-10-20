import StaffNotificationBell from '@/components/staff-notification-bell';
import { Brand, PageErrors } from '@/components/umdts';
import { useWorkspaceActivity } from '@/hooks/use-workspace-activity';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    ChartNoAxesCombined,
    ChevronRight,
    ClipboardList,
    FileText,
    FolderCog,
    Globe,
    Inbox,
    LayoutDashboard,
    LogOut,
    Menu,
    Search,
    Send,
    Settings2,
    ShieldCheck,
    Users,
    X,
} from 'lucide-react';
import { type FormEvent, type PropsWithChildren, useState } from 'react';

export default function UmdtsLayout({ children }: PropsWithChildren) {
    const { auth, flash, unreadActivity, staffNotifications } = usePage<SharedData & { flash: { success?: string } }>().props;
    const activity = useWorkspaceActivity(unreadActivity);
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const admin = auth.user.role === 'admin';
    const badges: Record<string, number> = { '/documents': activity.documents, '/inbox': activity.inbox, '/outgoing': activity.outgoing };
    const nav = [
        { label: 'Overview', items: [{ title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }] },
        {
            label: 'Workspace',
            items: [
                { title: 'My Work', href: '/my-work', icon: ClipboardList },
                { title: 'Documents', href: '/documents', icon: FileText },
                { title: 'Incoming', href: '/inbox', icon: Inbox },
                { title: 'Outgoing', href: '/outgoing', icon: Send },
                { title: 'Reports', href: '/reports', icon: ChartNoAxesCombined },
            ],
        },
        ...(admin
            ? [
                  {
                      label: 'Administration',
                      items: [
                          { title: 'Departments & offices', href: '/departments', icon: Building2 },
                          { title: 'Staff accounts', href: '/users', icon: Users },
                          { title: 'Document categories', href: '/settings/categories', icon: FolderCog },
                          { title: 'Audit log', href: '/audit', icon: ShieldCheck },
                      ],
                  },
              ]
            : []),
    ];
    const current = nav.flatMap((n) => n.items).find((n) => url.split('?')[0].startsWith(n.href));
    function search(e: FormEvent) {
        e.preventDefault();
        router.get('/documents', { search: query });
    }
    return (
        <div className="workspace">
            <a href="#main-content" className="skip-link">
                Skip to content
            </a>
            {open && <button className="sidebar-scrim" aria-label="Close navigation" onClick={() => setOpen(false)} />}
            <aside className={`main-sidebar ${open ? 'sidebar-open' : ''}`}>
                <div className="sidebar-brand">
                    <Brand dark />
                    <button className="mobile-only icon-btn" aria-label="Close navigation" onClick={() => setOpen(false)}>
                        <X size={20} />
                    </button>
                </div>
                <div className="sidebar-context">
                    <span className="context-dot" />
                    Municipal workspace<span className="context-version">v1</span>
                </div>
                <nav>
                    {nav.map((group) => (
                        <div className="nav-group" key={group.label}>
                            <p>{group.label}</p>
                            {group.items.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={() => setOpen(false)}
                                    className={`nav-item ${url.split('?')[0].startsWith(item.href) ? 'nav-active' : ''}`}
                                >
                                    <item.icon size={18} strokeWidth={1.7} />
                                    <span>{item.title}</span>
                                    {badges[item.href] > 0 && (
                                        <b
                                            className="nav-activity-count"
                                            aria-label={`${badges[item.href]} new ${badges[item.href] === 1 ? 'activity' : 'activities'}`}
                                            title="New activity since you last opened this tab"
                                        >
                                            {badges[item.href] > 99 ? '99+' : badges[item.href]}
                                        </b>
                                    )}
                                    {url.split('?')[0].startsWith(item.href) && <ChevronRight size={14} />}
                                </Link>
                            ))}
                        </div>
                    ))}
                </nav>
                <div className="sidebar-bottom">
                    <Link href="/track" className="nav-item">
                        <Globe size={18} />
                        Public tracking
                        <ChevronRight size={14} />
                    </Link>
                    <div className="sidebar-foot">
                        <ShieldCheck size={15} />
                        <span>Every document, accounted for.</span>
                    </div>
                </div>
            </aside>
            <div className="workspace-body">
                <header className="topbar">
                    <div className="topbar-left">
                        <button className="mobile-only icon-btn" aria-label="Open navigation" onClick={() => setOpen(true)}>
                            <Menu size={22} />
                        </button>
                        <span className="breadcrumb">
                            Workspace
                            <ChevronRight size={13} />
                            <b>{current?.title || 'Account settings'}</b>
                        </span>
                    </div>
                    <form onSubmit={search} className="global-search">
                        <Search size={16} />
                        <input
                            aria-label="Global document search"
                            placeholder="Find a document…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <kbd>↵</kbd>
                    </form>
                    <div className="topbar-account">
                        <StaffNotificationBell initial={staffNotifications} />
                        <Link href="/settings/profile" className="account-button" aria-label="Account settings">
                            <span className="avatar">
                                {auth.user.name
                                    .split(' ')
                                    .map((p) => p[0])
                                    .slice(0, 2)
                                    .join('')}
                            </span>
                            <span className="account-name">
                                <b>{auth.user.name}</b>
                                <small>{admin ? 'Administrator' : auth.user.department?.name || 'Municipal staff'}</small>
                            </span>
                        </Link>
                        <Link href="/settings/password" aria-label="Change password" className="icon-btn">
                            <Settings2 size={18} />
                        </Link>
                        <Link href="/logout" method="post" as="button" className="icon-btn" aria-label="Sign out">
                            <LogOut size={17} />
                        </Link>
                    </div>
                </header>
                <main id="main-content" className="workspace-main">
                    {flash?.success && (
                        <div className="notice notice-success" role="status">
                            {flash.success}
                        </div>
                    )}
                    <PageErrors />
                    {children}
                </main>
                <footer className="workspace-footer">
                    <span>Umingan Municipal Document Tracking System</span>
                    <span>
                        Umingan, Pangasinan <i /> Philippine Standard Time
                    </span>
                </footer>
            </div>
        </div>
    );
}
