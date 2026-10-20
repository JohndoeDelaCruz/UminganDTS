import DocumentActionSection from '@/components/document-action-section';
import { Field, Input, SaveButton, Textarea } from '@/components/umdts';
import type { StatusOption } from '@/types/umdts';
import { useForm } from '@inertiajs/react';
import { Ban, CheckCircle2, ChevronDown, ClipboardCheck, PauseCircle, Play, RotateCcw, Send, type LucideIcon } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

type Action = { status: string; label: string; icon: LucideIcon; reasonLabel?: string; releaseMethod?: boolean };

export default function DocumentProcessingActions({
    documentId,
    status,
    nextStatuses,
}: {
    documentId: string;
    status: string;
    nextStatuses: StatusOption[];
}) {
    const form = useForm({ status: '', reason: '', release_method: '' });
    const actionErrors = form.errors as typeof form.errors & { transfer?: string };
    const [selected, setSelected] = useState<Action | null>(null);
    const [expanded, setExpanded] = useState(false);
    const moreActions = useRef<HTMLDetailsElement>(null);
    const reopening = ['completed', 'released', 'cancelled'].includes(status);
    const resuming = ['on_hold', 'returned'].includes(status);
    const available: Action[] = [
        {
            status: 'in_progress',
            label: reopening ? 'Reopen processing' : resuming ? 'Resume processing' : 'Start processing',
            icon: reopening ? RotateCcw : Play,
            ...(reopening ? { reasonLabel: 'Reason for reopening' } : {}),
        },
        { status: 'completed', label: 'Complete document', icon: CheckCircle2 },
        { status: 'released', label: 'Release document', icon: Send, releaseMethod: true },
        { status: 'on_hold', label: 'Put on hold', icon: PauseCircle, reasonLabel: 'Reason for hold' },
        { status: 'cancelled', label: 'Cancel document', icon: Ban, reasonLabel: 'Cancellation reason' },
    ];
    const actions = available.filter((action) => nextStatuses.some((next) => next.value === action.status));
    const primary = (status === 'completed' && actions.find((action) => action.status === 'released')) || actions[0];
    const secondary = actions.filter((action) => action !== primary);

    function perform(action: Action) {
        form.transform((data) => ({
            status: action.status,
            reason: action.reasonLabel ? data.reason : '',
            release_method: action.releaseMethod ? data.release_method : '',
        }));
        form.post(`/documents/${documentId}/status`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSelected(null);
            },
        });
    }

    function choose(action: Action) {
        form.clearErrors();
        form.reset();
        if (action.reasonLabel || action.releaseMethod) {
            setSelected(action);
            if (moreActions.current) moreActions.current.open = true;
        } else perform(action);
    }

    function back() {
        setSelected(null);
        form.clearErrors();
        if (moreActions.current) moreActions.current.open = false;
    }

    function confirm(event: FormEvent) {
        event.preventDefault();
        if (selected) perform(selected);
    }

    if (!actions.length) return null;

    return (
        <DocumentActionSection title="Document actions" icon={ClipboardCheck}>
            <div className="panel-content form-stack">
                {!(expanded && selected) && (
                    <button type="button" className="btn btn-primary processing-primary" disabled={form.processing} onClick={() => choose(primary)}>
                        <primary.icon size={16} />
                        {primary.label}
                    </button>
                )}
                <details
                    ref={moreActions}
                    className="processing-more-actions"
                    hidden={!secondary.length && !selected}
                    onToggle={(event) => {
                        setExpanded(event.currentTarget.open);
                        if (!event.currentTarget.open) setSelected(null);
                    }}
                >
                    <summary>
                        {selected?.label || 'More actions'}
                        <ChevronDown size={15} className="action-section-chevron" />
                    </summary>
                    {selected ? (
                        <form className="form-stack" onSubmit={confirm}>
                            {selected.reasonLabel && (
                                <Field label={selected.reasonLabel} error={form.errors.reason}>
                                    <Textarea
                                        autoFocus
                                        required
                                        maxLength={2000}
                                        value={form.data.reason}
                                        onChange={(event) => form.setData('reason', event.target.value)}
                                    />
                                </Field>
                            )}
                            {selected.releaseMethod && (
                                <Field label="Release method" error={form.errors.release_method}>
                                    <Input
                                        autoFocus
                                        required
                                        maxLength={180}
                                        placeholder="e.g. Collected at the office"
                                        value={form.data.release_method}
                                        onChange={(event) => form.setData('release_method', event.target.value)}
                                    />
                                </Field>
                            )}
                            <SaveButton
                                processing={form.processing}
                                label={selected.status === 'cancelled' ? 'Confirm cancellation' : selected.label}
                            />
                            <button type="button" className="btn btn-secondary" disabled={form.processing} onClick={back}>
                                Back
                            </button>
                        </form>
                    ) : (
                        <div className="processing-action-list">
                            {secondary.map((action) => (
                                <button
                                    key={action.status}
                                    type="button"
                                    className={`btn btn-secondary processing-action-${action.status}`}
                                    disabled={form.processing}
                                    onClick={() => choose(action)}
                                >
                                    <action.icon size={16} />
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    )}
                </details>
                {(actionErrors.status || actionErrors.transfer) && (
                    <p className="field-error" role="alert">
                        {actionErrors.status || actionErrors.transfer}
                    </p>
                )}
                <small className="muted">The status, time and timeline entry are recorded automatically.</small>
            </div>
        </DocumentActionSection>
    );
}
