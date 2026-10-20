import { Field, Input, PageTitle, Pagination, SaveButton, Select } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { User } from '@/types';
import type { Office, Paginated } from '@/types/umdts';
import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export default function Users({ users, departments }: { users: Paginated<User>; departments: Office[] }) {
    const [show, setShow] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const form = useForm({
        name: '',
        email: '',
        role: 'staff',
        department_id: '',
        is_active: true as boolean,
        password: '',
        password_confirmation: '',
    });
    function edit(user?: User) {
        setEditing(user?.id || null);
        form.clearErrors();
        form.setData({
            name: user?.name || '',
            email: user?.email || '',
            role: user?.role || 'staff',
            department_id: user?.department_id?.toString() || '',
            is_active: user?.is_active ?? true,
            password: '',
            password_confirmation: '',
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
        if (editing) form.put(`/users/${editing}`, options);
        else form.post('/users', options);
    }
    return (
        <UmdtsLayout>
            <Head title="Staff accounts" />
            <PageTitle
                eyebrow="ADMINISTRATION"
                title="Staff accounts"
                description="Give authorized personnel access to their office's records and actions."
                action={
                    <button className="btn btn-primary" onClick={() => edit()}>
                        <Plus size={17} />
                        Add staff account
                    </button>
                }
            />
            {show && (
                <section className="panel form-panel admin-editor">
                    <div className="panel-heading">
                        <h2>{editing ? 'Edit account' : 'New staff account'}</h2>
                        <button className="icon-btn" onClick={() => setShow(false)} aria-label="Close account editor">
                            <X size={18} />
                        </button>
                    </div>
                    <form onSubmit={submit} className="form-stack">
                        <div className="form-grid">
                            <Field label="Full name *" error={form.errors.name}>
                                <Input required maxLength={150} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Work email *" error={form.errors.email}>
                                <Input
                                    type="email"
                                    required
                                    maxLength={254}
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value.toLowerCase())}
                                />
                            </Field>
                            <Field label="Role *" error={form.errors.role}>
                                <Select value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                    <option value="staff">Municipal staff</option>
                                    <option value="admin">Administrator</option>
                                </Select>
                            </Field>
                            <Field
                                label="Department"
                                error={form.errors.department_id}
                                hint="Required for staff and for all document registration or routing."
                            >
                                <Select
                                    required={form.data.role === 'staff'}
                                    value={form.data.department_id}
                                    onChange={(e) => form.setData('department_id', e.target.value)}
                                >
                                    <option value="">No department assigned</option>
                                    {departments.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                label={editing ? 'New password (optional)' : 'Initial password *'}
                                error={form.errors.password}
                                hint="12+ characters, mixed case, a number and a symbol."
                            >
                                <Input
                                    type="password"
                                    autoComplete="new-password"
                                    required={!editing}
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                />
                            </Field>
                            <Field label="Confirm password">
                                <Input
                                    type="password"
                                    autoComplete="new-password"
                                    required={Boolean(form.data.password)}
                                    value={form.data.password_confirmation}
                                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                />
                            </Field>
                            <label className="check-label">
                                <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                                Account active
                            </label>
                        </div>
                        <div className="form-actions">
                            <button className="btn btn-secondary" type="button" onClick={() => setShow(false)}>
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
                        Municipal personnel<span className="count-chip">{users.total}</span>
                    </h2>
                    <span className="muted">Deactivate accounts to preserve accountability</span>
                </div>
                <div className="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Access</th>
                                <th>
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td>
                                        <b>{user.name}</b>
                                        <span className="record-reference">{user.email}</span>
                                    </td>
                                    <td>{user.role === 'admin' ? 'Administrator' : 'Municipal staff'}</td>
                                    <td>{user.department?.name || 'Not assigned'}</td>
                                    <td>
                                        <span className={`mini-state ${user.is_active ? 'enabled' : ''}`}>
                                            {user.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>
                                        <button className="btn btn-quiet" onClick={() => edit(user)}>
                                            <Pencil size={14} />
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={users} />
            </section>
        </UmdtsLayout>
    );
}
