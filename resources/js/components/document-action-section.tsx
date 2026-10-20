import { ChevronDown, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export const DOCUMENT_ACTION_GROUP = 'document-action-sections';

export default function DocumentActionSection({
    title,
    icon: Icon,
    badge,
    children,
}: {
    title: string;
    icon: LucideIcon;
    badge?: ReactNode;
    children: ReactNode;
}) {
    return (
        <details name={DOCUMENT_ACTION_GROUP} className="panel document-action-section">
            <summary className="panel-heading">
                <h2>
                    <Icon size={17} />
                    {title}
                </h2>
                {badge}
                <ChevronDown size={15} className="action-section-chevron" />
            </summary>
            {children}
        </details>
    );
}
