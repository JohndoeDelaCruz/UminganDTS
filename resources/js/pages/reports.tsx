import { DocumentTable, ExportButton, FilterBar, MetricCards, PageTitle, Pagination } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Category, Doc, Filters, Metrics, Office, Paginated, StatusOption } from '@/types/umdts';
import { Head } from '@inertiajs/react';
export default function Reports({
    documents,
    metrics,
    filters,
    departments,
    types,
    statuses,
}: {
    documents: Paginated<Doc>;
    metrics: Metrics;
    filters: Filters;
    departments: Office[];
    types: Category[];
    statuses: StatusOption[];
}) {
    return (
        <UmdtsLayout>
            <Head title="Reports" />
            <PageTitle
                eyebrow="INSIGHTS & ACCOUNTABILITY"
                title="Document reports"
                description="Review real activity and export records within your authorized scope."
                action={<ExportButton filters={filters} />}
            />
            <div className="panel filter-panel">
                <FilterBar filters={filters} departments={departments} types={types} statuses={statuses} url="/reports" />
            </div>
            <MetricCards metrics={metrics} />
            <div className="report-definition">
                <div>
                    <strong>{metrics.average_days === null ? '—' : `${metrics.average_days} days`}</strong>
                    <span>Average completion time</span>
                </div>
                <p>
                    Elapsed calendar days from receipt to recorded internal completion, for currently completed or released documents with valid
                    timestamps. Reopened records are excluded until completed again. Date filters use receipt date.
                </p>
            </div>
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        Filtered records<span className="count-chip">{documents.total}</span>
                    </h2>
                    <span className="muted">CSV includes the applied filters</span>
                </div>
                <DocumentTable documents={documents.data} />
                <Pagination page={documents} />
            </section>
        </UmdtsLayout>
    );
}
