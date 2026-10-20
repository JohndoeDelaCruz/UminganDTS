import { Empty, Field, Input, PageTitle, Pagination, SaveButton } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Category, Paginated } from '@/types/umdts';
import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export default function Configuration({ kind, records }: { kind: 'departments' | 'types'; records: Paginated<Category> }) {
    const isOffice = kind === 'departments';
    const title = isOffice ? 'Departments & offices' : 'Document categories';
    const [editing, setEditing] = useState<number | null>(null);
    const [show, setShow] = useState(false);
    const form = useForm({ code: '', name: '', is_active: true as boolean, default_processing_days: '' });
    const url = isOffice ? '/departments' : '/settings/categories';
    function edit(record?: Category) {
        setEditing(record?.id || null);
        form.clearErrors();
        form.setData({
            code: record?.code || '',
            name: record?.name || '',
            is_active: record?.is_active ?? true,
            default_processing_days: record?.default_processing_days?.toString() || '',
        });
        setShow(true);
    }
    function submit(e: FormEvent) {
        e.preventDefault();
        const options = {
            onSuccess: () => {
                setShow(false);
                form.reset();
            },
        };
        if (editing) form.put(`${url}/${editing}`, options);
        else form.post(url, options);
    }
    return (
        <UmdtsLayout>
            <Head title={title} />
            <PageTitle
                eyebrow="ADMINISTRATION"
                title={title}
                description={
                    isOffice
                        ? 'Configure the real municipal offices that receive and process documents.'
                        : 'Define real document categories and approved processing targets.'
                }
                action={
                    <button className="btn btn-primary" onClick={() => edit()}>
                        <Plus size={17} />
                        Add {isOffice ? 'office' : 'category'}
                    </button>
                }
            />
            {show && (
                <section className="panel form-panel admin-editor">
                    <div className="panel-heading">
                        <h2>
                            {editing ? 'Edit' : 'New'} {isOffice ? 'office' : 'category'}
                        </h2>
                        <button className="icon-btn" aria-label="Close editor" onClick={() => setShow(false)}>
                            <X size={18} />
                        </button>
                    </div>
                    <form onSubmit={submit} className="form-stack">
                        <div className="form-grid">
                            <Field label="Code *" error={form.errors.code} hint="Unique uppercase letters, numbers, hyphens or underscores.">
                                <Input
                                    required
                                    maxLength={32}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                />
                            </Field>
                            <Field label="Name *" error={form.errors.name}>
                                <Input required maxLength={150} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            {!isOffice && (
                                <Field
                                    label="Processing target (calendar days)"
                                    error={form.errors.default_processing_days}
                                    hint="Leave blank until an approved target is available. Applies to new documents."
                                >
                                    <Input
                                        type="number"
                                        min={1}
                                        max={3650}
                                        value={form.data.default_processing_days}
                                        onChange={(e) => form.setData('default_processing_days', e.target.value)}
                                    />
                                </Field>
                            )}
                            <label className="check-label">
                                <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                                Active {isOffice ? 'office' : 'category'}
                            </label>
                        </div>
                        <div className="form-actions">
                            <button type="button" className="btn btn-secondary" onClick={() => setShow(false)}>
                                Cancel
                            </button>
                            <SaveButton processing={form.processing} />
                        </div>
                    </form>
                </section>
            )}
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        All {title.toLowerCase()}
                        <span className="count-chip">{records.total}</span>
                    </h2>
                    <span className="muted">Archived records retain their history</span>
                </div>
                {records.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    {!isOffice && <th>Processing target</th>}
                                    <th>Availability</th>
                                    <th>
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {records.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="mono">{r.code}</td>
                                        <td>
                                            <b>{r.name}</b>
                                        </td>
                                        {!isOffice && (
                                            <td>{r.default_processing_days ? `${r.default_processing_days} calendar days` : 'Not configured'}</td>
                                        )}
                                        <td>
                                            <span className={`mini-state ${r.is_active ? 'enabled' : ''}`}>
                                                {r.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td>
                                            <button className="btn btn-quiet" onClick={() => edit(r)}>
                                                <Pencil size={14} />
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty
                        title={`No ${title.toLowerCase()} yet`}
                        description={`Add the actual ${isOffice ? 'municipal offices' : 'document categories'} used by your team to begin onboarding.`}
                        action={
                            <button className="btn btn-primary" onClick={() => edit()}>
                                <Plus size={16} />
                                Add first {isOffice ? 'office' : 'category'}
                            </button>
                        }
                    />
                )}
                <Pagination page={records} />
            </section>
        </UmdtsLayout>
    );
}
