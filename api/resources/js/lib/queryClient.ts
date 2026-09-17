import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './api';

/**
 * Cache serveur.
 *
 * `refetchOnWindowFocus` est capital sur iOS : l'app est gelee en
 * arriere-plan, tout ce qui a bouge entre-temps est rattrape au retour au
 * premier plan. C'est aussi le seul filet en l'absence de WebSocket vivant.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            gcTime: 10 * 60_000,
            refetchOnWindowFocus: true,
            refetchOnReconnect: true,
            retry: (failureCount, error) => {
                if (error instanceof ApiError) {
                    // 401/403/404/422 ne se resolvent pas en reessayant.
                    if (error.status >= 400 && error.status < 500) return false;
                }
                return failureCount < 2;
            },
        },
        mutations: {
            retry: false,
        },
    },
});

export default queryClient;
