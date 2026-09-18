import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Envelope } from '../lib/api';
import { normalizeRecipient } from '../lib/people';
import { useMoveDocument } from '../lib/useCategories';
import { formatAmount, type Document, type Todo } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { CategoryChip } from '../components/ui/Chips';
import { CategorySheet } from '../components/ui/CategorySheet';
import { Button, ErrorNote, Group, Row, SectionTitle, Skeleton } from '../components/ui/Layout';

function formatDate(value: string | null): string | null {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
}

/**
 * Une ligne d'information pendant l'analyse : soit la valeur a atterri, soit
 * elle est encore attendue et on en montre l'emplacement.
 *
 * C'est le coeur de l'ecran : l'utilisateur voit le document se lire, champ
 * par champ, au lieu d'attendre devant un compteur.
 */
function LiveRow({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex items-center gap-4 px-3.5 py-3">
            <dt className="shrink-0 text-[0.9375rem] text-fg-2">{label}</dt>
            <dd className="ml-auto text-right font-medium">
                {value ? (
                    <span className="land inline-block">{value}</span>
                ) : (
                    <Skeleton className="h-3.5 w-24 rounded-full" />
                )}
            </dd>
        </div>
    );
}

function TodoLine({ todo }: { todo: Todo }) {
    const due = formatDate(todo.due_at);
    const late = todo.due_at !== null && new Date(todo.due_at) < new Date() && todo.status === 'pending';

    return (
        <div className="flex items-start gap-3 px-3.5 py-3">
            <span
                className={`mt-2 size-2 shrink-0 rounded-full ${
                    todo.status === 'done' ? 'bg-done-fg' : late ? 'bg-late-fg' : 'bg-soon-fg'
                }`}
                aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
                <p className={todo.status === 'done' ? 'text-fg-3 line-through' : undefined}>
                    {todo.title}
                </p>
                <p className="text-[0.8125rem] text-fg-3">
                    {due ? (late ? `En retard — ${due}` : due) : 'Sans echeance'}
                    {todo.calendar?.sync_status === 'synced' && ' · dans le calendrier'}
                    {todo.calendar?.sync_status === 'failed' && ' · non synchronise'}
                </p>
            </div>
        </div>
    );
}

export default function DocumentDetail() {
    const { document: id } = useParams<{ document: string }>();
    const navigate = useNavigate();
    const client = useQueryClient();
    const [page, setPage] = useState(0);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [moving, setMoving] = useState(false);

    const move = useMoveDocument();

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
        return (
            <div className="px-4 pb-8">
                <NavBar back="Documents" backTo="/" />
                <Skeleton className="mt-2 h-8 w-3/5 rounded-full" />
                <Skeleton className="mt-4 h-72 w-full rounded-md" />
            </div>
        );
    }

    if (query.isError || !query.data) {
        return (
            <div className="px-4 pb-8">
                <NavBar back="Documents" backTo="/" />
                <div className="py-10 text-center">
                    <ErrorNote>Ce document est introuvable.</ErrorNote>
                </div>
            </div>
        );
    }

    const doc = query.data.data;
    const pages = doc.pages ?? [];
    const current = pages[Math.min(page, Math.max(0, pages.length - 1))];
    const amount = formatAmount(doc.total_amount, doc.currency);
    const working = !doc.is_terminal;

    return (
        <div className="px-4 pb-8">
            <NavBar back="Documents" backTo="/" />

            <CategorySheet
                open={moving}
                onClose={() => setMoving(false)}
                value={doc.category?.slug ?? null}
                onPick={(slug) => move.mutate({ id: doc.id, category: slug })}
                allowNone
            />

            <header className="mb-4 flex items-start gap-3">
                {/*
                  La pastille est un BOUTON : c'est par elle qu'on corrige un
                  classement. L'analyse range toute seule et se trompe parfois ;
                  jusqu'ici la seule correction offerte etait de relancer
                  l'analyse, c'est-a-dire de reparier.
                */}
                <button
                    type="button"
                    onClick={() => setMoving(true)}
                    aria-label={
                        doc.category ? `Changer de catégorie — ${doc.category.name}` : 'Ranger dans une catégorie'
                    }
                    className="pressable shrink-0 rounded-[0.875rem]"
                >
                    {doc.category ? (
                        <CategoryChip category={doc.category} size="md" />
                    ) : (
                        <span className="flex size-11 items-center justify-center rounded-[0.875rem] border border-dashed border-edge text-fg-3">
                            <svg
                                viewBox="0 0 24 24"
                                className="size-5"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth={2}
                                strokeLinecap="round"
                                aria-hidden="true"
                            >
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                        </span>
                    )}
                </button>
                <div className="min-w-0 flex-1">
                    <h1 className="font-serif text-[1.625rem] leading-8 font-semibold tracking-[-0.02em]">
                        {doc.title}
                    </h1>
                    <p className="mt-0.5 text-[0.9375rem] text-fg-3">
                        {doc.category?.name ?? doc.status_label}
                        {` · ${doc.page_count} page${doc.page_count > 1 ? 's' : ''}`}
                    </p>
                </div>
            </header>

            {doc.status === 'failed' && doc.analysis_error && (
                <div className="mb-4 rounded-md bg-late-bg px-3.5 py-3">
                    <p className="font-semibold text-late-fg">L'analyse a echoue</p>
                    <p className="mt-0.5 text-[0.8125rem] text-late-fg">{doc.analysis_error}</p>
                </div>
            )}

            {current && (
                <section className="mb-4">
                    <div className={`relative overflow-hidden rounded-md bg-surface ${working ? 'sweep' : ''}`}>
                        <img
                            src={current.file_url}
                            alt={`Page ${current.page_number}`}
                            className="w-full object-contain"
                        />
                    </div>

                    {pages.length > 1 && (
                        <div className="mt-2 flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.max(0, p - 1))}
                                disabled={page === 0}
                                className="pressable tap-target flex items-center rounded-sm px-3 text-accent disabled:opacity-30"
                            >
                                Precedente
                            </button>
                            <span className="text-[0.9375rem] text-fg-3">
                                {page + 1} / {pages.length}
                            </span>
                            <button
                                type="button"
                                onClick={() => setPage((p) => Math.min(pages.length - 1, p + 1))}
                                disabled={page >= pages.length - 1}
                                className="pressable tap-target flex items-center rounded-sm px-3 text-accent disabled:opacity-30"
                            >
                                Suivante
                            </button>
                        </div>
                    )}
                </section>
            )}

            {working && (
                <p className="mb-4 px-2 text-center text-[0.9375rem] leading-[1.3125rem] text-fg-2">
                    Lecture du document… Les informations apparaissent a mesure. Vous pouvez quitter cet
                    ecran.
                </p>
            )}

            {(doc.summary || working) && (
                <>
                    <SectionTitle>Resume</SectionTitle>
                    <Group className="p-3.5">
                        {doc.summary ? (
                            <p className="extracted-text leading-[1.4375rem] text-fg">{doc.summary}</p>
                        ) : (
                            <div className="space-y-2.5">
                                <Skeleton className="h-3 w-full rounded-full" />
                                <Skeleton className="h-3 w-11/12 rounded-full" />
                                <Skeleton className="h-3 w-2/3 rounded-full" />
                            </div>
                        )}
                    </Group>
                </>
            )}

            <SectionTitle>Informations</SectionTitle>
            <Group>
                <dl className="contents">
                    {working ? (
                        <>
                            <LiveRow label="Emetteur" value={doc.issuer} />
                            <LiveRow label="Destinataire" value={doc.recipient} />
                            <LiveRow label="Date" value={formatDate(doc.doc_date)} />
                            <LiveRow label="Montant" value={amount} />
                        </>
                    ) : (
                        <>
                            <Row label="Emetteur" value={doc.issuer} />
                            <Row
                                label="Destinataire"
                                value={
                                    doc.recipient ? (
                                        <Link
                                            to={`/people/${encodeURIComponent(normalizeRecipient(doc.recipient))}`}
                                            className="text-accent"
                                        >
                                            {doc.recipient}
                                        </Link>
                                    ) : null
                                }
                            />
                            <Row label="Date du document" value={formatDate(doc.doc_date)} />
                            <Row label="Montant" value={amount} />
                            <Row label="Reference" value={doc.reference} />
                            <Row label="Origine" value={doc.source_label} />
                            <Row label="Analyse le" value={formatDate(doc.analyzed_at)} />
                        </>
                    )}
                </dl>
            </Group>

            {(doc.todos?.length ?? 0) > 0 && (
                <>
                    <SectionTitle>Taches extraites</SectionTitle>
                    <Group>
                        {doc.todos!.map((todo) => (
                            <TodoLine key={todo.id} todo={todo} />
                        ))}
                    </Group>
                </>
            )}

            <div className="mt-6 flex flex-col gap-2.5">
                <Button
                    variant="ghost"
                    onClick={() => reanalyze.mutate()}
                    disabled={reanalyze.isPending || working}
                >
                    {reanalyze.isPending ? 'Relance…' : "Relancer l'analyse"}
                </Button>

                {confirmDelete ? (
                    <div className="flex gap-2.5">
                        <Button variant="ghost" onClick={() => setConfirmDelete(false)}>
                            Annuler
                        </Button>
                        <Button
                            onClick={() => remove.mutate()}
                            disabled={remove.isPending}
                            className="bg-late-fg text-white"
                        >
                            Supprimer
                        </Button>
                    </div>
                ) : (
                    <Button variant="danger" onClick={() => setConfirmDelete(true)}>
                        Supprimer ce document
                    </Button>
                )}
            </div>
        </div>
    );
}
