import { Brand, date, Errors, Field, Input } from '@/components/umdts';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Clock3, FileSearch, LockKeyhole, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
type Result = { reference: string; category: string; status: string; updated_at: string; timeline: { title: string; status: string; at: string }[] };
const UPDATES_PER_PAGE = 4;

export default function Track({ result, searched }: { result: Result | null; searched: boolean }) {
    const form = useForm({ reference: '', secret: '' });
    const [reveal, setReveal] = useState(false);
    const [earlierPage, setEarlierPage] = useState(0);
    const total = result?.timeline.length || 0;
    const end = Math.max(0, total - earlierPage * UPDATES_PER_PAGE);
    const start = Math.max(0, end - UPDATES_PER_PAGE);
    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/track', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('secret');
                setEarlierPage(0);
            },
        });
    }
    return (
        <div className="public-page">
            <Head title="Track your document" />
            <header className="public-header">
                <Brand />
                <Link href="/login" className="btn btn-secondary">
                    Employee sign in
                    <ArrowRight size={16} />
                </Link>
            </header>
            <main className="public-main">
                <section className="public-intro">
                    <div className="public-eyebrow">
                        <span />
                        UMINGAN, PANGASINAN
                    </div>
                    <h1>
                        Your document. <em>One clear update.</em>
                    </h1>
                    <p>Use the tracking reference and access code on your receipt to see your document's progress.</p>
                </section>
                <section className="tracking-card">
                    <div className="tracking-lookup">
                        <div className="tracking-title">
                            <span>
                                <FileSearch size={25} />
                            </span>
                            <div>
                                <h2>Track a document</h2>
                                <p>Enter the details from your receipt.</p>
                            </div>
                        </div>
                        <Errors errors={form.errors} />
                        <form onSubmit={submit} className="form-stack" autoComplete="off">
                            <Field label="Tracking reference">
                                <Input
                                    id="tracking-reference"
                                    name="tracking_reference"
                                    required
                                    maxLength={40}
                                    autoComplete="off"
                                    placeholder="Enter your tracking reference"
                                    value={form.data.reference}
                                    onChange={(e) => form.setData('reference', e.target.value.toUpperCase())}
                                />
                            </Field>
                            <Field label="Access code" hint="Use the code on your receipt. The dash is optional.">
                                <div className="password-wrap">
                                    <Input
                                        id="tracking-access-code"
                                        name="tracking_access_code"
                                        required
                                        type={reveal ? 'text' : 'password'}
                                        maxLength={200}
                                        autoComplete="one-time-code"
                                        autoCapitalize="characters"
                                        autoCorrect="off"
                                        spellCheck={false}
                                        placeholder="e.g. 7K3P-9W2R"
                                        value={form.data.secret}
                                        onChange={(e) => form.setData('secret', e.target.value.toUpperCase())}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setReveal(!reveal)}
                                        aria-label={reveal ? 'Hide access code' : 'Show access code'}
                                    >
                                        {reveal ? 'Hide' : 'Show'}
                                    </button>
                                </div>
                            </Field>
                            <button type="submit" disabled={form.processing} className="btn btn-primary">
                                <Search size={17} />
                                {form.processing ? 'Checking…' : 'Check document status'}
                            </button>
                        </form>
                        <div className="tracking-privacy">
                            <LockKeyhole size={14} />
                            <span>Secure lookup. Personal details and attachments stay private.</span>
                        </div>
                        <p className="public-support">Lost your access code? Contact the municipal office handling your document.</p>
                    </div>
                    <section className="tracking-outcome" aria-label="Document tracking result">
                        {!searched && !result && (
                            <div className="tracking-placeholder">
                                <FileSearch size={32} />
                                <h3>Your document's progress will appear here</h3>
                                <p>Enter your receipt details and select Check document status. No account needed.</p>
                            </div>
                        )}
                        {searched && !result && (
                            <div className="tracking-no-match" role="status">
                                <FileSearch size={25} />
                                <h3>No matching document found</h3>
                                <p>Check both details from your receipt. If you still need help, contact the office handling your document.</p>
                            </div>
                        )}
                        {result && (
                            <div className="tracking-result" role="status">
                                <div className="result-heading">
                                    <span className="result-check">
                                        <Check size={20} />
                                    </span>
                                    <div>
                                        <small>{result.reference}</small>
                                        <h3>{result.status}</h3>
                                    </div>
                                </div>
                                <dl>
                                    <div>
                                        <dt>Category</dt>
                                        <dd>{result.category}</dd>
                                    </div>
                                    <div>
                                        <dt>Last updated</dt>
                                        <dd>{date(result.updated_at, true)}</dd>
                                    </div>
                                </dl>
                                <section className="public-progress" aria-labelledby="public-progress-title">
                                    <div className="public-progress-heading">
                                        <h4 id="public-progress-title">
                                            <Clock3 size={17} />
                                            Document progress
                                        </h4>
                                        <span className="subtle-tag">
                                            {result.timeline.length} {result.timeline.length === 1 ? 'update' : 'updates'}
                                        </span>
                                    </div>
                                    <p className="public-progress-timezone">All dates in Philippine Standard Time</p>
                                    <ol className="event-timeline public-timeline">
                                        {result.timeline.slice(start, end).map((event, i) => (
                                            <li key={`${start + i}-${event.at}`}>
                                                <span className="event-dot" aria-hidden="true" />
                                                <div className="event-top">
                                                    <b>{event.title}</b>
                                                    <time dateTime={event.at}>{date(event.at, true)}</time>
                                                </div>
                                                <span className="public-progress-status">{event.status}</span>
                                            </li>
                                        ))}
                                    </ol>
                                    {total > UPDATES_PER_PAGE && (
                                        <nav className="public-progress-pagination" aria-label="Document progress pages">
                                            <button
                                                type="button"
                                                className="btn btn-secondary"
                                                disabled={start === 0}
                                                onClick={() => setEarlierPage((page) => page + 1)}
                                            >
                                                <ArrowLeft size={14} />
                                                Earlier updates
                                            </button>
                                            <span>
                                                {start + 1}–{end} of {total}
                                            </span>
                                            <button
                                                type="button"
                                                className="btn btn-secondary"
                                                disabled={earlierPage === 0}
                                                onClick={() => setEarlierPage((page) => page - 1)}
                                            >
                                                Later updates
                                                <ArrowRight size={14} />
                                            </button>
                                        </nav>
                                    )}
                                </section>
                            </div>
                        )}
                    </section>
                </section>
            </main>
            <footer className="public-footer">
                <span>Umingan Municipal Document Tracking System</span>
                <span>Accountable records. Better public service.</span>
            </footer>
        </div>
    );
}
