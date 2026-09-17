/**
 * File d'envoi hors-ligne, persistee en IndexedDB.
 *
 * Pourquoi maison : iOS n'a NI Background Sync NI Periodic Background Sync,
 * et n'en aura pas. Le BackgroundSyncPlugin de Workbox degrade en « rejeu au
 * demarrage du SW », ce qui ne se declenche pas de facon fiable. La file est
 * donc rejouee depuis la PAGE, sur tous les evenements disponibles cumules :
 * `online`, `visibilitychange` (le plus fiable : l'app est gelee en
 * arriere-plan et « revient » par la), `pageshow` (bfcache), au demarrage, et
 * un intervalle de garde tant que la file n'est pas vide.
 *
 * Deux regles de stockage :
 *  - les Blob vivent dans un object store SEPARE des metadonnees, sinon
 *    chaque lecture de la liste deserialise des dizaines de Mo ;
 *  - on stocke des Blob, jamais du base64 (+33 % de taille, cout CPU majeur).
 */

import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
import { ApiError, ValidationError, uploadWithCsrfRetry } from './api';

const DB_NAME = 'papers-outbox';
const DB_VERSION = 1;
const MAX_ATTEMPTS = 8;
const GUARD_INTERVAL_MS = 30_000;

export type OutboxKind = 'document.upload';
export type OutboxStatus = 'pending' | 'inflight' | 'failed' | 'blocked';

export interface OutboxItem {
    id: string;
    kind: OutboxKind;
    status: OutboxStatus;
    attempts: number;
    createdAt: number;
    nextAttemptAt: number;
    /** Metadonnees LEGERES uniquement — jamais de Blob ici. */
    meta: {
        title?: string;
        source: 'scanner' | 'import';
        pageCount: number;
        bytes: number;
    };
    lastError?: string;
    /**
     * Progression du transfert en cours, 0..1.
     * JAMAIS persistee : une ecriture de progression qui arrive apres la
     * suppression de l'entree la ressusciterait (`put` cree la ligne si elle
     * n'existe plus), et le document serait renvoye en boucle.
     */
    progress?: number;
}

interface BlobRow {
    id: string;
    itemId: string;
    page: number;
    blob: Blob;
}

interface PapersDB extends DBSchema {
    outbox: {
        key: string;
        value: OutboxItem;
        indexes: { 'by-status': string; 'by-createdAt': number };
    };
    blobs: {
        key: string;
        value: BlobRow;
        indexes: { 'by-item': string };
    };
}

let dbPromise: Promise<IDBPDatabase<PapersDB>> | null = null;

function db(): Promise<IDBPDatabase<PapersDB>> {
    dbPromise ??= openDB<PapersDB>(DB_NAME, DB_VERSION, {
        upgrade(database) {
            if (!database.objectStoreNames.contains('outbox')) {
                const outbox = database.createObjectStore('outbox', { keyPath: 'id' });
                outbox.createIndex('by-status', 'status');
                outbox.createIndex('by-createdAt', 'createdAt');
            }
            if (!database.objectStoreNames.contains('blobs')) {
                const blobs = database.createObjectStore('blobs', { keyPath: 'id' });
                blobs.createIndex('by-item', 'itemId');
            }
        },
    });
    return dbPromise;
}

/* -------------------------------------------------------------------------- */
/* Stockage : persistance et quota                                             */
/* -------------------------------------------------------------------------- */

/**
 * Seul rempart contre l'eviction LRU / ITP. WebKit l'accorde par heuristique,
 * et « etre une web app sur l'ecran d'accueil » est explicitement l'une d'elles.
 */
export async function ensurePersistentStorage(): Promise<boolean> {
    if (!navigator.storage?.persist) return false;
    try {
        if (await navigator.storage.persisted()) return true;
        return await navigator.storage.persist();
    } catch {
        return false;
    }
}

export async function storageEstimate(): Promise<{ usage: number; quota: number }> {
    if (!navigator.storage?.estimate) return { usage: 0, quota: 0 };
    try {
        const { usage = 0, quota = 0 } = await navigator.storage.estimate();
        return { usage, quota };
    } catch {
        return { usage: 0, quota: 0 };
    }
}

/** Refuse de mettre en file au-dela de ~60 % du quota, apres purge des caches. */
export async function guardQuota(incomingBytes: number): Promise<void> {
    const { usage, quota } = await storageEstimate();
    if (!quota) return;
    if (usage + incomingBytes <= quota * 0.6) return;

    if (typeof caches !== 'undefined') {
        const keys = await caches.keys();
        await Promise.all(
            keys.filter((k) => k.startsWith('papers-images')).map((k) => caches.delete(k)),
        );
    }

    const after = await storageEstimate();
    if (after.quota && after.usage + incomingBytes > after.quota * 0.8) {
        throw new DOMException(
            'Espace de stockage insuffisant sur l’appareil.',
            'QuotaExceededError',
        );
    }
}

/* -------------------------------------------------------------------------- */
/* Abonnement (pour l'UI)                                                      */
/* -------------------------------------------------------------------------- */

type Listener = (items: OutboxItem[]) => void;
const listeners = new Set<Listener>();

/** Progression en cours, en memoire uniquement (cf. OutboxItem.progress). */
const progressById = new Map<string, number>();

async function notify(): Promise<void> {
    if (listeners.size === 0) return;
    const items = await listQueue();
    for (const listener of listeners) listener(items);
}

export function subscribeQueue(listener: Listener): () => void {
    listeners.add(listener);
    void listQueue().then((items) => listener(items));
    return () => {
        listeners.delete(listener);
    };
}

/* -------------------------------------------------------------------------- */
/* Lecture / ecriture                                                          */
/* -------------------------------------------------------------------------- */

export async function listQueue(): Promise<OutboxItem[]> {
    const database = await db();
    const items = await database.getAll('outbox');
    return items
        .map((item) => ({ ...item, progress: progressById.get(item.id) ?? 0 }))
        .sort((a, b) => a.createdAt - b.createdAt);
}

export async function pendingCount(): Promise<number> {
    return (await listQueue()).length;
}

export interface EnqueueDocumentInput {
    pages: Blob[];
    title?: string;
    source?: 'scanner' | 'import';
}

/** Met un document complet en file. Renvoie l'identifiant local de l'entree. */
export async function enqueueDocumentUpload(input: EnqueueDocumentInput): Promise<string> {
    if (input.pages.length === 0) {
        throw new Error('Aucune page a envoyer.');
    }

    const bytes = input.pages.reduce((total, page) => total + page.size, 0);
    await guardQuota(bytes);

    const id = crypto.randomUUID();
    const item: OutboxItem = {
        id,
        kind: 'document.upload',
        status: 'pending',
        attempts: 0,
        createdAt: Date.now(),
        nextAttemptAt: 0,
        meta: {
            title: input.title,
            source: input.source ?? 'scanner',
            pageCount: input.pages.length,
            bytes,
        },
    };

    const database = await db();
    const tx = database.transaction(['outbox', 'blobs'], 'readwrite');
    await tx.objectStore('outbox').put(item);
    const blobs = tx.objectStore('blobs');
    await Promise.all(
        input.pages.map((blob, index) =>
            blobs.put({ id: `${id}:${index}`, itemId: id, page: index, blob }),
        ),
    );
    await tx.done;

    await notify();
    void drain();
    return id;
}

export async function removeQueueItem(id: string): Promise<void> {
    const database = await db();
    const tx = database.transaction(['outbox', 'blobs'], 'readwrite');
    await tx.objectStore('outbox').delete(id);
    const index = tx.objectStore('blobs').index('by-item');
    for (const key of await index.getAllKeys(id)) {
        await tx.objectStore('blobs').delete(key);
    }
    await tx.done;
    progressById.delete(id);
    await notify();
}

export async function retryQueueItem(id: string): Promise<void> {
    const database = await db();
    const item = await database.get('outbox', id);
    if (!item) return;
    await database.put('outbox', { ...item, status: 'pending', nextAttemptAt: 0, attempts: 0 });
    await notify();
    void drain();
}

/** Purge complete : a appeler a la deconnexion. */
export async function clearQueue(): Promise<void> {
    const database = await db();
    const tx = database.transaction(['outbox', 'blobs'], 'readwrite');
    await tx.objectStore('outbox').clear();
    await tx.objectStore('blobs').clear();
    await tx.done;
    progressById.clear();
    await notify();
}

/* -------------------------------------------------------------------------- */
/* Envoi                                                                       */
/* -------------------------------------------------------------------------- */

/** Entree dont les images ont disparu : irrecuperable, inutile de reessayer. */
class MissingPagesError extends Error {
    constructor() {
        super('Pages introuvables dans la file.');
        this.name = 'MissingPagesError';
    }
}

async function sendItem(item: OutboxItem): Promise<void> {
    const database = await db();
    const rows = await database.getAllFromIndex('blobs', 'by-item', item.id);
    if (rows.length === 0) throw new MissingPagesError();

    rows.sort((a, b) => a.page - b.page);

    const form = new FormData();
    form.set('source', item.meta.source);
    if (item.meta.title) form.set('title', item.meta.title);
    for (const row of rows) {
        form.append('pages[]', row.blob, `page-${row.page + 1}.jpg`);
    }

    let lastNotify = 0;
    await uploadWithCsrfRetry('/documents', form, {
        onProgress: (sent, total) => {
            if (!total) return;
            progressById.set(item.id, sent / total);
            // Pas d'ecriture IndexedDB ici : uniquement de la memoire, et on
            // ne reveille l'UI que 4 fois par seconde.
            const now = Date.now();
            if (now - lastNotify < 250) return;
            lastNotify = now;
            void notify();
        },
    });
}

/**
 * Une erreur de validation ou d'authentification ne se resout pas en
 * reessayant : l'entree est marquee `blocked` et attend une action humaine.
 */
function isTerminal(error: unknown): boolean {
    if (error instanceof MissingPagesError) return true;
    if (error instanceof ValidationError) return true;
    if (error instanceof ApiError) {
        return error.status === 401 || error.status === 403 || error.status === 413;
    }
    return false;
}

let draining = false;

export async function drain(): Promise<void> {
    if (draining) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return;

    draining = true;
    try {
        const database = await db();
        const now = Date.now();
        const candidates = (await database.getAll('outbox'))
            .filter((item) => item.status !== 'blocked' && item.nextAttemptAt <= now)
            .sort((a, b) => a.createdAt - b.createdAt);

        for (const item of candidates) {
            progressById.set(item.id, 0);
            await database.put('outbox', { ...item, status: 'inflight' });
            await notify();

            try {
                await sendItem(item);
                await removeQueueItem(item.id);
            } catch (error) {
                const attempts = item.attempts + 1;
                const terminal = isTerminal(error) || attempts >= MAX_ATTEMPTS;
                // Backoff exponentiel plafonne a 5 minutes.
                const delay = Math.min(1000 * 2 ** attempts, 5 * 60_000);

                await database.put('outbox', {
                    ...item,
                    status: terminal ? 'blocked' : 'failed',
                    attempts,
                    nextAttemptAt: Date.now() + delay,
                    lastError: error instanceof Error ? error.message : String(error),
                });
                progressById.delete(item.id);
                await notify();

                // Hors ligne : inutile d'insister sur les entrees suivantes.
                if (error instanceof ApiError && error.isOffline) break;
            }
        }
    } finally {
        draining = false;
    }
}

/* -------------------------------------------------------------------------- */
/* Declencheurs                                                                */
/* -------------------------------------------------------------------------- */

let triggersInstalled = false;

export function installQueueTriggers(): () => void {
    if (triggersInstalled) return () => undefined;
    triggersInstalled = true;

    const run = () => void drain();

    const onVisibility = () => {
        // Le declencheur le plus fiable sur iOS : l'app est gelee en
        // arriere-plan, elle « revient » toujours par la.
        if (document.visibilityState === 'visible') run();
    };

    window.addEventListener('online', run);
    window.addEventListener('pageshow', run);
    document.addEventListener('visibilitychange', onVisibility);
    const guard = window.setInterval(async () => {
        if ((await pendingCount()) > 0) run();
    }, GUARD_INTERVAL_MS);

    void ensurePersistentStorage();
    run();

    return () => {
        window.removeEventListener('online', run);
        window.removeEventListener('pageshow', run);
        document.removeEventListener('visibilitychange', onVisibility);
        window.clearInterval(guard);
        triggersInstalled = false;
    };
}
