import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Envelope } from '../lib/api';
import { formatAmount, type Document, type Todo } from '../lib/types';

function formatDate(value: string | null): string | null {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
}

function Row({ label, value }: { label: string; value: string | null }) {
    if (!value) return null;

    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-neutral-100 py-2 last:border-0 dark:border-neutral-800">
            <dt className="shrink-0 text-sm text-neutral-500 dark:text-neutral-400">{label}</dt>
            <dd className="text-right text-sm font-medium">{value}</dd>
        </div>
    );
}

function TodoLine({ todo }: { todo: Todo }) {
    const due = formatDate(todo.due_at);
    const late = todo.due_at !== null && new Date(todo.due_at) < new Date() && todo.status === 'pending';

    return (
        <li className="flex items-start gap-2 border-b border-neutral-100 py-2 last:border-0 dark:border-neutral-800">
            <span
                className={`mt-1.5 size-2 shrink-0 rounded-full ${
                    todo.status === 'done' ? 'bg-emerald-500' : late ? 'bg-red-500' : 'bg-amber-500'
                }`}
                aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
                <p className={`text-sm ${todo.status === 'done' ? 'text-neutral-400 line-through' : ''}`}>
                    {todo.title}
                </p>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    {due ? (late ? `En retard — ${due}` : due) : 'Sans echeance'}
                    {todo.calendar?.sync_status === 'synced' && ' · dans le calendrier'}
                    {todo.calendar?.sync_status === 'failed' && ' · non synchronise'}
                </p>
            </div>
        </li>
    );
}

export default function DocumentDetail() {
    const { document: id } = useParams<{ document: string }>();
    const navigate = useNavigate();
    const client = useQueryClient();
    const [page, setPage] = useState(0);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const query = useQuery({
        queryKey: ['document', id],
        queryFn: () => api.get<Envelope<Document>>(`/documents/${id}`),
        enabled: Boolean(id),
        refetchInterval: (q) => {
            const data = q.state.data as Envelope<Document> | undefined;
            return data && !data.data.is_terminal ? 3000 : false;
        },
    });

    const reanalyze = useMutation({
        mutationFn: () => api.post(`/documents/${id}/reanalyze`),
        onSuccess: () => client.invalidateQueries({ queryKey: ['document', id] }),
    });

    const remove = useMutation({
        mutationFn: () => api.delete(`/documents/${id}`),
        onSuccess: async () => {
            await client.invalidateQueries({ queryKey: ['documents'] });
            void navigate('/', { replace: true });
        },
    });

    if (query.isPending) {
        return <p className="px-4 py-10 text-center text-sm text-neutral-500">Chargement…</p>;
    }

    if (query.isError || !query.data) {
        return (
            <div className="px-4 py-10 text-center">
                <p className="text-sm text-red-600 dark:text-red-400">Ce document est introuvable.</p>
                <Link to="/" className="mt-3 inline-block text-sm font-medium text-sky-600">
                    Retour aux documents
                </Link>
            </div>
        );
    }

    const doc = query.data.data;
    const pages = doc.pages ?? [];
    const current = pages[Math.min(page, Math.max(0, pages.length - 1))];
    const amount = formatAmount(doc.total_amount, doc.currency);

    return (
        <div className="flex flex-col gap-5 px-4 pb-8 pt-2">
            <header>
                <h1 className="text-xl font-semibold">{doc.title}</h1>
                <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                    {doc.status_label}
                    {doc.category && ` · ${doc.category.name}`}
                    {` · ${doc.page_count} page${doc.page_count > 1 ? 's' : ''}`}
                </p>
            </header>

            {doc.status === 'failed' && doc.analysis_error && (
                <div className="rounded-lg bg-red-50 px-3 py-2 dark:bg-red-950/50">
                    <p className="text-sm font-medium text-red-800 dark:text-red-300">L’analyse a echoue</p>
                    <p className="mt-0.5 text-xs text-red-700 dark:text-red-400">{doc.analysis_error}</p>
                </div>
            )}

            {!doc.is_terminal && (
                <p className="flex items-center gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-950/50 dark:text-sky-300">
                    <span className="size-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Analyse en cours, cette page se mettra a jour toute seule.
                </p>
            )}

            {current && (
                <section className="flex flex-col gap-2">
                    <img
                        src={current.file_url}
                        alt={`Page ${current.page_number}`}
                        className="w-full rounded-xl border border-neutral-200 bg-neutral-50 object-contain dark:border-neutral-800 dark:bg-neutral-900"
                    />

                    {pages.length > 1 && (
                        <div className="flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.max(0, p - 1))}
                                disabled={page === 0}
                                className="rounded-lg border border-neutral-300 px-3 py-2 text-sm disabled:opacity-30 dark:border-neutral-700"
                            >
                                Precedente
                            </button>
                            <span className="text-sm text-neutral-500 dark:text-neutral-400">
                                {page + 1} / {pages.length}
                            </span>
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.min(pages.length - 1, p + 1))}
                                disabled={page >= pages.length - 1}
                                className="rounded-lg border border-neutral-300 px-3 py-2 text-sm disabled:opacity-30 dark:border-neutral-700"
                            >
                                Suivante
                            </button>
                        </div>
                    )}
                </section>
            )}

            {doc.summary && (
                <section>
                    <h2 className="mb-1 text-sm font-semibold">Resume</h2>
                    <p className="text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{doc.summary}</p>
                </section>
            )}

            <section>
                <h2 className="mb-1 text-sm font-semibold">Informations</h2>
                <dl>
                    <Row label="Emetteur" value={doc.issuer} />
                    <Row label="Destinataire" value={doc.recipient} />
                    <Row label="Date du document" value={formatDate(doc.doc_date)} />
                    <Row label="Montant" value={amount} />
                    <Row label="Reference" value={doc.reference} />
                    <Row label="Origine" value={doc.source_label} />
                    <Row label="Analyse le" value={formatDate(doc.analyzed_at)} />
                </dl>
            </section>

            {(doc.todos?.length ?? 0) > 0 && (
                <section>
                    <h2 className="mb-1 text-sm font-semibold">Taches extraites</h2>
                    <ul>
                        {doc.todos!.map((todo) => (
                            <TodoLine key={todo.id} todo={todo} />
                        ))}
                    </ul>
                </section>
            )}

            <section className="flex flex-col gap-2 pt-2">
                <button
                    type="button"
                    onClick={() => reanalyze.mutate()}
                    disabled={reanalyze.isPending || !doc.is_terminal}
                    className="rounded-xl border border-neutral-300 px-4 py-3 text-sm font-medium disabled:opacity-40 dark:border-neutral-700"
                >
                    {reanalyze.isPending ? 'Relance…' : 'Relancer l’analyse'}
                </button>

                {confirmDelete ? (
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => setConfirmDelete(false)}
                            className="flex-1 rounded-xl border border-neutral-300 px-4 py-3 text-sm dark:border-neutral-700"
                        >
                            Annuler
                        </button>
                        <button
                            type="button"
                            onClick={() => remove.mutate()}
                            disabled={remove.isPending}
                            className="flex-1 rounded-xl bg-red-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            Confirmer la suppression
                        </button>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => setConfirmDelete(true)}
                        className="rounded-xl border border-red-300 px-4 py-3 text-sm font-medium text-red-600 dark:border-red-900 dark:text-red-400"
                    >
                        Supprimer ce document
                    </button>
                )}
            </section>
        </div>
    );
}
