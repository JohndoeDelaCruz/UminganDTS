import type { Category, Doc, Filters, Metrics, Office, Paginated, StatusOption } from '@/types/umdts';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDownToLine, ArrowRight, Check, ChevronLeft, ChevronRight, FileText, Plus, Search } from 'lucide-react';
import { type ComponentProps, type FormEvent, type ReactNode, useState } from 'react';

export function Brand({ dark = false }: { dark?: boolean }) {
    return (
        <Link href="/" className={`brand ${dark ? 'brand-dark' : ''}`}>
            <span className="brand-mark">
                <FileText size={22} strokeWidth={1.7} />
            </span>
            <span>
                <strong>
                    UMINGAN<span className="brand-dts">DTS</span>
                </strong>
                <small>DOCUMENT TRACKING SYSTEM</small>
            </span>
        </Link>
    );
}
export function Field({ label, error, children, hint }: { label: string; error?: string; children: ReactNode; hint?: string }) {
    return (
        <label className="field">
            <span>{label}</span>
            {children}
            {hint && <small>{hint}</small>}
            {error && <small className="field-error">{error}</small>}
        </label>
    );
}
export function Input(props: ComponentProps<'input'>) {
    return <input className="control" {...props} />;
}
export function Select(props: ComponentProps<'select'>) {
    return <select className="control" {...props} />;
}
export function Textarea(props: ComponentProps<'textarea'>) {
    return <textarea className="control" rows={3} {...props} />;
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    const unique = [...new Set(Object.values(errors))];
    return unique.length ? (
        <div role="alert" className="notice notice-error">
            {unique.map((error) => (
                <p key={error}>{error}</p>
            ))}
        </div>
    ) : null;
}
export function PageErrors() {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return <Errors errors={errors} />;
}
export function PageTitle({ eyebrow, title, description, action }: { eyebrow?: string; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="page-heading">
            <div>
                {eyebrow && <div className="eyebrow">{eyebrow}</div>}
                <h1>{title}</h1>
                {description && <p>{description}</p>}
            </div>
            {action && <div className="page-action">{action}</div>}
        </div>
    );
}
export function NewDocumentButton() {
    return (
        <Link className="btn btn-primary" href="/documents/create">
            <Plus size={17} />
            Register document
        </Link>
    );
}
export function Empty({
    title = 'No documents yet',
    description = 'Your records will appear here once your office registers its first document.',
    action,
}: {
    title?: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="empty">
            <span className="empty-icon">
                <FileText size={28} strokeWidth={1.4} />
            </span>
            <h3>{title}</h3>
            <p>{description}</p>
            {action}
        </div>
    );
}
const labels: Record<string, string> = {
    received: 'Received',
    awaiting_acknowledgment: 'Awaiting acknowledgment',
    in_progress: 'In progress',
    on_hold: 'On hold',
    returned: 'Returned',
    completed: 'Completed',
    released: 'Released',
    cancelled: 'Cancelled',
};
export function statusLabel(status: string) {
    return labels[status] || status.replaceAll('_', ' ');
}
export function StatusBadge({ status }: { status: string }) {
    return (
        <span className={`status status-${status}`}>
            <i />
            {statusLabel(status)}
        </span>
    );
}
export function date(value?: string | null, time = false) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        ...(time ? { hour: 'numeric', minute: '2-digit' } : {}),
    }).format(new Date(value));
}
export function Pagination({ page }: { page: Paginated<unknown> }) {
    return (
        <div className="pagination">
            <span>{page.total ? `${page.from}–${page.to} of ${page.total} records` : '0 records'}</span>
            <div>
                {page.links.map((link, index) =>
                    link.url ? (
                        <Link
                            key={index}
                            href={link.url}
                            preserveScroll
                            className={`page-link ${link.active ? 'selected' : ''}`}
                            aria-label={index === 0 ? 'Previous page' : index === page.links.length - 1 ? 'Next page' : `Page ${link.label}`}
                        >
                            {index === 0 ? <ChevronLeft size={15} /> : index === page.links.length - 1 ? <ChevronRight size={15} /> : link.label}
                        </Link>
                    ) : (
                        <span key={index} className="page-link disabled">
                            {index === 0 ? <ChevronLeft size={15} /> : index === page.links.length - 1 ? <ChevronRight size={15} /> : '…'}
                        </span>
                    ),
                )}
            </div>
        </div>
    );
}
export function DocumentTable({ documents, create = false }: { documents: Doc[]; create?: boolean }) {
    if (!documents.length) return <Empty action={create ? <NewDocumentButton /> : undefined} />;
    return (
        <div className="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Document / reference</th>
                        <th>Category</th>
                        <th>Current office</th>
                        <th>Status</th>
                        <th>Received</th>
                        <th>
                            <span className="sr-only">Open</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {documents.map((d) => (
                        <tr key={d.id}>
                            <td>
                                <Link href={`/documents/${d.id}`} className="record-title">
                                    {d.subject}
                                </Link>
                                <span className="record-reference">
                                    {d.tracking_number}
                                    {d.priority === 'urgent' && <b className="urgent">Urgent</b>}
                                    {d.confidentiality === 'confidential' && <b> · Confidential</b>}
                                </span>
                            </td>
                            <td>{d.type.name}</td>
                            <td>{d.department.name}</td>
                            <td>
                                <StatusBadge status={d.status} />
                            </td>
                            <td className="nowrap">{date(d.received_at)}</td>
                            <td>
                                <Link href={`/documents/${d.id}`} className="icon-btn" aria-label={`Open ${d.tracking_number}`}>
                                    <ArrowRight size={17} />
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
export function FilterBar({
    filters,
    departments = [],
    types = [],
    statuses = [],
    url,
    dates = true,
}: {
    filters: Filters;
    departments?: Office[];
    types?: Category[];
    statuses?: StatusOption[];
    url: string;
    dates?: boolean;
}) {
    const [values, setValues] = useState<Filters>(filters);
    function submit(e: FormEvent) {
        e.preventDefault();
        router.get(url, values, { preserveState: true, preserveScroll: true });
    }
    return (
        <form className="filter-bar" onSubmit={submit}>
            <label className="search-field">
                <Search size={17} />
                <input
                    aria-label="Search documents"
                    placeholder="Search reference, subject or sender…"
                    value={values.search || ''}
                    onChange={(e) => setValues({ ...values, search: e.target.value })}
                />
            </label>
            {!!statuses.length && (
                <Select aria-label="Filter by status" value={values.status || ''} onChange={(e) => setValues({ ...values, status: e.target.value })}>
                    <option value="">All statuses</option>
                    {statuses.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </Select>
            )}
            <Select
                aria-label="Filter by office"
                value={values.department_id || ''}
                onChange={(e) => setValues({ ...values, department_id: e.target.value })}
            >
                <option value="">All authorized offices</option>
                {departments.map((d) => (
                    <option key={d.id} value={d.id}>
                        {d.name}
                    </option>
                ))}
            </Select>
            {!!types.length && (
                <Select
                    aria-label="Filter by category"
                    value={values.document_type_id || ''}
                    onChange={(e) => setValues({ ...values, document_type_id: e.target.value })}
                >
                    <option value="">All categories</option>
                    {types.map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name}
                        </option>
                    ))}
                </Select>
            )}
            {dates && (
                <>
                    <Input
                        type="date"
                        aria-label="Received from date"
                        value={values.from || ''}
                        onChange={(e) => setValues({ ...values, from: e.target.value })}
                    />
                    <Input
                        type="date"
                        aria-label="Received through date"
                        min={values.from}
                        value={values.to || ''}
                        onChange={(e) => setValues({ ...values, to: e.target.value })}
                    />
                </>
            )}
            <button className="btn btn-secondary" type="submit">
                Apply
            </button>
            <Link className="filter-reset" href={url} onClick={() => setValues({})}>
                Reset
            </Link>
        </form>
    );
}
export function MetricCards({ metrics }: { metrics: Metrics }) {
    return (
        <div className="metric-grid">
            {[
                { label: 'Total documents', value: metrics.total, note: 'Within selected scope', cls: 'blue' },
                { label: 'Pending', value: metrics.pending, note: 'Received & awaiting receipt', cls: 'amber' },
                { label: 'In progress', value: metrics.in_progress, note: 'Includes held & returned', cls: 'violet' },
                { label: 'Completed', value: metrics.completed, note: 'Completed & released', cls: 'green' },
                { label: 'Overdue', value: metrics.overdue, note: 'Open & past target date', cls: 'rose' },
            ].map((m) => (
                <div className={`metric-card metric-${m.cls}`} key={m.label}>
                    <div>
                        <span>{m.label}</span>
                        <span className="metric-dot" />
                    </div>
                    <strong>{m.value.toLocaleString()}</strong>
                    <small>{m.note}</small>
                </div>
            ))}
        </div>
    );
}
export function ExportButton({ filters }: { filters: Filters }) {
    return (
        <a
            className="btn btn-secondary"
            href={`/reports/export?${new URLSearchParams(Object.entries(filters).filter(([, v]) => Boolean(v))).toString()}`}
        >
            <ArrowDownToLine size={17} />
            Export CSV
        </a>
    );
}
export function SaveButton({ processing, label = 'Save changes' }: { processing: boolean; label?: string }) {
    return (
        <button type="submit" className="btn btn-primary" disabled={processing}>
            <Check size={16} />
            {processing ? 'Saving…' : label}
        </button>
    );
}
