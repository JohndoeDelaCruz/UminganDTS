import { Brand } from '@/components/umdts';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
export default function AuthLayout({ children, title, description }: PropsWithChildren<{ title?: string; description?: string }>) {
    return (
        <main className="auth-simple">
            <Brand />
            <div className="auth-simple-card">
                <h1>{title}</h1>
                <p>{description}</p>
                {children}
            </div>
            <Link href="/login" className="text-link">
                Return to employee sign in
            </Link>
        </main>
    );
}
