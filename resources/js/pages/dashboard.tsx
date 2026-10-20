import { DocumentTable, FilterBar, MetricCards, NewDocumentButton, PageTitle } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { SharedData } from '@/types';
import type { Doc, Filters, Metrics, Office } from '@/types/umdts';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight, Building2, Check, FileText, FolderCog, ShieldCheck, Sparkles } from 'lucide-react';

type Props = {
    metrics: Metrics;
    recent: Doc[];
    breakdown: { label: string; value: number }[];
    trend: { month: string; total: number }[];
    filters: Filters;
    departments: Office[];
    onboarding: { departments: boolean; types: boolean; office: boolean };
};
export default function Dashboard({ metrics, recent, breakdown, trend, filters, departments, onboarding }: Props) {
    const { auth } = usePage<SharedData>().props;
    const ready = onboarding.departments && onboarding.types && onboarding.office;
    return (
        <UmdtsLayout>
            <Head title="Dashboard" />
            <PageTitle
                eyebrow="YOUR MUNICIPAL WORKSPACE"
                title="Document overview"
                description="A clear view of your documents, from receipt to release."
                action={
                    onboarding.office ? (
                        <NewDocumentButton />
                    ) : (
                        <Link className="btn btn-secondary" href={auth.user.role === 'admin' ? '/departments' : '/settings/profile'}>
                            <Building2 size={16} />
                            Complete office setup
                        </Link>
                    )
                }
            />
            {!ready && (
                <div className="onboarding">
                    <div className="onboarding-intro">
                        <span className="onboarding-icon">
                            <Sparkles size={22} />
                        </span>
                        <div>
                            <h2>Welcome. Let's get your workspace ready.</h2>
                            <p>
                                {auth.user.role === 'admin'
                                    ? 'Add your real offices and categories, then assign your account to an office.'
                                    : 'Your administrator needs to finish the municipal workspace setup.'}
                            </p>
                        </div>
                    </div>
                    {auth.user.role === 'admin' && (
                        <div className="onboarding-steps">
                            {[
                                { done: onboarding.departments, title: 'Create departments', href: '/departments', icon: Building2 },
                                { done: onboarding.types, title: 'Add document categories', href: '/settings/categories', icon: FolderCog },
                                { done: onboarding.office, title: 'Assign your office', href: '/users', icon: ShieldCheck },
                            ].map((step, i) => (
                                <Link href={step.href} key={step.title} className={step.done ? 'step step-done' : 'step'}>
                                    <span>{step.done ? <Check size={15} /> : i + 1}</span>
                                    {step.title}
                                    <ArrowUpRight size={15} />
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            )}
            <div className="scope-line">
                <span className="live-dot" />
                Live records
                <span className="scope-divider" />
                {auth.user.role === 'admin' ? 'Authorized municipal records' : auth.user.department?.name}
                <span className="scope-note">All dates in Philippine Standard Time</span>
            </div>
            <div className="panel filter-panel">
                <FilterBar filters={filters} departments={departments} url="/dashboard" />
            </div>
            <MetricCards metrics={metrics} />
            <div className="dashboard-charts">
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2>Document activity</h2>
                            <p>Documents received by month</p>
                        </div>
                        <span className="subtle-tag">Latest 6 active months</span>
                    </div>
                    {trend.length ? (
                        <div className="trend-chart">
                            {trend.map((t) => (
                                <div className="trend-column" key={t.month}>
                                    <span>{t.total}</span>
                                    <div style={{ height: `${Math.max(4, (t.total / Math.max(...trend.map((x) => x.total))) * 130)}px` }} />
                                    <small>{new Date(`${t.month}-15`).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })}</small>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="chart-empty">
                            <div className="empty-bars">
                                <i />
                                <i />
                                <i />
                                <i />
                                <i />
                                <i />
                                <i />
                            </div>
                            <b>No data yet</b>
                            <p>Activity will appear as documents are registered.</p>
                        </div>
                    )}
                </section>
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2>Status at a glance</h2>
                            <p>Where your documents stand</p>
                        </div>
                        <FileText size={19} className="muted" />
                    </div>
                    {breakdown.length ? (
                        <div className="status-breakdown">
                            {breakdown.map((b) => (
                                <div key={b.label}>
                                    <span>{b.label}</span>
                                    <div>
                                        <i style={{ width: `${(b.value / metrics.total) * 100}%` }} />
                                    </div>
                                    <b>{b.value}</b>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="chart-empty">
                            <div className="empty-ring">
                                <span>0</span>
                            </div>
                            <b>No data yet</b>
                            <p>Your status breakdown will appear here.</p>
                        </div>
                    )}
                </section>
            </div>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>Recently registered</h2>
                        <p>The latest records in your authorized workspace</p>
                    </div>
                    <Link href="/documents" className="text-link">
                        View all documents
                        <ArrowRight size={16} />
                    </Link>
                </div>
                <DocumentTable documents={recent} create={ready} />
            </section>
        </UmdtsLayout>
    );
}
