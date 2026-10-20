import { workStates, workTypes } from '@/components/document-office-work';
import { date, Empty, Field, Input, PageTitle, Pagination, Select, StatusBadge } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Doc, Paginated } from '@/types/umdts';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, ClipboardList } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export default function MyWork({
    documents,
    filters,
    hasOffice,
}: {
    documents: Paginated<Doc>;
    filters: { search?: string; work_state?: string };
    hasOffice: boolean;
}) {
    const [search, setSearch] = useState(filters.search || '');
    const [state, setState] = useState(filters.work_state || '');
    function filter(event: FormEvent) {
        event.preventDefault();
        router.get('/my-work', { search, work_state: state });
    }
    return (
        <UmdtsLayout>
            <Head title="My Work" />
            <PageTitle
                eyebrow="YOUR OFFICE WORK"
                title="My Work"
                description="Documents assigned to you in your current office, from review to the next handoff."
                action={
                    <Link className="btn btn-secondary" href="/documents">
                        Browse documents
                        <ArrowRight size={16} />
                    </Link>
                }
            />
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        <ClipboardList size={18} />
                        Assigned to you<span className="count-chip">{documents.total}</span>
                    </h2>
                </div>
                <form className="my-work-filters" onSubmit={filter}>
                    <Field label="Find assigned work">
                        <Input
                            value={search}
                            maxLength={150}
                            placeholder="Reference or subject"
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </Field>
                    <Field label="Office work stage">
                        <Select value={state} onChange={(event) => setState(event.target.value)}>
                            <option value="">All stages</option>
                            <option value="assigned">Assigned</option>
                            <option value="in_progress">Working</option>
                            <option value="finished">Ready to forward</option>
                        </Select>
                    </Field>
                    <button className="btn btn-secondary" type="submit">
                        Apply
                    </button>
                    <Link className="text-link" href="/my-work">
                        Reset
                    </Link>
                </form>
                {documents.data.length ? (
                    <div className="table-scroll my-work-table" role="region" aria-label="Assigned documents" tabIndex={0}>
                        <table>
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Office task</th>
                                    <th>Document status</th>
                                    <th>Assigned</th>
                                    <th>
                                        <span className="sr-only">Open</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {documents.data.map((doc) => {
                                    const work = doc.current_office_work!;
                                    return (
                                        <tr key={doc.id}>
                                            <td>
                                                <Link className="record-title" href={`/documents/${doc.id}`}>
                                                    {doc.subject}
                                                </Link>
                                                <small className="my-work-reference">
                                                    {doc.tracking_number} · {doc.type.name}
                                                </small>
                                            </td>
                                            <td>
                                                <span className={`office-work-badge work-${work.status}`}>{workStates[work.status]}</span>
                                                <small className="my-work-reference">{workTypes[work.work_type]}</small>
                                            </td>
                                            <td>
                                                <StatusBadge status={doc.status} />
                                            </td>
                                            <td>{date(work.assigned_at, true)}</td>
                                            <td>
                                                <Link className="text-link" href={`/documents/${doc.id}`} aria-label={`Open ${doc.tracking_number}`}>
                                                    Open
                                                    <ArrowRight size={14} />
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        title={hasOffice ? 'No assigned work here' : 'An office assignment is needed'}
                        description={
                            hasOffice
                                ? 'Claim a document from the register, or ask a colleague to assign it to you. Sent handoffs appear in Outgoing until resolved.'
                                : 'Ask the administrator to assign your account to an active office.'
                        }
                    />
                )}
                <Pagination page={documents} />
            </section>
        </UmdtsLayout>
    );
}
