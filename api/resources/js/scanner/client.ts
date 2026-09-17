/**
 * Pont promesse vers le worker OpenCV.
 *
 * Le worker est cree PARESSEUSEMENT, a la premiere utilisation : ouvrir l'app
 * ne doit jamais declencher le telechargement des 10,8 Mo d'OpenCV.
 */

import type {
    DetectResponse,
    PreviewMode,
    Quad,
    WarpResponse,
    WorkerRequest,
    WorkerResponse,
} from './types';

type Pending = {
    resolve: (value: never) => void;
    reject: (reason: Error) => void;
};

/**
 * Omit distributif.
 *
 * `Omit<Union, K>` s'applique a l'union PRISE GLOBALEMENT et ne conserve que
 * les proprietes communes a toutes ses branches : les champs propres a `warp`
 * (dont `quad`) disparaitraient. La forme conditionnelle repartit l'operation
 * sur chaque branche.
 */
type DistributiveOmit<T, K extends PropertyKey> = T extends unknown ? Omit<T, K> : never;

export type LoadStage = 'idle' | 'loading' | 'ready';

let worker: Worker | null = null;
let nextId = 1;
const pending = new Map<number, Pending>();

const stageListeners = new Set<(stage: LoadStage) => void>();
let stage: LoadStage = 'idle';

function setStage(next: LoadStage): void {
    stage = next;
    for (const listener of stageListeners) {
        listener(next);
    }
}

export function getLoadStage(): LoadStage {
    return stage;
}

export function onLoadStage(listener: (stage: LoadStage) => void): () => void {
    stageListeners.add(listener);
    listener(stage);
    return () => stageListeners.delete(listener);
}

function ensureWorker(): Worker {
    if (worker) {
        return worker;
    }

    worker = new Worker(new URL('../workers/opencv.worker.ts', import.meta.url), {
        type: 'module',
        name: 'papers-opencv',
    });

    worker.addEventListener('message', (event: MessageEvent<WorkerResponse>) => {
        const message = event.data;

        if (message.id === -1 && message.type === 'progress') {
            setStage(message.stage === 'ready' ? 'ready' : 'loading');
            return;
        }

        const entry = pending.get(message.id);
        if (!entry) {
            return;
        }

        pending.delete(message.id);

        if ('ok' in message && message.ok) {
            entry.resolve(message.result as never);
        } else if ('error' in message) {
            entry.reject(new Error(message.error));
        }
    });

    worker.addEventListener('error', (event) => {
        const error = new Error(event.message || 'Le module de traitement d image a echoue.');
        for (const entry of pending.values()) {
            entry.reject(error);
        }
        pending.clear();
        setStage('idle');
        worker?.terminate();
        worker = null;
    });

    return worker;
}

function send<T>(request: DistributiveOmit<WorkerRequest, 'id'>, transfer: Transferable[]): Promise<T> {
    const instance = ensureWorker();
    const id = nextId;
    nextId += 1;

    return new Promise<T>((resolve, reject) => {
        pending.set(id, { resolve: resolve as (value: never) => void, reject });
        instance.postMessage({ ...request, id } as WorkerRequest, transfer);
    });
}

/** Detecte les 4 coins du document. Le bitmap est CONSOMME par le worker. */
export function detect(bitmap: ImageBitmap): Promise<DetectResponse> {
    return send<DetectResponse>({ type: 'detect', bitmap }, [bitmap]);
}

/** Redresse et corrige. Le bitmap est CONSOMME par le worker. */
export function warp(bitmap: ImageBitmap, quad: Quad, preview: PreviewMode): Promise<WarpResponse> {
    return send<WarpResponse>({ type: 'warp', bitmap, quad, preview }, [bitmap]);
}

/** Libere le worker et les 10,8 Mo d'OpenCV quand on quitte le scanner. */
export function disposeScanner(): void {
    if (!worker) {
        return;
    }

    worker.terminate();
    worker = null;
    pending.clear();
    setStage('idle');
}
