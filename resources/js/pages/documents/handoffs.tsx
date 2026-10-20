import { date, Empty, Input, NewDocumentButton, PageTitle, Pagination, Select } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Handoff, HandoffFilters, Office, Paginated } from '@/types/umdts';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CornerDownLeft, LockKeyhole, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const stateLabels = { pending: 'Awaiting receipt', acknowledged: 'Received', rejected: 'Rejected', cancelled: 'Cancelled' };

export default function Handoffs({
    transfers,
    filters,
    mode,
    view,
    counts,
    departments,
    canCreate,
}: {
    transfers: Paginated<Handoff>;
    filters: HandoffFilters;
    mode: 'inbox' | 'outgoing';
    view: 'pending' | 'received' | 'sent';
    counts: { pending: number; received: number };
    departments: Office[];
    canCreate: boolean;
}) {
    const incoming = mode === 'inbox';
    const received = view === 'received';
    const url = incoming ? '/inbox' : '/outgoing';
    const title = incoming ? 'Incoming documents' : 'Outgoing documents';
    const [values, setValues] = useState<HandoffFilters>(filters);
    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(url, { ...values, ...(incoming ? { view } : {}) }, { preserveScroll: true });
    }
    return (
        <UmdtsLayout>
            <Head title={title} />
            <PageTitle
                eyebrow="DOCUMENT WORKSPACE"
                title={title}
                description={
                    incoming
                        ? 'Receive documents addressed to your office and review acknowledged handoffs.'
                        : 'Follow every handoff sent by your office, including accepted and returned movements.'
                }
                action={canCreate ? <NewDocumentButton /> : undefined}
            />
            {incoming && (
                <nav className="handoff-tabs" aria-label="Incoming document views">
                    <Link href="/inbox" className={received ? '' : 'selected'} aria-current={!received ? 'page' : undefined}>
                        Awaiting receipt <span>{counts.pending}</span>
                    </Link>
                    <Link href="/inbox?view=received" className={received ? 'selected' : ''} aria-current={received ? 'page' : undefined}>
                        Received history <span>{counts.received}</span>
                    </Link>
                </nav>
            )}
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        {incoming ? (received ? 'Received history' : 'Awaiting your acknowledgment') : 'Sent handoffs'}
                        <span className="count-chip">{transfers.total}</span>
                    </h2>
                </div>
                <form className="filter-bar handoff-filters" onSubmit={submit}>
                    <label className="search-field">
                        <Search size={17} />
                        <input
                            aria-label="Search handoff reference or accessible subject"
                            placeholder="Search reference or accessible subject…"
                            value={values.search || ''}
                            onChange={(e) => setValues({ ...values, search: e.target.value })}
                        />
                    </label>
                    <Select
                        aria-label={incoming ? 'Sending office' : 'Destination office'}
                        value={values.office_id || ''}
                        onChange={(e) => setValues({ ...values, office_id: e.target.value })}
                    >
                        <option value="">{incoming ? 'All sending offices' : 'All destinations'}</option>
                        {departments.map((office) => (
                            <option key={office.id} value={office.id}>
                                {office.name}
                            </option>
                        ))}
                    </Select>
                    {!incoming && (
                        <Select
                            aria-label="Handoff status"
                            value={values.transfer_status || ''}
                            onChange={(e) => setValues({ ...values, transfer_status: e.target.value })}
                        >
                            <option value="">All handoff statuses</option>
                            {Object.entries(stateLabels).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </Select>
                    )}
                    <Select
                        aria-label="Handoff type"
                        value={values.handoff_type || ''}
                        onChange={(e) => setValues({ ...values, handoff_type: e.target.value })}
                    >
                        <option value="">All movements</option>
                        <option value="forward">Forwarded</option>
                        <option value="return">Return for correction</option>
                    </Select>
                    <Input
                        type="date"
                        aria-label={received ? 'Received from date' : 'Sent from date'}
                        value={values.from || ''}
                        onChange={(e) => setValues({ ...values, from: e.target.value })}
                    />
                    <Input
                        type="date"
                        aria-label={received ? 'Received through date' : 'Sent through date'}
                        value={values.to || ''}
                        min={values.from}
                        onChange={(e) => setValues({ ...values, to: e.target.value })}
                    />
                    <button className="btn btn-secondary" type="submit">
                        Apply
                    </button>
                    <Link className="filter-reset" href={received ? '/inbox?view=received' : url}>
                        Reset
                    </Link>
                </form>
                <p className="handoff-help">
                    {received ? 'Dates filter when your office acknowledged receipt.' : 'Dates filter when the sending office initiated the handoff.'}{' '}
                    Each movement keeps its own history. Record details are available within your current access.
                </p>
                {transfers.data.length ? (
                    <div className="table-scroll">
                        <table className="handoff-table">
                            <thead>
                                <tr>
                                    <th>Document / reference</th>
                                    <th>{incoming ? 'From office' : 'To office'}</th>
                                    <th>Movement</th>
                                    <th>Handoff status</th>
                                    <th>Sent</th>
                                    <th>Received / resolved</th>
                                    <th>
                                        <span className="sr-only">Record access</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {transfers.data.map((handoff) => (
                                    <tr key={handoff.id}>
                                        <td>
                                            {handoff.can_open && handoff.document_id ? (
                                                <Link className="record-title" href={`/documents/${handoff.document_id}`}>
                                                    {handoff.subject}
                                                </Link>
                                            ) : (
                                                <b>Office handoff record</b>
                                            )}
                                            <span className="record-reference mono">{handoff.reference}</span>
                                        </td>
                                        <td>{incoming ? handoff.from_office : handoff.to_office}</td>
                                        <td>
                                            <span className="handoff-kind">
                                                {handoff.handoff_type === 'return' ? <CornerDownLeft size={15} /> : <ArrowRight size={15} />}
                                                {handoff.handoff_type === 'return' ? 'For correction' : 'Forwarded'}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`handoff-state handoff-${handoff.status}`}>{stateLabels[handoff.status]}</span>
                                        </td>
                                        <td className="nowrap">{date(handoff.sent_at, true)}</td>
                                        <td className="nowrap">
                                            {handoff.acknowledged_at || handoff.resolved_at
                                                ? date(handoff.acknowledged_at || handoff.resolved_at, true)
                                                : 'Awaiting action'}
                                        </td>
                                        <td>
                                            {handoff.can_open && handoff.document_id ? (
                                                <Link
                                                    className="icon-btn"
                                                    href={`/documents/${handoff.document_id}`}
                                                    aria-label={`Open ${handoff.reference}`}
                                                >
                                                    <ArrowRight size={17} />
                                                </Link>
                                            ) : (
                                                <span className="handoff-restricted">
                                                    <LockKeyhole size={14} />
                                                    Details restricted
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        title={incoming ? (received ? 'No received handoffs yet' : 'No handoffs awaiting receipt') : 'No sent handoffs yet'}
                        description={
                            received
                                ? 'Acknowledged movements stay here after processing or forwarding to another office.'
                                : incoming
                                  ? 'New handoffs addressed to your office will appear here.'
                                  : 'Forwarded and returned movements will remain in this history after acknowledgment.'
                        }
                    />
                )}
                <Pagination page={transfers} />
            </section>
        </UmdtsLayout>
    );
}
