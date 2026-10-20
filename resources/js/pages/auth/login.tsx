import { Errors, Field, Input } from '@/components/umdts';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Building2, Eye, EyeOff, FileClock, LockKeyhole, Search, ShieldCheck, UsersRound, Workflow } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export default function Login({ status, canResetPassword, setupPending }: { status?: string; canResetPassword: boolean; setupPending: boolean }) {
    const form = useForm({ email: '', password: '', remember: false as boolean });
    const [showPassword, setShowPassword] = useState(false);
    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }
    return (
        <div className="entry-page">
            <Head title="Welcome to Umingan DTS" />
            <div className="entry-scene" aria-hidden="true" />
            <div className="entry-shade" aria-hidden="true" />
            <main className="entry-shell">
                <section className="entry-story" aria-labelledby="entry-title">
                    <Link href="/" className="entry-brand" aria-label="Umingan Municipal Document Tracking System home">
                        <span className="entry-emblem">
                            <Building2 size={28} strokeWidth={1.5} />
                        </span>
                        <span>
                            Municipal Government of<strong>Umingan, Pangasinan</strong>
                        </span>
                    </Link>
                    <div className="entry-introduction">
                        <span className="entry-kicker">CONNECTED OFFICES. ACCOUNTABLE RECORDS.</span>
                        <h1 id="entry-title">
                            Umingan Municipal
                            <br />
                            Document Tracking System
                        </h1>
                        <p>A faster, more transparent, and efficient management of municipal documents and transactions.</p>
                        <Link href="/track" className="entry-track-link">
                            <Search size={17} />
                            Track your document
                            <ArrowRight size={17} />
                        </Link>
                    </div>
                    <div className="entry-features">
                        <div>
                            <span>
                                <FileClock size={23} strokeWidth={1.5} />
                            </span>
                            <div>
                                <h2>Track Documents</h2>
                                <p>Follow recorded status updates</p>
                            </div>
                        </div>
                        <div>
                            <span>
                                <Workflow size={23} strokeWidth={1.5} />
                            </span>
                            <div>
                                <h2>Improved Efficiency</h2>
                                <p>Better coordination between offices</p>
                            </div>
                        </div>
                        <div>
                            <span>
                                <UsersRound size={23} strokeWidth={1.5} />
                            </span>
                            <div>
                                <h2>Transparent Service</h2>
                                <p>Accessible document status</p>
                            </div>
                        </div>
                    </div>
                </section>
                <section className="entry-card" aria-labelledby="entry-signin-title">
                    <div className="entry-card-heading">
                        <h2 id="entry-signin-title">Welcome Back</h2>
                        <p>Sign in to your account</p>
                    </div>
                    {setupPending && (
                        <div className="entry-setup" role="status">
                            <ShieldCheck size={17} />
                            <p>Workspace setup is pending. Your administrator must provision the first account before employees can sign in.</p>
                        </div>
                    )}
                    {status && (
                        <div className="notice notice-success" role="status">
                            {status}
                        </div>
                    )}
                    <Errors errors={form.errors} />
                    <form onSubmit={submit} className="entry-form">
                        <Field label="Email address">
                            <Input
                                type="email"
                                autoComplete="username"
                                required
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder="Enter your work email"
                            />
                        </Field>
                        <Field label="Password">
                            <div className="entry-password">
                                <Input
                                    type={showPassword ? 'text' : 'password'}
                                    autoComplete="current-password"
                                    required
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    placeholder="Enter your password"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                                >
                                    {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                                </button>
                            </div>
                        </Field>
                        <div className="entry-options">
                            <label className="check-label">
                                <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                                Remember me
                            </label>
                            {canResetPassword && <Link href="/forgot-password">Forgot password?</Link>}
                        </div>
                        <button type="submit" className="btn btn-primary entry-submit" disabled={form.processing || setupPending}>
                            {form.processing ? 'Signing in…' : 'Sign In'}
                            <ArrowRight size={17} />
                        </button>
                    </form>
                    <div className="entry-divider">
                        <span />
                        Public access
                        <span />
                    </div>
                    <Link href="/track" className="btn btn-secondary entry-public-button">
                        <Search size={17} />
                        Track a document without signing in
                    </Link>
                    <div className="entry-account-note">
                        <LockKeyhole size={14} />
                        <p>
                            For authorized municipal personnel.
                            <br />
                            Contact your administrator to request an account.
                        </p>
                    </div>
                </section>
            </main>
            <footer className="entry-footer">
                <span>Umingan Municipal Document Tracking System</span>
                <span>Architectural illustration · Philippine Standard Time</span>
            </footer>
        </div>
    );
}
