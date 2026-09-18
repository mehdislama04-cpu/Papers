/**
 * Enregistrement du service worker et prompt de mise a jour.
 *
 * L'enregistrement est fait a la main (vite-plugin-pwa est configure avec
 * `injectRegister: false`) parce que le SW est servi depuis la RACINE
 * (`/sw.js`) alors que les assets Vite vivent sous `/build/`. Un SW enregistre
 * sous /build/ n'aurait qu'un scope /build/ et ne controlerait jamais les
 * navigations, sauf a poser un en-tete Service-Worker-Allowed que le serveur
 * statique ne pose pas.
 */

const SW_URL = '/sw.js';
const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;

export interface PwaHandle {
    /** Applique la mise a jour en attente : active le SW puis recharge. */
    applyUpdate: () => void;
    unregisterTriggers: () => void;
}

export interface RegisterOptions {
    /** Appele quand une nouvelle version est installee et attend. */
    onUpdateAvailable: () => void;
    onReady?: (registration: ServiceWorkerRegistration) => void;
}

export function registerServiceWorker(options: RegisterOptions): PwaHandle {
    const noop: PwaHandle = {
        applyUpdate: () => window.location.reload(),
        unregisterTriggers: () => undefined,
    };

    if (!('serviceWorker' in navigator)) return noop;
    // En dev, Vite sert les modules a la volee : un SW ne ferait que masquer
    // les erreurs reseau qu'on veut voir.
    if (import.meta.env.DEV) return noop;

    let waiting: ServiceWorker | null = null;
    let reloading = false;
    let interval = 0;

    const announce = (worker: ServiceWorker | null) => {
        if (!worker) return;
        waiting = worker;
        options.onUpdateAvailable();
    };

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) return;
        reloading = true;
        window.location.reload();
    });

    const onVisibility = () => {
        if (document.visibilityState !== 'visible') return;
        void navigator.serviceWorker.getRegistration(SW_URL).then((r) => r?.update());
    };

    void navigator.serviceWorker
        .register(SW_URL, { scope: '/', type: 'classic', updateViaCache: 'none' })
        .then((registration) => {
            options.onReady?.(registration);

            /*
             | Verification IMMEDIATE, en plus de l'intervalle et du retour au
             | premier plan.
             |
             | `register()` sur une inscription qui existe deja ne redemande pas
             | toujours le script : l'app peut alors rester des heures sur une
             | version perimee sans que rien ne l'annonce. C'est exactement ce
             | qui s'est produit — un backend a jour servant un front d'avant,
             | et trois pannes fantomes a la cle : photo muette, categories
             | absentes, creation sans effet. Aucune n'existait dans le code.
             */
            void registration.update().catch(() => undefined);

            if (registration.waiting && navigator.serviceWorker.controller) {
                announce(registration.waiting);
            }

            registration.addEventListener('updatefound', () => {
                const installing = registration.installing;
                if (!installing) return;
                installing.addEventListener('statechange', () => {
                    // `controller` non nul = ce n'est pas la premiere installation,
                    // donc c'est bien une mise a jour.
                    if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                        announce(registration.waiting ?? installing);
                    }
                });
            });

            interval = window.setInterval(() => {
                if (registration.installing || !navigator.onLine) return;
                void registration.update().catch(() => undefined);
            }, UPDATE_CHECK_INTERVAL_MS);

            // iOS gele l'app en arriere-plan : on verifie au retour.
            document.addEventListener('visibilitychange', onVisibility);
        })
        .catch(() => undefined);

    return {
        applyUpdate: () => {
            if (!waiting) {
                window.location.reload();
                return;
            }
            waiting.postMessage({ type: 'SKIP_WAITING' });
        },
        unregisterTriggers: () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibility);
        },
    };
}

/** Vide les caches applicatifs. A appeler a la deconnexion. */
export async function purgeAppCaches(): Promise<void> {
    if (typeof caches === 'undefined') return;
    try {
        const keys = await caches.keys();
        await Promise.all(
            keys.filter((key) => key.startsWith('papers-')).map((key) => caches.delete(key)),
        );
    } catch {
        /* rien a faire */
    }
}
