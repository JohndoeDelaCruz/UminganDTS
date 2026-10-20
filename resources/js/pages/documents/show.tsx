import DocumentActionSection from '@/components/document-action-section';
import DocumentOfficeWork, { workResults, workStates, workTypes } from '@/components/document-office-work';
import DocumentProcessingActions from '@/components/document-processing-actions';
import { date, Field, Input, PageTitle, SaveButton, Select, StatusBadge, statusLabel, Textarea } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { SharedData } from '@/types';
import type { Doc, Handler, Office, StatusOption } from '@/types/umdts';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ClipboardList,
    Clock3,
    CornerDownLeft,
    Download,
    FileText,
    Globe,
    LockKeyhole,
    MessageSquareText,
    Paperclip,
    Printer,
    Send,
    ShieldCheck,
} from 'lucide-react';
import { type FormEvent } from 'react';

function eventTitle(event: NonNullable<Doc['events']>[number]) {
    if (event.details?.summary) return event.details.summary;
    const titles: Record<string, string> = {
        registered: 'Document registered',
        forwarded: 'Document forwarded',
        transfer_acknowledge: 'Receipt acknowledged',
        transfer_reject: 'Handoff rejected',
        transfer_cancel: 'Handoff cancelled',
        return_requested: 'Return for correction sent',
        return_acknowledged: 'Return received for correction',
        return_rejected: 'Return rejected',
        return_cancelled: 'Return cancelled',
        reopened: 'Processing reopened',
        remark_added: 'Staff note',
        public_credential_issued: 'Receipt access code generated',
        public_credential_revoked: 'Public tracking disabled',
        attachment_uploaded: 'Supporting file added',
    };
    if (event.event_type === 'status_changed' && event.to_status) return `Status changed to ${statusLabel(event.to_status)}`;
    return titles[event.event_type] || event.event_type.replaceAll('_', ' ');
}

export default function Show({
    document: doc,
    departments,
    canUpdate,
    handlers,
    canManagePublic,
    nextStatuses,
    returnDepartmentId,
    receiptCredential,
    publicTrackingUrl,
}: {
    document: Doc;
    departments: Office[];
    canUpdate: boolean;
    handlers: Handler[];
    canManagePublic: boolean;
    nextStatuses: StatusOption[];
    returnDepartmentId: number | null;
    receiptCredential: { reference: string; secret: string } | null;
    publicTrackingUrl: string;
}) {
    const { auth } = usePage<SharedData>().props;
    const receiptCode = receiptCredential?.secret.match(/.{1,4}/g)?.join('-') || '';
    const forward = useForm({ to_department_id: '', remarks: '' });
    const correction = useForm({ to_department_id: returnDepartmentId?.toString() || '', reason: '' });
    const note = useForm({ remarks: '' });
    const handoff = useForm({ action: 'acknowledge', reason: '' });
    const publicForm = useForm({ action: 'issue' });
    const upload = useForm({ attachment: null as File | null });
    const pending = doc.transfers?.find((t) => t.status === 'pending');
    const isDestination = pending?.to_department_id === auth.user.department_id;
    const terminal = ['completed', 'released', 'cancelled'].includes(doc.status);
    function submit(e: FormEvent, action: () => void) {
        e.preventDefault();
        action();
    }
    return (
        <UmdtsLayout>
            <Head title={doc.tracking_number} />
            <div className="no-print">
                <PageTitle
                    eyebrow={doc.tracking_number}
                    title={doc.subject}
                    description={`Registered ${date(doc.created_at, true)} · ${doc.type.name}`}
                    action={
                        <>
                            <Link className="btn btn-secondary" href="/documents">
                                <ArrowLeft size={16} />
                                Register
                            </Link>
                            <button type="button" className="btn btn-secondary" onClick={() => window.print()}>
                                <Printer size={16} />
                                Print receipt
                            </button>
                        </>
                    }
                />
            </div>
            <section className="print-only receipt">
                <h1>Umingan Document Tracking System</h1>
                <h2>Document acknowledgment / route slip</h2>
                <strong>{doc.tracking_number}</strong>
                <dl>
                    <div>
                        <dt>Subject</dt>
                        <dd>{doc.subject}</dd>
                    </div>
                    <div>
                        <dt>Category</dt>
                        <dd>{doc.type.name}</dd>
                    </div>
                    <div>
                        <dt>Received</dt>
                        <dd>{date(doc.received_at, true)}</dd>
                    </div>
                    <div>
                        <dt>Current office</dt>
                        <dd>{doc.department.name}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd>{statusLabel(doc.status)}</dd>
                    </div>
                </dl>
                {receiptCredential && (
                    <>
                        <h3>Track your document</h3>
                        <p>Visit {publicTrackingUrl}</p>
                        <p>Enter the tracking reference above and this access code:</p>
                        <code>{receiptCode}</code>
                        <p>The dash is optional. Keep your receipt and access code private.</p>
                    </>
                )}
            </section>
            <div className="document-detail no-print">
                <div className="document-main">
                    <section className="panel">
                        <div className="panel-heading">
                            <h2>
                                <FileText size={18} />
                                Record overview
                            </h2>
                            <StatusBadge status={doc.status} />
                        </div>
                        <dl className="detail-grid">
                            <div>
                                <dt>Tracking reference</dt>
                                <dd className="mono">{doc.tracking_number}</dd>
                            </div>
                            <div>
                                <dt>Current custodian</dt>
                                <dd>{doc.department.name}</dd>
                            </div>
                            <div>
                                <dt>Sender / organization</dt>
                                <dd>{doc.sender_name}</dd>
                            </div>
                            <div>
                                <dt>Classification</dt>
                                <dd className="capitalize">{doc.confidentiality}</dd>
                            </div>
                            <div>
                                <dt>Direction / priority</dt>
                                <dd className="capitalize">
                                    {doc.direction} · {doc.priority}
                                </dd>
                            </div>
                            <div>
                                <dt>Received at</dt>
                                <dd>{date(doc.received_at, true)}</dd>
                            </div>
                            <div>
                                <dt>Processing target</dt>
                                <dd>{doc.due_at ? date(doc.due_at, true) : 'No target configured'}</dd>
                            </div>
                            <div>
                                <dt>Originating reference</dt>
                                <dd>{doc.originating_reference || 'Not provided'}</dd>
                            </div>
                            {doc.contact_private && (
                                <div className="span-2">
                                    <dt>
                                        <LockKeyhole size={12} />
                                        Private contact details
                                    </dt>
                                    <dd>{doc.contact_private}</dd>
                                </div>
                            )}
                            {doc.description && (
                                <div className="span-2">
                                    <dt>Description</dt>
                                    <dd className="pre-wrap">{doc.description}</dd>
                                </div>
                            )}
                            {doc.completed_at && (
                                <div>
                                    <dt>Completed</dt>
                                    <dd>{date(doc.completed_at, true)}</dd>
                                </div>
                            )}
                            {doc.released_at && (
                                <div>
                                    <dt>Released</dt>
                                    <dd>
                                        {date(doc.released_at, true)} · {doc.release_method}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </section>
                    {pending && (
                        <section className="panel handoff-panel">
                            <div className="panel-heading">
                                <h2>
                                    <Send size={18} />
                                    {pending.handoff_type === 'return' ? 'Return for correction awaiting receipt' : 'Pending handoff'}
                                </h2>
                                <span className="subtle-tag">Awaiting acknowledgment</span>
                            </div>
                            <div className="panel-content">
                                <div className="handoff-route">
                                    <b>{pending.from_department.name}</b>
                                    <ArrowRight size={18} />
                                    <b>{pending.to_department.name}</b>
                                </div>
                                <p className="muted">Sent {date(pending.sent_at, true)}. The sending office retains custody until accepted.</p>
                                {pending.handoff_type === 'return' && (
                                    <p className="muted">
                                        Acknowledging this return assigns the document to the receiving office with Returned status for correction.
                                    </p>
                                )}
                                {pending.routing_remarks && <p className="pre-wrap">{pending.routing_remarks}</p>}
                                {(canUpdate || isDestination) && (
                                    <form
                                        className="form-stack"
                                        onSubmit={(e) =>
                                            submit(e, () => {
                                                handoff.transform((data) => ({ ...data, action: isDestination ? data.action : 'cancel' }));
                                                handoff.post(`/documents/${doc.id}/transfers/${pending.id}`);
                                            })
                                        }
                                    >
                                        <Field label="Handoff action" error={handoff.errors.action}>
                                            <Select
                                                value={isDestination ? handoff.data.action : 'cancel'}
                                                onChange={(e) => handoff.setData('action', e.target.value)}
                                            >
                                                {isDestination ? (
                                                    <>
                                                        <option value="acknowledge">Acknowledge receipt</option>
                                                        <option value="reject">Reject handoff</option>
                                                    </>
                                                ) : (
                                                    <option value="cancel">Cancel pending handoff</option>
                                                )}
                                            </Select>
                                        </Field>
                                        <Field label="Reason / note" error={handoff.errors.reason} hint="Required when rejecting or cancelling.">
                                            <Textarea
                                                value={handoff.data.reason}
                                                maxLength={2000}
                                                onChange={(e) => handoff.setData('reason', e.target.value)}
                                            />
                                        </Field>
                                        <button type="submit" className="btn btn-primary" disabled={handoff.processing}>
                                            {handoff.processing ? 'Recording…' : 'Record handoff action'}
                                        </button>
                                    </form>
                                )}
                            </div>
                        </section>
                    )}
                    <section className="panel">
                        <div className="panel-heading">
                            <h2>
                                <Clock3 size={18} />
                                Permanent timeline
                            </h2>
                            <span className="subtle-tag">{doc.events?.length || 0} events</span>
                        </div>
                        <ol className="event-timeline">
                            {doc.events?.map((event) => (
                                <li key={event.id}>
                                    <span className="event-dot" />
                                    <div className="event-top">
                                        <b>{eventTitle(event)}</b>
                                        <time>{date(event.occurred_at, true)}</time>
                                    </div>
                                    <p>
                                        {event.actor.name}
                                        {event.from_department && <> · {event.from_department.name}</>}
                                        {event.to_department && event.to_department.id !== event.from_department?.id && (
                                            <> → {event.to_department.name}</>
                                        )}
                                    </p>
                                    {event.to_status && <StatusBadge status={event.to_status} />}
                                    {event.details?.remarks && <blockquote>{event.details.remarks}</blockquote>}
                                    {event.details?.release_method && <p>Release method: {event.details.release_method}</p>}
                                </li>
                            ))}
                        </ol>
                    </section>
                    {!!doc.office_works?.length && (
                        <DocumentActionSection
                            title="Office work history"
                            icon={ClipboardList}
                            badge={<span className="subtle-tag">{doc.office_works.length} tasks</span>}
                        >
                            <ol className="office-work-history">
                                {doc.office_works.map((work) => (
                                    <li key={work.id}>
                                        <div>
                                            <b>{workTypes[work.work_type]}</b>
                                            <span className={`office-work-badge work-${work.status}`}>
                                                {work.status === 'finished' ? 'Finished' : workStates[work.status]}
                                            </span>
                                        </div>
                                        <p>
                                            {work.handler.name} · {work.department?.name}
                                        </p>
                                        <small>
                                            Assigned {date(work.assigned_at, true)}
                                            {work.started_at && <> · Started {date(work.started_at, true)}</>}
                                            {work.finished_at && <> · Finished {date(work.finished_at, true)}</>}
                                        </small>
                                        {work.review_result && <p>{workResults[work.review_result]}</p>}
                                        {work.remarks && <p className="pre-wrap">{work.remarks}</p>}
                                        {work.closed_at && (
                                            <small>
                                                Closed {date(work.closed_at, true)} · {work.closed_reason}
                                            </small>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </DocumentActionSection>
                    )}
                    <section className="panel">
                        <div className="panel-heading">
                            <h2>
                                <Paperclip size={18} />
                                Private attachments<span className="count-chip">{doc.attachments?.length || 0}</span>
                            </h2>
                        </div>
                        <div className="panel-content">
                            {doc.attachments?.length ? (
                                <div className="attachment-list">
                                    {doc.attachments.map((a) => (
                                        <a key={a.id} href={`/attachments/${a.id}/download`}>
                                            <FileText size={20} />
                                            <div>
                                                <b>{a.filename_display}</b>
                                                <small>
                                                    {(a.bytes / 1024).toFixed(1)} KB · {a.mime_type}
                                                </small>
                                            </div>
                                            <Download size={17} />
                                        </a>
                                    ))}
                                </div>
                            ) : (
                                <p className="muted">No attachments have been added.</p>
                            )}
                            {canUpdate && !terminal && !pending && (
                                <form
                                    className="form-stack upload-form"
                                    onSubmit={(e) =>
                                        submit(e, () =>
                                            upload.post(`/documents/${doc.id}/attachments`, { forceFormData: true, onSuccess: () => upload.reset() }),
                                        )
                                    }
                                >
                                    <Field label="Add supporting file" hint="PDF, JPG or PNG · up to 10 MB">
                                        <Input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            required
                                            onChange={(e) => upload.setData('attachment', e.target.files?.[0] || null)}
                                        />
                                    </Field>
                                    <SaveButton processing={upload.processing} label="Upload private file" />
                                </form>
                            )}
                        </div>
                    </section>
                </div>
                <aside className="document-actions">
                    <DocumentOfficeWork
                        key={`${doc.id}-${doc.office_work_revision}`}
                        document={doc}
                        handlers={handlers}
                        canUpdate={canUpdate}
                        pending={!!pending}
                    />
                    {canUpdate && !pending && !!nextStatuses.length && (
                        <DocumentProcessingActions
                            key={`${doc.id}-${doc.status}`}
                            documentId={doc.id}
                            status={doc.status}
                            nextStatuses={nextStatuses}
                        />
                    )}
                    {canUpdate &&
                        !pending &&
                        !terminal &&
                        doc.status !== 'on_hold' &&
                        (!doc.current_office_work || doc.current_office_work.status === 'finished') && (
                            <DocumentActionSection title="Forward document" icon={Send}>
                                <form
                                    className="panel-content form-stack"
                                    onSubmit={(e) =>
                                        submit(e, () => forward.post(`/documents/${doc.id}/forward`, { onSuccess: () => forward.reset() }))
                                    }
                                >
                                    <Field label="Destination office" error={forward.errors.to_department_id}>
                                        <Select
                                            required
                                            value={forward.data.to_department_id}
                                            onChange={(e) => forward.setData('to_department_id', e.target.value)}
                                        >
                                            <option value="">Choose an active office</option>
                                            {departments.map((d) => (
                                                <option value={d.id} key={d.id}>
                                                    {d.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Routing note (optional)" error={forward.errors.remarks}>
                                        <Textarea
                                            maxLength={2000}
                                            value={forward.data.remarks}
                                            onChange={(e) => forward.setData('remarks', e.target.value)}
                                        />
                                    </Field>
                                    <SaveButton processing={forward.processing} label="Forward to office" />
                                </form>
                            </DocumentActionSection>
                        )}
                    {canUpdate && !pending && !terminal && doc.status !== 'on_hold' && (
                        <DocumentActionSection title="Return for correction" icon={CornerDownLeft}>
                            <form
                                className="panel-content form-stack"
                                onSubmit={(e) =>
                                    submit(e, () => correction.post(`/documents/${doc.id}/return`, { onSuccess: () => correction.reset('reason') }))
                                }
                            >
                                <p className="muted">
                                    Choose the office responsible for the correction. The previous sending office is selected when available.
                                </p>
                                <Field label="Return to office" error={correction.errors.to_department_id}>
                                    <Select
                                        required
                                        value={correction.data.to_department_id}
                                        onChange={(e) => correction.setData('to_department_id', e.target.value)}
                                    >
                                        <option value="">Choose the responsible office</option>
                                        {departments.map((office) => (
                                            <option key={office.id} value={office.id}>
                                                {office.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    label="Corrections needed"
                                    error={correction.errors.reason}
                                    hint="Required. Explain what the receiving office needs to correct."
                                >
                                    <Textarea
                                        required
                                        maxLength={2000}
                                        value={correction.data.reason}
                                        onChange={(e) => correction.setData('reason', e.target.value)}
                                    />
                                </Field>
                                <SaveButton processing={correction.processing} label="Send return for correction" />
                                <small className="muted">Custody changes only when the destination acknowledges receipt.</small>
                            </form>
                        </DocumentActionSection>
                    )}
                    {canUpdate && !pending && !terminal && (
                        <DocumentActionSection title="Add a note" icon={MessageSquareText} badge={<span className="subtle-tag">Optional</span>}>
                            <form
                                className="panel-content form-stack"
                                onSubmit={(e) => submit(e, () => note.post(`/documents/${doc.id}/remarks`, { onSuccess: () => note.reset() }))}
                            >
                                <Field label="Additional information" error={note.errors.remarks}>
                                    <Textarea
                                        maxLength={2000}
                                        required
                                        value={note.data.remarks}
                                        onChange={(e) => note.setData('remarks', e.target.value)}
                                    />
                                </Field>
                                <SaveButton processing={note.processing} label="Add to timeline" />
                            </form>
                        </DocumentActionSection>
                    )}
                    <DocumentActionSection
                        title="Citizen receipt"
                        icon={Globe}
                        badge={
                            <span className={`mini-state ${doc.public_tracking_enabled ? 'enabled' : ''}`}>
                                {doc.public_tracking_enabled ? 'Enabled' : 'Disabled'}
                            </span>
                        }
                    >
                        <div className="panel-content form-stack">
                            <p className="muted">Public lookup shows minimal status information. Names, notes and attachments remain private.</p>
                            {receiptCredential && (
                                <div className="credential-box">
                                    <b>Citizen receipt access code</b>
                                    <p>Included automatically when you print the receipt. You can reprint it later.</p>
                                    <code>{receiptCode}</code>
                                    <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                                        <Printer size={16} />
                                        Print receipt
                                    </button>
                                    <button type="button" className="btn btn-secondary" onClick={() => navigator.clipboard.writeText(receiptCode)}>
                                        Copy access code
                                    </button>
                                </div>
                            )}
                            {canManagePublic && doc.public_tracking_enabled && !receiptCredential && (
                                <p className="muted">
                                    The original code remains valid. Generate a replacement below if a new receipt code is needed.
                                </p>
                            )}
                            {canManagePublic ? (
                                <details className="public-code-management">
                                    <summary>Manage access code</summary>
                                    <form
                                        className="form-stack"
                                        onSubmit={(e) =>
                                            submit(e, () =>
                                                publicForm.post(`/documents/${doc.id}/public-credential`, { onSuccess: () => publicForm.reset() }),
                                            )
                                        }
                                    >
                                        <Field label="Code action" hint="Replacing the code invalidates the code on previous receipts.">
                                            <Select value={publicForm.data.action} onChange={(e) => publicForm.setData('action', e.target.value)}>
                                                <option value="issue">
                                                    {doc.public_tracking_enabled ? 'Replace access code' : 'Generate receipt access code'}
                                                </option>
                                                {doc.public_tracking_enabled && <option value="revoke">Disable public tracking</option>}
                                            </Select>
                                        </Field>
                                        <SaveButton processing={publicForm.processing} label="Apply tracking change" />
                                    </form>
                                </details>
                            ) : (
                                <div className="inline-info">
                                    <ShieldCheck size={16} />
                                    {doc.confidentiality === 'confidential'
                                        ? 'Public tracking is unavailable for confidential documents.'
                                        : 'Only the current custodian can manage public credentials.'}
                                </div>
                            )}
                        </div>
                    </DocumentActionSection>
                </aside>
            </div>
        </UmdtsLayout>
    );
}
