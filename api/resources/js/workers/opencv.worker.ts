/// <reference lib="webworker" />

/**
 * Worker de traitement d'image.
 *
 * Tout OpenCV vit ici : le module pese plus de 10 Mo et son execution est
 * bloquante. Le charger sur le thread principal gelerait l'interface pendant
 * plusieurs secondes sur iPhone, et chaque warp figerait le defilement.
 *
 * Le chargement est PARESSEUX : il ne demarre qu'a la premiere requete, donc a
 * la premiere ouverture du scanner, jamais au demarrage de l'app.
 */

import { detectQuad, warpDocument, fullFrameQuad, patchCount, Scope, type Cv } from '../scanner/pipeline';
import type { WorkerRequest, WorkerResponse, PreviewMode } from '../scanner/types';

const ctx = self as unknown as DedicatedWorkerGlobalScope;

let cvPromise: Promise<Cv> | null = null;

function loadCv(): Promise<Cv> {
    if (!cvPromise) {
        ctx.postMessage({ id: -1, type: 'progress', stage: 'loading' } satisfies WorkerResponse);

        // @techstark/opencv-js exporte une Promise (verifie a l'execution), et
        // non un objet pret ni une factory : il faut l'attendre.
        cvPromise = import('@techstark/opencv-js')
            .then((mod) => mod.default as unknown as Promise<Cv>)
            .then((cv) => {
                ctx.postMessage({ id: -1, type: 'progress', stage: 'ready' } satisfies WorkerResponse);
                return cv;
            });
    }

    return cvPromise;
}

/**
 * Convertit un Mat en Blob JPEG.
 *
 * Passe par un OffscreenCanvas : c'est la seule voie disponible dans un worker.
 * Le Mat est d'abord ramene en RGBA, seule disposition acceptee par ImageData.
 */
async function matToBlob(cv: Cv, mat: unknown, quality: number): Promise<Blob> {
    const scope = new Scope();

    try {
        const rgba = scope.keep(new cv.Mat());
        const source = mat as { channels(): number };

        if (source.channels() === 1) {
            cv.cvtColor(mat, rgba, cv.COLOR_GRAY2RGBA);
        } else if (source.channels() === 3) {
            cv.cvtColor(mat, rgba, cv.COLOR_RGB2RGBA);
        } else {
            (mat as { copyTo(dst: unknown): void }).copyTo(rgba);
        }

        const width = rgba.cols as number;
        const height = rgba.rows as number;
        const canvas = new OffscreenCanvas(width, height);
        const context = canvas.getContext('2d');

        if (!context) {
            throw new Error("Contexte 2D indisponible dans le worker.");
        }

        context.putImageData(new ImageData(new Uint8ClampedArray(rgba.data), width, height), 0, 0);

        // JPEG et pas WebP : dans Safari, toBlob('image/webp') retombe
        // SILENCIEUSEMENT sur PNG, sans erreur et en ignorant la qualite.
        const blob = await canvas.convertToBlob({ type: 'image/jpeg', quality });

        // Libere le canvas : la limite memoire canvas d'iOS (~384 Mo) est
        // distincte de la limite d'aire et reste active.
        canvas.width = 0;
        canvas.height = 0;

        if (blob.type !== 'image/jpeg') {
            throw new Error(`Encodage JPEG refuse par le navigateur (recu ${blob.type}).`);
        }

        return blob;
    } finally {
        scope.release();
    }
}

async function bitmapToMat(cv: Cv, bitmap: ImageBitmap): Promise<unknown> {
    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
    const context = canvas.getContext('2d');

    if (!context) {
        throw new Error("Contexte 2D indisponible dans le worker.");
    }

    context.drawImage(bitmap, 0, 0);
    const imageData = context.getImageData(0, 0, bitmap.width, bitmap.height);

    canvas.width = 0;
    canvas.height = 0;
    bitmap.close();

    return cv.matFromImageData(imageData);
}

function previewMat(cv: Cv, mode: PreviewMode, color: unknown, gray: unknown): { mat: unknown; owned: boolean } {
    if (mode === 'color') {
        return { mat: color, owned: false };
    }

    if (mode === 'gray') {
        return { mat: gray, owned: false };
    }

    // Noir et blanc : apercu ECRAN uniquement, jamais ce qui part au serveur.
    const bw = new cv.Mat();
    cv.adaptiveThreshold(gray, bw, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C, cv.THRESH_BINARY, 31, 10);
    return { mat: bw, owned: true };
}

ctx.addEventListener('message', (event: MessageEvent<WorkerRequest>) => {
    const request = event.data;

    void (async () => {
        try {
            const cv = await loadCv();

            if (request.type === 'detect') {
                const src = await bitmapToMat(cv, request.bitmap);

                try {
                    const quad = detectQuad(cv, src);
                    ctx.postMessage({
                        id: request.id,
                        type: 'detect',
                        ok: true,
                        result: {
                            quad,
                            width: (src as { cols: number }).cols,
                            height: (src as { rows: number }).rows,
                        },
                    } satisfies WorkerResponse);
                } finally {
                    (src as { delete(): void }).delete();
                }

                return;
            }

            const src = await bitmapToMat(cv, request.bitmap);
            const srcSized = src as { cols: number; rows: number; delete(): void };

            try {
                const quad = request.quad ?? fullFrameQuad(srcSized.cols, srcSized.rows);
                const out = warpDocument(cv, src, quad);

                try {
                    // Qualite 0.92 : au-dela le gain visuel est nul pour l'OCR
                    // et le poids double sur une liaison mobile.
                    const upload = await matToBlob(cv, out.color, 0.92);
                    const preview = previewMat(cv, request.preview, out.color, out.gray);

                    try {
                        const previewBlob = await matToBlob(cv, preview.mat, 0.8);

                        ctx.postMessage({
                            id: request.id,
                            type: 'warp',
                            ok: true,
                            result: {
                                upload,
                                preview: previewBlob,
                                width: out.width,
                                height: out.height,
                                sharpness: out.sharpness,
                                patches: patchCount(out.width, out.height),
                            },
                        } satisfies WorkerResponse);
                    } finally {
                        if (preview.owned) {
                            (preview.mat as { delete(): void }).delete();
                        }
                    }
                } finally {
                    (out.color as { delete(): void }).delete();
                    (out.gray as { delete(): void }).delete();
                }
            } finally {
                srcSized.delete();
            }
        } catch (error) {
            ctx.postMessage({
                id: request.id,
                type: request.type,
                ok: false,
                error: error instanceof Error ? error.message : String(error),
            } satisfies WorkerResponse);
        }
    })();
});
