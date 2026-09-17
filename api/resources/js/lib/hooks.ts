import { useEffect, useState, useSyncExternalStore } from 'react';
import { subscribeQueue, type OutboxItem } from './queue';

function subscribeOnline(callback: () => void): () => void {
    window.addEventListener('online', callback);
    window.addEventListener('offline', callback);
    return () => {
        window.removeEventListener('online', callback);
        window.removeEventListener('offline', callback);
    };
}

export function useOnline(): boolean {
    return useSyncExternalStore(
        subscribeOnline,
        () => navigator.onLine,
        () => true,
    );
}

/** Contenu courant de la file d'envoi hors-ligne. */
export function useOutbox(): OutboxItem[] {
    const [items, setItems] = useState<OutboxItem[]>([]);
    useEffect(() => subscribeQueue(setItems), []);
    return items;
}

/** Vrai quand l'app tourne installee sur l'ecran d'accueil. */
export function useStandalone(): boolean {
    const [standalone, setStandalone] = useState(false);

    useEffect(() => {
        const query = window.matchMedia('(display-mode: standalone)');
        const read = () =>
            setStandalone(
                query.matches ||
                    (navigator as Navigator & { standalone?: boolean }).standalone === true,
            );
        read();
        query.addEventListener('change', read);
        return () => query.removeEventListener('change', read);
    }, []);

    return standalone;
}
