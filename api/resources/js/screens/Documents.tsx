import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope, type Envelope } from '../lib/api';
import { useOutbox } from '../lib/hooks';
import type { Category, Document, DocumentStatus } from '../lib/types';

const STATUS_FILTERS: Array<{ value: DocumentStatus | ''; label: string }> = [
    { value: '', label: 'Tous' },
    { value: 'processing', label: 'En analyse' },
    { value: 'analyzed', label: 'Analyses' },
    { value: 'failed', label: 'En echec' },
];

function StatusBadge({ document }: { document: Document }) {
    const tone =
        document.status === 'analyzed'
            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
            : document.status === 'failed'
              ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300'
              : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300';

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${tone}`}>
            {!document.is_terminal && (
                <span className="size-1.5 animate-pulse rounded-full bg-current" aria-hidden="true" />
            )}
            {document.status_label}
        </span>
    );
}

export default function Documents() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<DocumentStatus | ''>('');
    const [category, setCategory] = useState('');
    const [semantic, setSemantic] = useState(false);

    const outbox = useOutbox();

    const categories = useQuery({
        queryKey: ['categories'],
        queryFn: () => api.get<Envelope<Category[]>>('/categories'),
        staleTime: 5 * 60 * 1000,
    });

    const query = useMemo(() => {
        const params = new URLSearchParams();
        if (search.trim()) params.set('search', search.trim());
        if (status) params.set('status', status);
        if (category) params.set('category', category);
        if (semantic && search.trim()) params.set('semantic', '1');
        return params.toString();
    }, [search, status, category, semantic]);

    const documents = useQuery({
        queryKey: ['documents', query],
        queryFn: () => api.get<PaginatedEnvelope<Document>>(`/documents${query ? `?${query}` : ''}`),
        placeholderData: keepPreviousData,
        // Tant qu'un document est en cours d'analyse on rafraichit : iOS n'a
        // pas de Background Sync, le polling est le seul moyen de voir la fin
        // du traitement sans action de l'utilisateur.
        refetchInterval: (q) => {
            const data = q.state.data as PaginatedEnvelope<Document> | undefined;
            return data?.data.some((doc) => !doc.is_terminal) ? 4000 : false;
        },
    });

    const list = documents.data?.data ?? [];

    return (
        <div className="flex flex-col gap-4 px-4 pb-8 pt-2">
            <header className="flex items-baseline justify-between">
                <h1 className="text-xl font-semibold">Documents</h1>
                {documents.data?.meta && (
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {documents.data.meta.total} au total
                    </span>
                )}
            </header>

            {outbox.length > 0 && (
                <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    {outbox.length} document{outbox.length > 1 ? 's' : ''} en attente d’envoi. Ils partiront
                    automatiquement des que la connexion le permettra.
                </p>
            )}

            <div className="flex flex-col gap-2">
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Rechercher un document…"
                    className="rounded-xl border border-neutral-300 px-3 py-2.5 text-base dark:border-neutral-700 dark:bg-neutral-900"
                />

                <label className="flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400">
                    <input
                        type="checkbox"
                        checked={semantic}
                        onChange={(event) => setSemantic(event.target.checked)}
                        className="size-4"
                    />
                    Recherche par le sens (et non par mots exacts)
                </label>

                <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                    {STATUS_FILTERS.map((filter) => (
                        <button
                            key={filter.value}
                            type="button"
                            onClick={() => setStatus(filter.value)}
                            className={`shrink-0 rounded-full border px-3 py-1.5 text-xs ${
                                status === filter.value
                                    ? 'border-sky-500 bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'
                                    : 'border-neutral-300 dark:border-neutral-700'
                            }`}
                        >
                            {filter.label}
                        </button>
                    ))}

                    {(categories.data?.data ?? []).map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setCategory((current) => (current === item.slug ? '' : item.slug))}
                            className={`shrink-0 rounded-full border px-3 py-1.5 text-xs ${
                                category === item.slug
                                    ? 'border-sky-500 bg-sky-50 font-semibold text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'
                                    : 'border-neutral-300 dark:border-neutral-700'
                            }`}
                        >
                            {item.name}
                        </button>
                    ))}
                </div>
            </div>

            {documents.isPending && (
                <p className="py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">Chargement…</p>
            )}

            {documents.isError && (
                <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
                    Impossible de charger les documents.
                </p>
            )}

            {!documents.isPending && list.length === 0 && (
                <div className="flex flex-col items-center gap-3 py-14 text-center">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {search || status || category
                            ? 'Aucun document ne correspond a cette recherche.'
                            : 'Aucun document pour l’instant.'}
                    </p>
                    {!search && !status && !category && (
                        <Link
                            to="/scan"
                            className="rounded-xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white"
                        >
                            Scanner un premier document
                        </Link>
                    )}
                </div>
            )}

            <ul className="flex flex-col gap-2">
                {list.map((document) => {
                    const cover = document.pages?.[0];

                    return (
                        <li key={document.id}>
                            <Link
                                to={`/documents/${document.id}`}
                                className="flex items-center gap-3 rounded-xl border border-neutral-200 p-2.5 active:bg-neutral-50 dark:border-neutral-800 dark:active:bg-neutral-900"
                            >
                                {cover?.thumb_url ? (
                                    <img
                                        src={cover.thumb_url}
                                        alt=""
                                        className="h-20 w-16 shrink-0 rounded-lg bg-neutral-100 object-cover dark:bg-neutral-900"
                                        loading="lazy"
                                    />
                                ) : (
                                    <div className="h-20 w-16 shrink-0 rounded-lg bg-neutral-100 dark:bg-neutral-900" />
                                )}

                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{document.title}</p>
                                    {document.issuer && (
                                        <p className="truncate text-sm text-neutral-600 dark:text-neutral-400">
                                            {document.issuer}
                                        </p>
                                    )}
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                        <StatusBadge document={document} />
                                        {document.category && (
                                            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                                {document.category.name}
                                            </span>
                                        )}
                                        {(document.todos_count ?? 0) > 0 && (
                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                {document.todos_count} tache{(document.todos_count ?? 0) > 1 ? 's' : ''}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
