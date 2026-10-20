import { Field, Input, PageTitle, SaveButton, Select, Textarea } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Category } from '@/types/umdts';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
export default function Create({ types }: { types: Category[] }) {
    const localDate = new Date(Date.now() + 8 * 60 * 60 * 1000).toISOString().slice(0, 16);
    const form = useForm({
        subject: '',
        document_type_id: '',
        direction: 'incoming',
        priority: 'normal',
        sender_name: '',
        contact_private: '',
        description: '',
        confidentiality: 'internal',
        originating_reference: '',
        received_at: localDate,
        attachment: null as File | null,
    });
    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/documents', { forceFormData: true });
    }
    return (
        <UmdtsLayout>
            <Head title="Register document" />
            <PageTitle
                eyebrow="NEW RECORD"
                title="Register a document"
                description="Start a traceable record. Your office becomes its first custodian."
                action={
                    <Link href="/documents" className="btn btn-secondary">
                        <ArrowLeft size={16} />
                        Back to documents
                    </Link>
                }
            />
            {!types.length && (
                <div className="notice notice-info">An administrator must add a real document category before registration is available.</div>
            )}
            <form onSubmit={submit} className="registration-grid">
                <div className="panel form-panel">
                    <div className="form-section">
                        <div className="section-number">01</div>
                        <div>
                            <h2>Document information</h2>
                            <p>Identify the record and how it should be handled.</p>
                        </div>
                    </div>
                    <div className="form-grid">
                        <div className="span-2">
                            <Field label="Document subject *" error={form.errors.subject}>
                                <Input
                                    required
                                    maxLength={250}
                                    value={form.data.subject}
                                    onChange={(e) => form.setData('subject', e.target.value)}
                                    placeholder="A clear, descriptive subject"
                                />
                            </Field>
                        </div>
                        <Field label="Category *" error={form.errors.document_type_id}>
                            <Select required value={form.data.document_type_id} onChange={(e) => form.setData('document_type_id', e.target.value)}>
                                <option value="">Select a category</option>
                                {types.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Direction *">
                            <Select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                                <option value="incoming">Incoming</option>
                                <option value="outgoing">Outgoing</option>
                            </Select>
                        </Field>
                        <Field label="Priority *">
                            <Select value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                            </Select>
                        </Field>
                        <Field label="Received date & time *" error={form.errors.received_at} hint="Philippine Standard Time">
                            <Input
                                required
                                type="datetime-local"
                                value={form.data.received_at}
                                onChange={(e) => form.setData('received_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Classification *" hint="Confidential records cannot enable public tracking.">
                            <Select value={form.data.confidentiality} onChange={(e) => form.setData('confidentiality', e.target.value)}>
                                <option value="internal">Internal</option>
                                <option value="confidential">Confidential</option>
                            </Select>
                        </Field>
                        <Field label="Originating reference (optional)">
                            <Input
                                maxLength={100}
                                value={form.data.originating_reference}
                                onChange={(e) => form.setData('originating_reference', e.target.value)}
                            />
                        </Field>
                        <div className="span-2">
                            <Field label="Description (optional)">
                                <Textarea
                                    maxLength={5000}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder="Relevant context for authorized staff"
                                />
                            </Field>
                        </div>
                    </div>
                    <div className="form-section">
                        <div className="section-number">02</div>
                        <div>
                            <h2>Sender & supporting file</h2>
                            <p>Collect only the information needed to process the document.</p>
                        </div>
                    </div>
                    <div className="form-grid">
                        <Field label="Sender / organization *" error={form.errors.sender_name}>
                            <Input
                                required
                                maxLength={180}
                                value={form.data.sender_name}
                                onChange={(e) => form.setData('sender_name', e.target.value)}
                            />
                        </Field>
                        <Field label="Private contact details (optional)">
                            <Input
                                maxLength={1000}
                                value={form.data.contact_private}
                                onChange={(e) => form.setData('contact_private', e.target.value)}
                            />
                        </Field>
                        <div className="span-2">
                            <Field
                                label="Attachment (optional)"
                                error={form.errors.attachment}
                                hint="PDF, JPG or PNG. Maximum 10 MB. Stored privately."
                            >
                                <Input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    onChange={(e) => form.setData('attachment', e.target.files?.[0] || null)}
                                />
                            </Field>
                        </div>
                    </div>
                    <div className="form-actions">
                        <Link className="btn btn-secondary" href="/documents">
                            Cancel
                        </Link>
                        <SaveButton processing={form.processing || !types.length} label="Register document" />
                    </div>
                </div>
            </form>
        </UmdtsLayout>
    );
}
