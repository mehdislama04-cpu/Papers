import { Navigate, Outlet, useLocation } from 'react-router';
import { useAuth } from '../../lib/auth';

function BootSplash() {
    return (
        <div className="app-shell items-center justify-center" aria-busy="true">
            <span className="sr-only">Chargement de Papers</span>
            <span className="size-7 animate-spin rounded-full border-2 border-edge border-t-accent" />
        </div>
    );
}

/**
 * Garde d'authentification.
 *
 * L'etat vient de GET /api/me (cookie de session Sanctum), jamais d'un jeton
 * stocke cote client : ITP purgerait ce stockage apres 7 jours sans ouverture.
 * On redirige vers /login en gardant la destination pour y revenir apres.
 */
export function RequireAuth() {
    const { isAuthenticated, isLoading } = useAuth();
    const location = useLocation();

    if (isLoading) return <BootSplash />;

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
    }

    return <Outlet />;
}

export function RequireGuest() {
    const { isAuthenticated, isLoading } = useAuth();

    if (isLoading) return <BootSplash />;
    if (isAuthenticated) return <Navigate to="/" replace />;

    return <Outlet />;
}

export default RequireAuth;
