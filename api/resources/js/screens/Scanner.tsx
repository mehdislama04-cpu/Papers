import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router';

import { api, ApiError } from '../lib/api';
import { enqueueDocumentUpload } from '../lib/queue';
import { CornerEditor } from '../scanner/CornerEditor';
import { detect, disposeScanner, onLoadStage, warp, type LoadStage } from '../scanner/client';
import { BLUR_THRESHOLD, type PreviewMode, type Quad } from '../scanner/types';

import { NavBar } from '../components/ui/NavBar';
import { Button, EmptyState, ErrorNote, Group } from '../components/ui/Layout';

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
            /*
             | Tous les echecs ne se valent pas.
             |
             | Un echec de TRANSPORT (hors ligne, serveur injoignable, 5xx,
             | delai depasse) merite la file : le scan n'est pas perdu et
             | repartira a la reouverture de l'app — iOS n'a pas de Background
             | Sync.
             |
             | Un REFUS du serveur (page trop lourde, format rejete, trop de
             | pages) n'aboutira pas davantage a la tentative suivante. Le
             | mettre en file fait boucler l'envoi indefiniment, derriere un
             | message qui promet un depart qui n'arrivera jamais. On montre
             | alors ce que le serveur reproche, et on GARDE les pages a
             | l'ecran pour que l'utilisateur puisse en retirer une.
             */
            const transient =
                !(cause instanceof ApiError) ||
                cause.isOffline ||
                cause.status >= 500 ||
                cause.status === 408 ||
                cause.status === 429;

            if (!transient) {
                setError(
                    cause.status === 413
                        ? 'Le document est trop lourd pour le serveur. Retirez une page, ou reprenez-la de moins pres.'
                        : cause.message,
                );
                return;
            }

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

    // ------------------------------------------------------------------
    // Cadrage : plein ecran noir. C'est une visee — la photo et les quatre
    // poignees doivent dominer, pas un formulaire avec des boutons empiles.
    // ------------------------------------------------------------------
    if (shot) {
        return (
            <div
                className="app-chrome fixed inset-0 z-50 flex flex-col bg-black"
                style={{
                    paddingTop: 'var(--safe-t)',
                    paddingBottom: 'calc(var(--safe-b) + 1rem)',
                }}
            >
                <div className="flex min-h-11 items-center justify-between px-4 text-white">
                    <button
                        type="button"
                        onClick={cancelShot}
                        className="pressable tap-target flex items-center"
                    >
                        Annuler
                    </button>
                    <span className="text-[0.9375rem] font-semibold text-white/70">
                        Page {pages.length + 1}
                    </span>
                    <span className="w-16" />
                </div>

                <div className="flex flex-1 items-center justify-center px-4">
                    <CornerEditor
                        src={shot.objectUrl}
                        width={shot.width}
                        height={shot.height}
                        quad={shot.quad}
                        onChange={(quad) => setShot((current) => (current ? { ...current, quad } : current))}
                    />
                </div>

                <p className="px-8 pb-4 text-center text-[0.9375rem] leading-[1.3125rem] text-white/80">
                    {shot.detected
                        ? 'Document detecte. Faites glisser les coins si le cadrage ne tombe pas juste.'
                        : 'Aucun contour net detecte : placez vous-meme les quatre coins.'}
                </p>

                {error && (
                    <p role="alert" className="mx-4 mb-3 rounded-md bg-late-fg px-3.5 py-3 text-white">
                        {error}
                    </p>
                )}

                <div className="flex gap-2.5 px-4">
                    <button
                        type="button"
                        onClick={cancelShot}
                        className="pressable flex h-13 flex-1 items-center justify-center rounded-md bg-white/15 font-semibold text-white"
                    >
                        Reprendre
                    </button>
                    <button
                        type="button"
                        onClick={confirmShot}
                        disabled={busy !== null}
                        className="pressable flex h-13 flex-2 items-center justify-center rounded-md bg-accent font-semibold text-on-accent disabled:opacity-50"
                    >
                        {busy ?? 'Valider cette page'}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="px-4 pb-8">
            <NavBar
                title="Scanner"
                subtitle="Photographiez le document bien a plat. Le cadrage, la perspective et l&rsquo;eclairage sont corriges automatiquement."
            />

            {error && <ErrorNote>{error}</ErrorNote>}

            {stage === 'loading' && (
                <p className="mb-3 flex items-center gap-2.5 rounded-md bg-surface-2 px-3.5 py-3 text-[0.9375rem] text-fg-2">
                    <span className="size-4 shrink-0 animate-spin rounded-full border-2 border-edge border-t-accent" />
                    Chargement du moteur de traitement d&rsquo;image (une seule fois)&hellip;
                </p>
            )}

            {/* accept explicite : NE JAMAIS inclure image/heic, Safari 17+ renverrait
                alors du HEIC. capture=environment ouvre l&rsquo;appareil photo natif, qui
                donne l&rsquo;autofocus, le flash et la pleine resolution capteur. */}
            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/png"
                capture="environment"
                className="sr-only"
                onChange={onPick}
            />

            <Button onClick={() => inputRef.current?.click()} disabled={busy !== null}>
                {busy ?? (pages.length === 0 ? 'Photographier le document' : 'Ajouter une page')}
            </Button>

            {pages.length === 0 && (
                <EmptyState
                    icon={
                        <svg
                            viewBox="0 0 24 24"
                            className="size-13"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={1.3}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M3 8V5.5A1.5 1.5 0 0 1 4.5 4H7" />
                            <path d="M21 8V5.5A1.5 1.5 0 0 0 19.5 4H17" />
                            <path d="M3 16v2.5A1.5 1.5 0 0 0 4.5 20H7" />
                            <path d="M21 16v2.5a1.5 1.5 0 0 1-1.5 1.5H17" />
                            <circle cx="12" cy="12" r="3.25" />
                        </svg>
                    }
                    title="Une page a la fois"
                >
                    Chaque photo devient une page. Vous pourrez les reordonner avant l&rsquo;envoi.
                </EmptyState>
            )}

            {pages.length > 0 && (
                <section className="mt-5">
                    <div className="mb-2 flex items-baseline justify-between px-0.5">
                        <h2 className="font-semibold">
                            {pages.length} page{pages.length > 1 ? 's' : ''}
                        </h2>
                        <span className="text-[0.8125rem] text-fg-3">
                            {(totalBytes / 1024 / 1024).toFixed(1)} Mo
                        </span>
                    </div>

                    {blurryCount > 0 && (
                        <p className="mb-2.5 rounded-md bg-soon-bg px-3.5 py-3 text-[0.9375rem] text-soon-fg">
                            {blurryCount === 1
                                ? 'Une page semble floue'
                                : `${blurryCount} pages semblent floues`}
                            . Le texte risque d&rsquo;etre mal lu : reprenez-la si possible.
                        </p>
                    )}

                    <Group>
                        {pages.map((page, index) => (
                            <div key={page.id} className="flex items-center gap-3.5 px-3.5 py-3">
                                <img
                                    src={page.previewUrl}
                                    alt={`Page ${index + 1}`}
                                    className="h-[3.5625rem] w-11 shrink-0 rounded-[0.4375rem] object-cover"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">Page {index + 1}</p>
                                    <p className="text-[0.8125rem] text-fg-3">
                                        {page.width}&times;{page.height}
                                        {page.sharpness < BLUR_THRESHOLD ? ' · floue' : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center">
                                    <button
                                        type="button"
                                        aria-label={`Monter la page ${index + 1}`}
                                        onClick={() => movePage(index, -1)}
                                        disabled={index === 0}
                                        className="pressable flex size-11 items-center justify-center text-fg-2 disabled:opacity-25"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            className="size-5"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M10 16V4m0 0L5 9m5-5 5 5" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`Descendre la page ${index + 1}`}
                                        onClick={() => movePage(index, 1)}
                                        disabled={index === pages.length - 1}
                                        className="pressable flex size-11 items-center justify-center text-fg-2 disabled:opacity-25"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            className="size-5"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M10 4v12m0 0 5-5m-5 5-5-5" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`Supprimer la page ${index + 1}`}
                                        onClick={() => removePage(page.id)}
                                        className="pressable flex size-11 items-center justify-center text-late-fg"
                                    >
                                        <svg
                                            viewBox="0 0 20 20"
                                            className="size-5"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                            strokeLinecap="round"
                                            aria-hidden="true"
                                        >
                                            <path d="m5 5 10 10M15 5 5 15" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </Group>

                    <label className="mt-4 flex flex-col gap-1.5">
                        <span className="px-0.5 text-[0.9375rem] font-medium">Titre (facultatif)</span>
                        <input
                            type="text"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            placeholder="Laisser vide : deduit du contenu"
                            className="h-13 rounded-md border border-edge bg-surface px-3.5"
                        />
                    </label>

                    <fieldset className="mt-4">
                        <legend className="sr-only">Rendu de l&rsquo;apercu</legend>
                        <div className="grid h-11 grid-cols-3 gap-0.5 rounded-[0.5625rem] bg-surface-2 p-0.5">
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
                                    className={`flex items-center justify-center rounded-[0.4375rem] text-sm transition-colors ${
                                        preview === mode
                                            ? 'bg-surface font-semibold text-fg shadow-[0_1px_3px_oklch(0.2_0.01_258/0.13)]'
                                            : 'font-medium text-fg-2'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <p className="mt-2 px-0.5 text-[0.8125rem] leading-[1.125rem] text-fg-3">
                            L&rsquo;apercu seul change. Le document envoye a l&rsquo;analyse reste en couleur,
                            pour conserver tampons, surlignages et signatures.
                        </p>
                    </fieldset>

                    <div className="mt-5">
                        <Button onClick={submit} disabled={busy !== null}>
                            {progress !== null
                                ? `Envoi ${Math.round(progress * 100)} %`
                                : (busy ?? `Analyser ${pages.length} page${pages.length > 1 ? 's' : ''}`)}
                        </Button>
                    </div>
                </section>
            )}
        </div>
    );
}
