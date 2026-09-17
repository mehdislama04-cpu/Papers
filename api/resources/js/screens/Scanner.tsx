import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router';

import { api } from '../lib/api';
import { enqueueDocumentUpload } from '../lib/queue';
import { CornerEditor } from '../scanner/CornerEditor';
import { detect, disposeScanner, onLoadStage, warp, type LoadStage } from '../scanner/client';
import { BLUR_THRESHOLD, type PreviewMode, type Quad } from '../scanner/types';

interface ScannedPage {
    id: string;
    previewUrl: string;
    uploadBlob: Blob;
    sharpness: number;
    patches: number;
    width: number;
    height: number;
}

interface PendingShot {
    file: File;
    objectUrl: string;
    width: number;
    height: number;
    quad: Quad;
    detected: boolean;
}

/** Repli quand aucun quadrilatere n'est trouve : l'image entiere, legerement rentree. */
function fallbackQuad(width: number, height: number): Quad {
    const mx = width * 0.02;
    const my = height * 0.02;
    return [
        { x: mx, y: my },
        { x: width - mx, y: my },
        { x: width - mx, y: height - my },
        { x: mx, y: height - my },
    ];
}

export default function Scanner() {
    const navigate = useNavigate();
    const inputRef = useRef<HTMLInputElement>(null);

    const [pages, setPages] = useState<ScannedPage[]>([]);
    const [shot, setShot] = useState<PendingShot | null>(null);
    const [preview, setPreview] = useState<PreviewMode>('color');
    const [stage, setStage] = useState<LoadStage>('idle');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [progress, setProgress] = useState<number | null>(null);
    const [title, setTitle] = useState('');

    useEffect(() => onLoadStage(setStage), []);

    // Libere le worker et les 10,8 Mo d'OpenCV en quittant l'ecran.
    useEffect(
        () => () => {
            disposeScanner();
        },
        [],
    );

    // Revoque les URL objet pour ne pas fuir de blobs.
    useEffect(
        () => () => {
            pages.forEach((page) => URL.revokeObjectURL(page.previewUrl));
            if (shot) URL.revokeObjectURL(shot.objectUrl);
        },
        // Volontairement au demontage seulement.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const totalBytes = useMemo(
        () => pages.reduce((sum, page) => sum + page.uploadBlob.size, 0),
        [pages],
    );

    const onPick = useCallback(async (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) return;

        setError(null);
        setBusy('Analyse du cadrage…');

        try {
            const bitmap = await createImageBitmap(file);
            const width = bitmap.width;
            const height = bitmap.height;

            // detect() consomme le bitmap : on en recreera un pour le warp.
            const result = await detect(bitmap);

            setShot({
                file,
                objectUrl: URL.createObjectURL(file),
                width,
                height,
                quad: result.quad ?? fallbackQuad(width, height),
                detected: result.quad !== null,
            });
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : "Impossible de lire cette photo.");
        } finally {
            setBusy(null);
        }
    }, []);

    const confirmShot = useCallback(async () => {
        if (!shot) return;

        setBusy('Redressement…');
        setError(null);

        try {
            const bitmap = await createImageBitmap(shot.file);
            const result = await warp(bitmap, shot.quad, preview);

            setPages((current) => [
                ...current,
                {
                    id: crypto.randomUUID(),
                    previewUrl: URL.createObjectURL(result.preview),
                    uploadBlob: result.upload,
                    sharpness: result.sharpness,
                    patches: result.patches,
                    width: result.width,
                    height: result.height,
                },
            ]);

            URL.revokeObjectURL(shot.objectUrl);
            setShot(null);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Le redressement a echoue.');
        } finally {
            setBusy(null);
        }
    }, [shot, preview]);

    const cancelShot = useCallback(() => {
        if (shot) URL.revokeObjectURL(shot.objectUrl);
        setShot(null);
    }, [shot]);

    const removePage = useCallback((id: string) => {
        setPages((current) => {
            const page = current.find((p) => p.id === id);
            if (page) URL.revokeObjectURL(page.previewUrl);
            return current.filter((p) => p.id !== id);
        });
    }, []);

    const movePage = useCallback((index: number, delta: number) => {
        setPages((current) => {
            const target = index + delta;
            if (target < 0 || target >= current.length) return current;
            const next = [...current];
            [next[index], next[target]] = [next[target], next[index]];
            return next;
        });
    }, []);

    const submit = useCallback(async () => {
        if (pages.length === 0) return;

        setBusy('Envoi…');
        setError(null);
        setProgress(0);

        const blobs = pages.map((page) => page.uploadBlob);

        try {
            if (!navigator.onLine) {
                await enqueueDocumentUpload({
                    pages: blobs,
                    title: title.trim() || undefined,
                    source: 'scanner',
                });
                pages.forEach((page) => URL.revokeObjectURL(page.previewUrl));
                setPages([]);
                setTitle('');
                navigate('/', { replace: true });
                return;
            }

            const form = new FormData();
            blobs.forEach((blob, index) => {
                form.append('pages[]', blob, `page-${index + 1}.jpg`);
            });
            form.append('source', 'scanner');
            if (title.trim()) form.append('title', title.trim());

            const response = await api.upload<{ data: { id: string } }>('/documents', form, {
                onProgress: (sent, total) => setProgress(total > 0 ? sent / total : null),
            });

            pages.forEach((page) => URL.revokeObjectURL(page.previewUrl));
            setPages([]);
            setTitle('');
            navigate(`/documents/${response.data.id}`, { replace: true });
        } catch (cause) {
            // Hors ligne ou serveur injoignable : on met en file plutot que de
            // perdre le scan. iOS n'a pas de Background Sync, la file est
            // rejouee a la reouverture de l'app.
            try {
                await enqueueDocumentUpload({
                    pages: blobs,
                    title: title.trim() || undefined,
                    source: 'scanner',
                });
                pages.forEach((page) => URL.revokeObjectURL(page.previewUrl));
                setPages([]);
                setTitle('');
                setError("Envoi impossible pour le moment : le document est en file et partira automatiquement.");
            } catch {
                setError(cause instanceof Error ? cause.message : "L'envoi a echoue.");
            }
        } finally {
            setBusy(null);
            setProgress(null);
        }
    }, [pages, title, navigate]);

    const blurryCount = pages.filter((page) => page.sharpness < BLUR_THRESHOLD).length;

    return (
        <div className="flex flex-col gap-5 px-4 pb-8 pt-2">
            <header>
                <h1 className="text-xl font-semibold">Scanner</h1>
                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    Photographiez le document bien a plat. Le cadrage, la perspective et l’eclairage
                    sont corriges automatiquement.
                </p>
            </header>

            {error && (
                <p
                    role="alert"
                    className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300"
                >
                    {error}
                </p>
            )}

            {stage === 'loading' && (
                <p className="flex items-center gap-2 rounded-lg bg-neutral-100 px-3 py-2 text-sm text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                    <span className="size-4 animate-spin rounded-full border-2 border-neutral-400 border-t-transparent" />
                    Chargement du moteur de traitement d’image (une seule fois)…
                </p>
            )}

            {/* accept explicite : NE JAMAIS inclure image/heic, Safari 17+ renverrait
                alors du HEIC. capture=environment ouvre l’appareil photo natif, qui
                donne l’autofocus, le flash et la pleine resolution capteur. */}
            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/png"
                capture="environment"
                className="sr-only"
                onChange={onPick}
            />

            {shot ? (
                <section className="flex flex-col gap-3">
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {shot.detected
                            ? 'Document detecte. Ajustez les coins si besoin.'
                            : 'Aucun contour net detecte : placez vous-meme les quatre coins.'}
                    </p>

                    <CornerEditor
                        src={shot.objectUrl}
                        width={shot.width}
                        height={shot.height}
                        quad={shot.quad}
                        onChange={(quad) => setShot((current) => (current ? { ...current, quad } : current))}
                    />

                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={cancelShot}
                            className="flex-1 rounded-xl border border-neutral-300 px-4 py-3 text-sm font-medium dark:border-neutral-700"
                        >
                            Reprendre
                        </button>
                        <button
                            type="button"
                            onClick={confirmShot}
                            disabled={busy !== null}
                            className="flex-[2] rounded-xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {busy ?? 'Valider cette page'}
                        </button>
                    </div>
                </section>
            ) : (
                <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    disabled={busy !== null}
                    className="rounded-xl bg-sky-600 px-4 py-4 text-base font-semibold text-white disabled:opacity-50"
                >
                    {busy ?? (pages.length === 0 ? 'Photographier le document' : 'Ajouter une page')}
                </button>
            )}

            {pages.length > 0 && (
                <section className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold">
                            {pages.length} page{pages.length > 1 ? 's' : ''}
                        </h2>
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">
                            {(totalBytes / 1024 / 1024).toFixed(1)} Mo
                        </span>
                    </div>

                    {blurryCount > 0 && (
                        <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            {blurryCount === 1 ? 'Une page semble floue' : `${blurryCount} pages semblent floues`}.
                            Le texte risque d’etre mal lu : reprenez-la si possible.
                        </p>
                    )}

                    <ul className="flex flex-col gap-2">
                        {pages.map((page, index) => (
                            <li
                                key={page.id}
                                className="flex items-center gap-3 rounded-xl border border-neutral-200 p-2 dark:border-neutral-800"
                            >
                                <img
                                    src={page.previewUrl}
                                    alt={`Page ${index + 1}`}
                                    className="h-20 w-16 rounded-lg object-cover"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium">Page {index + 1}</p>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                        {page.width}×{page.height}
                                        {page.sharpness < BLUR_THRESHOLD ? ' · floue' : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    <button
                                        type="button"
                                        aria-label={`Monter la page ${index + 1}`}
                                        onClick={() => movePage(index, -1)}
                                        disabled={index === 0}
                                        className="size-9 rounded-lg border border-neutral-300 text-sm disabled:opacity-30 dark:border-neutral-700"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`Descendre la page ${index + 1}`}
                                        onClick={() => movePage(index, 1)}
                                        disabled={index === pages.length - 1}
                                        className="size-9 rounded-lg border border-neutral-300 text-sm disabled:opacity-30 dark:border-neutral-700"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`Supprimer la page ${index + 1}`}
                                        onClick={() => removePage(page.id)}
                                        className="size-9 rounded-lg border border-red-300 text-sm text-red-600 dark:border-red-900 dark:text-red-400"
                                    >
                                        ×
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">Titre (facultatif)</span>
                        <input
                            type="text"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            placeholder="Laisser vide : le titre sera deduit du contenu"
                            className="rounded-xl border border-neutral-300 px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-900"
                        />
                    </label>

                    <fieldset className="flex gap-2">
                        <legend className="sr-only">Rendu de l’apercu</legend>
                        {(
                            [
                                ['color', 'Couleur'],
                                ['gray', 'Gris'],
                                ['bw', 'Noir et blanc'],
                            ] as Array<[PreviewMode, string]>
                        ).map(([mode, label]) => (
                            <button
                                key={mode}
                                type="button"
                                onClick={() => setPreview(mode)}
                                className={`flex-1 rounded-lg border px-2 py-2 text-xs ${
                                    preview === mode
                                        ? 'border-sky-500 bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'
                                        : 'border-neutral-300 dark:border-neutral-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </fieldset>
                    <p className="-mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        L’apercu seul change. Le document envoye a l’analyse reste en couleur, pour
                        conserver tampons, surlignages et signatures.
                    </p>

                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy !== null}
                        className="rounded-xl bg-emerald-600 px-4 py-4 text-base font-semibold text-white disabled:opacity-50"
                    >
                        {progress !== null
                            ? `Envoi ${Math.round(progress * 100)} %`
                            : (busy ?? `Analyser ${pages.length} page${pages.length > 1 ? 's' : ''}`)}
                    </button>
                </section>
            )}
        </div>
    );
}
