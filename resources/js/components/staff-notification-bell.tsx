import { date } from '@/components/umdts';
import type { StaffNotificationFeed } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowRight, Bell, CheckCheck, ChevronLeft, ChevronRight, ClipboardList, CornerDownLeft, Inbox, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';

const empty: StaffNotificationFeed = { items: [], unread: 0, through_id: 0, page: 1, last_page: 1, total: 0 };
const icons = { assigned: ClipboardList, incoming: Inbox, returned: CornerDownLeft };

export default function StaffNotificationBell({ initial }: { initial?: StaffNotificationFeed | null }) {
    const [feed, setFeed] = useState(initial || empty);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [acting, setActing] = useState(false);
    const container = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const request = useRef<AbortController | null>(null);
    const version = useRef(0);
    const id = useId();

    const refresh = useCallback(async (page: number) => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        const current = ++version.current;
        setLoading(true);
        try {
            const response = await fetch(`/workspace/notifications?page=${page}`, {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok || response.redirected) throw new Error('Notifications unavailable');
            const next: StaffNotificationFeed = await response.json();
            if (current === version.current) {
                setFeed(next);
                setError(false);
            }
        } catch {
            if (!controller.signal.aborted && current === version.current) setError(true);
        } finally {
            if (current === version.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        request.current?.abort();
        version.current++;
        setFeed(initial || empty);
        setLoading(false);
        setError(false);
    }, [initial]);

    useEffect(() => {
        function poll() {
            if (!document.hidden) void refresh(feed.page);
        }
        const interval = window.setInterval(poll, 30_000);
        window.addEventListener('focus', poll);
        document.addEventListener('visibilitychange', poll);
        return () => {
            window.clearInterval(interval);
            window.removeEventListener('focus', poll);
            document.removeEventListener('visibilitychange', poll);
        };
    }, [feed.page, refresh]);

    useEffect(
        () => () => {
            request.current?.abort();
            version.current++;
        },
        [],
    );

    useEffect(() => {
        if (!open) return;
        heading.current?.focus();
        function outside(event: PointerEvent) {
            if (!container.current?.contains(event.target as Node)) setOpen(false);
        }
        function escape(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        }
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    function act(url: string, data: Record<string, number> = {}, close = false) {
        request.current?.abort();
        version.current++;
        setLoading(false);
        router.post(url, data, {
            preserveScroll: !close,
            onStart: () => setActing(true),
            onSuccess: () => {
                if (close) setOpen(false);
            },
            onFinish: () => {
                setActing(false);
                if (!close) heading.current?.focus();
            },
        });
    }

    return (
        <div className="notification-bell" ref={container}>
            <button
                ref={trigger}
                type="button"
                className={`icon-btn notification-trigger ${open ? 'notification-trigger-open' : ''}`}
                aria-label={`Notifications${feed.unread ? `, ${feed.unread} unread` : ''}`}
                aria-expanded={open}
                aria-controls={open ? id : undefined}
                aria-haspopup="dialog"
                title="Notifications"
                onClick={() => {
                    setOpen(!open);
                    if (!open) void refresh(1);
                }}
            >
                <Bell size={18} />
                {feed.unread > 0 && (
                    <span className="notification-count" aria-hidden="true">
                        {feed.unread > 99 ? '99+' : feed.unread}
                    </span>
                )}
            </button>
            {open && (
                <section id={id} role="dialog" aria-labelledby={`${id}-title`} className="notification-popover">
                    <header className="notification-heading">
                        <div>
                            <h2 id={`${id}-title`} tabIndex={-1} ref={heading}>
                                Notifications
                            </h2>
                            <small>{feed.unread ? `${feed.unread} unread` : 'All caught up'}</small>
                        </div>
                        <button
                            type="button"
                            className="icon-btn"
                            aria-label="Close notifications"
                            onClick={() => {
                                setOpen(false);
                                trigger.current?.focus();
                            }}
                        >
                            <X size={17} />
                        </button>
                    </header>
                    <div className="notification-toolbar">
                        <small>Assignments, incoming documents and corrections</small>
                        <button
                            className="text-link"
                            type="button"
                            disabled={!feed.unread || acting || loading}
                            onClick={() => act('/workspace/notifications/read', { through_id: feed.through_id })}
                        >
                            <CheckCheck size={14} />
                            Mark all read
                        </button>
                    </div>
                    {error && (
                        <div className="notification-error" role="alert">
                            Couldn’t refresh notifications.{' '}
                            <button type="button" className="text-link" disabled={loading} onClick={() => void refresh(feed.page)}>
                                Try again
                            </button>
                        </div>
                    )}
                    <div className="notification-scroll" aria-busy={loading}>
                        {feed.items.length ? (
                            <ul className="notification-list">
                                {feed.items.map((item) => {
                                    const Icon = icons[item.kind];
                                    return (
                                        <li key={item.id} className={item.read_at ? '' : 'notification-unread'}>
                                            <span className={`notification-kind notification-${item.kind}`}>
                                                <Icon size={17} />
                                            </span>
                                            <div className="notification-body">
                                                <div className="notification-item-heading">
                                                    <b>{item.title}</b>
                                                    {!item.read_at && <span className="notification-unread-dot" aria-label="Unread" />}
                                                </div>
                                                <span className="notification-reference">{item.reference}</span>
                                                <p>{item.message}</p>
                                                <div className="notification-item-footer">
                                                    <time dateTime={item.created_at}>{date(item.created_at, true)}</time>
                                                    <button
                                                        type="button"
                                                        className="text-link"
                                                        disabled={acting}
                                                        onClick={() => act(item.open_url, {}, true)}
                                                        aria-label={`Open document ${item.reference}`}
                                                    >
                                                        Open document
                                                        <ArrowRight size={13} />
                                                    </button>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <div className="notification-empty">
                                <Bell size={25} strokeWidth={1.5} />
                                <b>No tasks needing your attention</b>
                                <p>New assignments, incoming handoffs and returns will appear here.</p>
                            </div>
                        )}
                    </div>
                    {feed.total > 0 && (
                        <footer className="notification-pagination">
                            <span>
                                {(feed.page - 1) * 10 + 1}–{Math.min(feed.page * 10, feed.total)} of {feed.total}
                            </span>
                            <div>
                                <button
                                    type="button"
                                    className="icon-btn"
                                    aria-label="Newer notifications"
                                    disabled={feed.page <= 1 || loading || acting}
                                    onClick={() => void refresh(feed.page - 1)}
                                >
                                    <ChevronLeft size={16} />
                                </button>
                                <button
                                    type="button"
                                    className="icon-btn"
                                    aria-label="Older notifications"
                                    disabled={feed.page >= feed.last_page || loading || acting}
                                    onClick={() => void refresh(feed.page + 1)}
                                >
                                    <ChevronRight size={16} />
                                </button>
                            </div>
                        </footer>
                    )}
                </section>
            )}
        </div>
    );
}
