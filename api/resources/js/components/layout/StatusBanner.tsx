import { useOnline, useOutbox } from '../../lib/hooks';

/**
 * Bandeau d'etat : hors-ligne et/ou envois en attente.
 *
 * iOS n'a pas de Background Sync : rien ne part tant que l'app n'est pas au
 * premier plan. L'utilisateur doit donc voir explicitement ce qui reste a
 * envoyer, sinon il croit son document perdu.
 */
export function StatusBanner() {
    const online = useOnline();
    const outbox = useOutbox();

    const blocked = outbox.filter((item) => item.status === 'blocked');
    const waiting = outbox.length - blocked.length;

    if (online && outbox.length === 0) return null;

    return (
        <div className="app-chrome shrink-0 px-4 pt-1" role="status" aria-live="polite">
            {!online && (
                <p className="rounded-sm bg-soon-bg px-3 py-1.5 text-center text-xs font-medium text-soon-fg">
                    Hors ligne — vos documents partiront au retour du reseau.
                </p>
            )}
            {online && waiting > 0 && (
                <p className="rounded-sm bg-accent-bg px-3 py-1.5 text-center text-xs font-medium text-accent">
                    {waiting === 1 ? 'Envoi en cours…' : `${waiting} documents en cours d’envoi…`}
                </p>
            )}
            {blocked.length > 0 && (
                <p className="mt-1 rounded-sm bg-late-bg px-3 py-1.5 text-center text-xs font-medium text-late-fg">
                    {blocked.length === 1
                        ? '1 envoi a echoue.'
                        : `${blocked.length} envois ont echoue.`}{' '}
                    Voir Reglages.
                </p>
            )}
        </div>
    );
}

export default StatusBanner;
