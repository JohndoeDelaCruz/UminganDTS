import { date, Field, SaveButton, Select, Textarea } from '@/components/umdts';
import type { Doc, Handler } from '@/types/umdts';
import { useForm } from '@inertiajs/react';
import { ClipboardList, UserRound } from 'lucide-react';
import type { FormEvent } from 'react';

export const workTypes: Record<string, string> = {
    review: 'Review document',
    check_requirements: 'Check requirements',
    prepare_response: 'Prepare response',
};
export const workResults: Record<string, string> = { reviewed: 'Review completed', verified: 'Requirements verified', prepared: 'Response prepared' };
export const workStates: Record<string, string> = { assigned: 'Assigned', in_progress: 'Working', finished: 'Ready to forward', closed: 'Closed' };

export default function DocumentOfficeWork({
    document: doc,
    handlers,
    canUpdate,
    pending,
}: {
    document: Doc;
    handlers: Handler[];
    canUpdate: boolean;
    pending: boolean;
}) {
    const work = doc.current_office_work;
    const terminal = ['completed', 'released', 'cancelled'].includes(doc.status);
    const editable = canUpdate && !terminal && !pending;
    const paused = doc.status === 'on_hold';
    const eligible = !work || handlers.some((handler) => handler.id === work.assigned_to);
    const form = useForm({
        action: '',
        revision: doc.office_work_revision,
        staff_id: '',
        work_type: work?.work_type || 'review',
        review_result: work?.work_type === 'check_requirements' ? 'verified' : work?.work_type === 'prepare_response' ? 'prepared' : 'reviewed',
        remarks: '',
    });

    function perform(action: string) {
        form.transform((data) => ({ ...data, action }));
        form.post(`/documents/${doc.id}/office-work`, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function submit(event: FormEvent, action: string) {
        event.preventDefault();
        perform(action);
    }

    if (!editable && !work) return null;

    return (
        <section className="panel office-work-panel">
            <div className="panel-heading">
                <h2>
                    <ClipboardList size={17} />
                    Office work
                </h2>
                {work && (
                    <span className={`office-work-badge work-${work.status}`}>
                        {work.status === 'finished' && (pending || terminal) ? 'Finished' : workStates[work.status]}
                    </span>
                )}
            </div>
            <div className="panel-content form-stack">
                {work ? (
                    <div className="office-handler">
                        <UserRound size={17} />
                        <div>
                            <b>{work.handler.name}</b>
                            <small>Assigned {date(work.assigned_at, true)}</small>
                        </div>
                    </div>
                ) : (
                    <p className="muted">Assign a handler for your office’s review.</p>
                )}
                {work?.started_at && (
                    <small className="muted">
                        {workTypes[work.work_type]} · Started {date(work.started_at, true)}
                    </small>
                )}
                {work?.status === 'finished' && (
                    <div className="office-work-result">
                        <b>{workResults[work.review_result || '']}</b>
                        <small>{date(work.finished_at, true)}</small>
                        {work.remarks && <p className="pre-wrap">{work.remarks}</p>}
                    </div>
                )}
                {work?.status === 'closed' && <p className="muted">This office task closed when the document was {work.closed_reason}.</p>}
                {editable && (
                    <>
                        {paused && <p className="muted">Processing is on hold. Resume it under Document actions to continue office work.</p>}
                        {work && work.status !== 'finished' && !eligible && (
                            <p className="field-error" role="alert">
                                The handler is no longer active in this office. Assign another handler to continue.
                            </p>
                        )}
                        {!work && (
                            <button className="btn btn-primary" type="button" disabled={form.processing} onClick={() => perform('claim')}>
                                Claim document
                            </button>
                        )}
                        {work?.status === 'assigned' && !paused && eligible && (
                            <form className="form-stack" onSubmit={(event) => submit(event, 'start')}>
                                <Field label="Office task">
                                    <Select value={form.data.work_type} onChange={(event) => form.setData('work_type', event.target.value)}>
                                        {Object.entries(workTypes).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <SaveButton processing={form.processing} label="Start office work" />
                            </form>
                        )}
                        {work?.status === 'in_progress' && !paused && eligible && (
                            <form className="form-stack" onSubmit={(event) => submit(event, 'finish')}>
                                <Field label="Review result">
                                    <Select value={form.data.review_result} onChange={(event) => form.setData('review_result', event.target.value)}>
                                        {Object.entries(workResults).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <details className="office-work-options">
                                    <summary>Add result details (optional)</summary>
                                    <Field label="Result details">
                                        <Textarea
                                            maxLength={2000}
                                            value={form.data.remarks}
                                            onChange={(event) => form.setData('remarks', event.target.value)}
                                        />
                                    </Field>
                                </details>
                                <SaveButton processing={form.processing} label="Finish office work" />
                            </form>
                        )}
                        {work?.status === 'finished' && (
                            <p className="muted">
                                Your office task is finished. Forward the document, or complete it under Document actions when all offices are done.
                            </p>
                        )}
                        <details className="office-work-options">
                            <summary>
                                {!work ? 'Assign someone else' : work.status === 'finished' ? 'Start another office task' : 'Change handler'}
                            </summary>
                            <form className="form-stack" onSubmit={(event) => submit(event, 'assign')}>
                                <Field label="Office handler">
                                    <Select required value={form.data.staff_id} onChange={(event) => form.setData('staff_id', event.target.value)}>
                                        <option value="">Choose an active staff member</option>
                                        {handlers.map((handler) => (
                                            <option key={handler.id} value={handler.id}>
                                                {handler.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <SaveButton processing={form.processing} label={work?.status === 'finished' ? 'Assign new task' : 'Assign handler'} />
                            </form>
                        </details>
                        <small className="muted">Times and timeline entries are recorded automatically.</small>
                    </>
                )}
                {pending && <small className="muted">Office work stays with the sending office until the handoff is acknowledged.</small>}
            </div>
        </section>
    );
}
