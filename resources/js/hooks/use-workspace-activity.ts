import type { WorkspaceActivityCounts } from '@/types';
import { useEffect, useState } from 'react';

const emptyCounts: WorkspaceActivityCounts = { documents: 0, inbox: 0, outgoing: 0 };

export function useWorkspaceActivity(initial?: WorkspaceActivityCounts | null) {
    const [counts, setCounts] = useState(initial || emptyCounts);

    useEffect(() => {
        setCounts(initial || emptyCounts);
        const controller = new AbortController();
        let busy = false;
        async function refresh() {
            if (document.hidden || busy) return;
            busy = true;
            try {
                const response = await fetch('/workspace/activity', {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (response.ok && !response.redirected) {
                    const next: WorkspaceActivityCounts = await response.json();
                    if (!controller.signal.aborted) setCounts(next);
                }
            } catch {
                // Keep the last known counters during a network interruption.
            } finally {
                busy = false;
            }
        }
        const interval = window.setInterval(refresh, 30_000);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            controller.abort();
            window.clearInterval(interval);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [initial]);

    return counts;
}
