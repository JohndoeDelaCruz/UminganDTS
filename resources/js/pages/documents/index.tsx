import { DocumentTable, FilterBar, NewDocumentButton, PageTitle, Pagination } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Category, Doc, Filters, Office, Paginated, StatusOption } from '@/types/umdts';
import { Head } from '@inertiajs/react';
export default function Documents({
    documents,
    filters,
    mode,
    departments,
    types,
    statuses,
    canCreate,
}: {
    documents: Paginated<Doc>;
    filters: Filters;
    mode: string;
    departments: Office[];
    types: Category[];
    statuses: StatusOption[];
    canCreate: boolean;
}) {
    const title = mode === 'inbox' ? 'Incoming documents' : mode === 'outgoing' ? 'Outgoing documents' : 'Document register';
    const description =
        mode === 'inbox'
            ? 'Handoffs addressed to your office, ready for acknowledgment.'
            : mode === 'outgoing'
              ? 'Documents your office has forwarded within your current access scope.'
              : 'Register, find and follow the records in your authorized workspace.';
    const url = mode === 'inbox' ? '/inbox' : mode === 'outgoing' ? '/outgoing' : '/documents';
    return (
        <UmdtsLayout>
            <Head title={title} />
            <PageTitle eyebrow="DOCUMENT WORKSPACE" title={title} description={description} action={canCreate ? <NewDocumentButton /> : undefined} />
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        {mode === 'inbox' ? 'Awaiting your acknowledgment' : 'All records'}
                        <span className="count-chip">{documents.total}</span>
                    </h2>
                </div>
                <FilterBar filters={filters} departments={departments} types={types} statuses={statuses} url={url} />
                <DocumentTable documents={documents.data} create={canCreate && mode !== 'inbox'} />
                <Pagination page={documents} />
            </section>
        </UmdtsLayout>
    );
}
