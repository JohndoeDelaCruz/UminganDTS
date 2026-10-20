import { date, Empty, PageTitle, Pagination } from '@/components/umdts';
import UmdtsLayout from '@/layouts/umdts-layout';
import type { Paginated } from '@/types/umdts';
import { Head } from '@inertiajs/react';
type Entry = { id: number; actor_name: string | null; action: string; resource_type: string; resource_id: string; created_at: string };
export default function Audit({ entries }: { entries: Paginated<Entry> }) {
    return (
        <UmdtsLayout>
            <Head title="Audit log" />
            <PageTitle
                eyebrow="ADMINISTRATION"
                title="Audit log"
                description="A permanent record of privileged actions, record changes, exports and file access."
            />
            <section className="panel">
                <div className="panel-heading">
                    <h2>
                        Accountability history<span className="count-chip">{entries.total}</span>
                    </h2>
                    <span className="subtle-tag">Append-only</span>
                </div>
                {entries.data.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Actor</th>
                                    <th>Resource</th>
                                    <th>Recorded at</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((e) => (
                                    <tr key={e.id}>
                                        <td>
                                            <b>{e.action.replaceAll('.', ' · ').replaceAll('_', ' ')}</b>
                                        </td>
                                        <td>{e.actor_name || 'System operator'}</td>
                                        <td>
                                            <span>{e.resource_type}</span>
                                            <small className="record-reference mono">{e.resource_id}</small>
                                        </td>
                                        <td className="nowrap">{date(e.created_at, true)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <Empty title="No audit events yet" description="Accountable actions will appear here as your workspace is used." />
                )}
                <Pagination page={entries} />
            </section>
        </UmdtsLayout>
    );
}
