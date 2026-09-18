/// <reference lib="webworker" />

/**
 * Service worker Papers (mode injectManifest).
 *
 * En injectManifest, l'option `workbox.runtimeCaching` du plugin est IGNOREE :
 * tout le runtime caching est ecrit ici, a la main.
 *
 * Regle d'or heritee de l'auth par cookie Sanctum (ARCHITECTURE.md §2) :
 * /api/*, /login, /logout et /sanctum/* ne passent JAMAIS par un cache.
 * Mettre en cache une reponse portant une session, ou servir un jeton CSRF
 * perime, casse l'authentification de facon silencieuse et durable.
 */

import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst, NetworkOnly } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { clientsClaim } from 'workbox-core';

declare const self: ServiceWorkerGlobalScope;

const SHELL_CACHE = 'papers-shell-v1';
const OPENCV_CACHE = 'papers-opencv-v1';
const STATIC_CACHE = 'papers-static-v1';

/** Chemins qui ne doivent jamais etre mis en cache, ni servis depuis un cache. */
const NETWORK_ONLY: RegExp[] = [
    /^\/api\//,
    /^\/sanctum\//,
    /^\/login\/?$/,
    /^\/logout\/?$/,
    /^\/register\/?$/,
    /^\/broadcasting\//,
    /^\/livewire\//,
];

const isNetworkOnly = (pathname: string): boolean =>
    NETWORK_ONLY.some((pattern) => pattern.test(pathname));

/* -------------------------------------------------------------------------- */
/* Precache de l'app shell                                                     */
/* -------------------------------------------------------------------------- */

// Assets hashes produits par Vite dans public/build/assets.
// Le WASM OpenCV (~8 Mo) en est exclu par globIgnores : il a son propre cache.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();
clientsClaim();

/* -------------------------------------------------------------------------- */
/* 1. Reseau seul — avant tout le reste, l'ordre des routes fait foi           */
/* -------------------------------------------------------------------------- */

/**
 * GET UNIQUEMENT. Ne jamais reenregistrer les mutations ici.
 *
 * Une strategie NetworkOnly sur un POST ne change rien au resultat — c'est
 * exactement ce que ferait le navigateur sans service worker — mais elle force
 * la requete a traverser le worker, qui la ree-emet via `fetch(event.request)`.
 * Sur WebKit, ree-emettre ainsi une requete porteuse d'un corps MULTIPART perd
 * ce corps : le flux n'est pas rejouable, et Safari part avec un corps vide.
 *
 * Symptome vecu : l'envoi d'une photo de personne depuis l'iPhone arrivait
 * authentifie, CSRF valide, et completement vide — « le champ personne est
 * obligatoire » sur les trois champs a la fois. Le renommage, lui, passait :
 * il envoie du JSON. Le meme piege menace l'upload de documents.
 *
 * Workbox n'ecoute que GET par defaut, precisement pour cette raison.
 */
const networkOnly = new NetworkOnly();

registerRoute(({ url, sameOrigin }) => sameOrigin && isNetworkOnly(url.pathname), networkOnly);

/* -------------------------------------------------------------------------- */
/* 2. Navigations — coquille de l'app                                          */
/* -------------------------------------------------------------------------- */

/**
 * Laravel rend la coquille HTML (pas d'index.html statique a precacher) :
 * NetworkFirst avec repli sur la derniere coquille connue. Le HTML mis en
 * cache ne porte aucune session — l'etat d'authentification vient du cookie
 * et de GET /api/me, jamais du document.
 */
const shellHandler = new NetworkFirst({
    cacheName: SHELL_CACHE,
    networkTimeoutSeconds: 5,
    plugins: [
        new CacheableResponsePlugin({ statuses: [200] }),
        new ExpirationPlugin({ maxEntries: 12, maxAgeSeconds: 7 * 24 * 3600 }),
    ],
});

registerRoute(
    new NavigationRoute(shellHandler, {
        denylist: [
            ...NETWORK_ONLY,
            /^\/build\//,
            /^\/storage\//,
            /^\/ingest\//,
            /\.[a-z0-9]{2,5}$/i,
        ],
    }),
);

/* -------------------------------------------------------------------------- */
/* 3. WASM OpenCV — cache dedie, immuable, jamais precache                     */
/* -------------------------------------------------------------------------- */

registerRoute(
    ({ url }) => /opencv.*\.(js|wasm|data)$/i.test(url.pathname),
    new CacheFirst({
        cacheName: OPENCV_CACHE,
        plugins: [
            new CacheableResponsePlugin({ statuses: [0, 200] }),
            new ExpirationPlugin({ maxEntries: 6, maxAgeSeconds: 365 * 24 * 3600 }),
        ],
    }),
);

/* -------------------------------------------------------------------------- */
/* 4. Icones, pictogrammes, manifeste et autres statiques de la racine         */
/* -------------------------------------------------------------------------- */

/**
 * `/pictograms/` compte autant que `/icons/` : ce sont les vignettes des
 * categories. Sans cette regle elles repartiraient sur le reseau a chaque
 * affichage, et la grille d'une personne serait pleine de trous hors ligne —
 * exactement la ou l'app doit continuer a fonctionner.
 */
registerRoute(
    ({ url, sameOrigin }) =>
        sameOrigin &&
        !isNetworkOnly(url.pathname) &&
        (/^\/icons\//.test(url.pathname) ||
            /^\/pictograms\//.test(url.pathname) ||
            /^\/(manifest\.webmanifest|favicon\.(ico|svg)|apple-touch-icon.*\.png)$/.test(
                url.pathname,
            )),
    new CacheFirst({
        cacheName: STATIC_CACHE,
        plugins: [
            new CacheableResponsePlugin({ statuses: [0, 200] }),
            new ExpirationPlugin({ maxEntries: 60, maxAgeSeconds: 30 * 24 * 3600 }),
        ],
    }),
);

/* AUCUNE route pour les POST/PATCH/DELETE, nulle part : registerRoute n'ecoute
 * que GET par defaut, et une mutation qui traverse le worker y perd son corps
 * multipart sur WebKit (cf. le bloc « reseau seul » plus haut). Les envois
 * differes passent par la file IndexedDB de lib/queue.ts, jamais par ici. */

/* -------------------------------------------------------------------------- */
/* 5. Cycle de vie                                                             */
/* -------------------------------------------------------------------------- */

self.addEventListener('message', (event: ExtendableMessageEvent) => {
    const data = event.data as { type?: string } | undefined;
    if (data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});

/* -------------------------------------------------------------------------- */
/* 6. Web Push (repli non declaratif)                                          */
/* -------------------------------------------------------------------------- */

self.addEventListener('push', (event: PushEvent) => {
    let payload: { title?: string; body?: string; url?: string; tag?: string } = {};
    try {
        payload = (event.data?.json() as typeof payload) ?? {};
    } catch {
        payload = { body: event.data?.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'Papers', {
            body: payload.body ?? 'Un document a ete analyse.',
            icon: '/icons/pwa-192x192.png',
            badge: '/icons/pwa-64x64.png',
            tag: payload.tag,
            data: { url: payload.url ?? '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event: NotificationEvent) => {
    event.notification.close();
    const target = (event.notification.data as { url?: string } | undefined)?.url ?? '/';

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const existing = clients.find((client) => client.url.startsWith(self.registration.scope));
            if (existing) {
                await existing.focus();
                existing.postMessage({ type: 'NAVIGATE', url: target });
                return;
            }
            await self.clients.openWindow(target);
        })(),
    );
});
