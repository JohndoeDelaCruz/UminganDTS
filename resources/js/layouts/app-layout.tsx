import AppLayoutTemplate from '@/layouts/umdts-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default ({ children }: AppLayoutProps) => <AppLayoutTemplate>{children}</AppLayoutTemplate>;
