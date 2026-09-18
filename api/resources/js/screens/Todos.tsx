import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { cn } from '../lib/cn';
import type { Todo, TodoStatus } from '../lib/types';

import { NavBar, NavAction } from '../components/ui/NavBar';
import { DocumentSkeletons, EmptyState, ErrorNote, Group, SectionTitle } from '../components/ui/Layout';

type Bucket = 'late' | 'today' | 'week' | 'later' | 'undated' | 'done';

const BUCKET_LABELS: Record<Bucket, string> = {
    late: 'En retard',
    today: 'Aujourd’hui',
    week: 'Cette semaine',
    later: 'Plus tard',
    undated: 'Sans echeance',
    done: 'Terminees',
};

const BUCKET_ORDER: Bucket[] = ['late', 'today', 'week', 'later', 'undated', 'done'];

function bucketOf(todo: Todo, now: Date): Bucket {
    if (!todo.due_at) return 'undated';

    const due = new Date(todo.due_at);
    if (Number.isNaN(due.getTime())) return 'undated';

    const endOfToday = new Date(now);
    endOfToday.setHours(23, 59, 59, 999);

    if (due < now) return 'late';
    if (due <= endOfToday) return 'today';

    const endOfWeek = new Date(endOfToday);
    endOfWeek.setDate(endOfWeek.getDate() + 7);

    return due <= endOfWeek ? 'week' : 'later';
}

/**
 * La date, en colonne : le jour en gros, le mois dessous.
 *
 * C'est la raison d'etre de l'app — ne rien rater. Une echeance doit se voir
 * avant d'etre lue ; une ligne de texte gris parmi d'autres ne le permet pas.
 */
function When({ todo, late }: { todo: Todo; late: boolean }) {
    if (!todo.due_at) {
        return (
            <div className="w-11.5 shrink-0 text-center text-fg-3">
                <span aria-hidden="true" className="text-[1.375rem] leading-6 font-bold">
                    —
                </span>
                <span className="sr-only">Sans echeance</span>
            </div>
        );
    }

    const date = new Date(todo.due_at);
    if (Number.isNaN(date.getTime())) return null;

    return (
        <div className={cn('w-11.5 shrink-0 text-center', late && 'text-late-fg')}>
            <b className="block text-[1.375rem] leading-6 font-bold tracking-[-0.02em]">
                {date.getDate()}
            </b>
            <span
                className={cn(
                    'block text-[0.6875rem] leading-[0.875rem] font-semibold tracking-[0.04em] uppercase',
                    late ? 'text-late-fg' : 'text-fg-3',
                )}
            >
                {date.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '')}
            </span>
            {!todo.all_day && (
                <span className="sr-only">
                    {date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                </span>
            )}
        </div>
    );
}

function CalendarMark({ todo }: { todo: Todo }) {
    if (!todo.due_at) return null;

    if (!todo.calendar) return <>hors calendrier</>;
    if (todo.calendar.sync_status === 'synced') return <>dans le calendrier</>;
    if (todo.calendar.sync_status === 'failed') return <span className="text-late-fg">non synchronise</span>;

    return <>synchronisation en attente</>;
}

export default function Todos() {
    const client = useQueryClient();
    const [showDone, setShowDone] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    const status: TodoStatus | '' = showDone ? '' : 'pending';

    const query = useQuery({
        queryKey: ['todos', status],
        queryFn: () =>
            api.get<PaginatedEnvelope<Todo>>(`/todos${status ? `?status=${status}&per_page=200` : '?per_page=200'}`),
    });

    const update = useMutation({
        mutationFn: ({ id, patch }: { id: string; patch: Partial<Todo> }) => api.patch(`/todos/${id}`, patch),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['todos'] });
            void client.invalidateQueries({ queryKey: ['document'] });
        },
    });

    const remove = useMutation({
        mutationFn: (id: string) => api.delete(`/todos/${id}`),
        onSuccess: () => client.invalidateQueries({ queryKey: ['todos'] }),
    });

    const grouped = useMemo(() => {
        const now = new Date();
        const map = new Map<Bucket, Todo[]>();

        for (const todo of query.data?.data ?? []) {
            // Une tache faite a son propre groupe. Elle atterrissait jusqu'ici
            // dans « Plus tard », ce qui melait le fini et le a-venir.
            const key: Bucket = todo.status === 'done' ? 'done' : bucketOf(todo, now);
            const list = map.get(key) ?? [];
            list.push(todo);
            map.set(key, list);
        }

        return map;
    }, [query.data]);

    const total = query.data?.data.length ?? 0;

    return (
        <div className="px-4 pb-8">
            <NavBar
                title="Taches"
                action={
                    <NavAction onClick={() => setShowDone((current) => !current)}>
                        {showDone ? 'Masquer les faites' : 'Voir les faites'}
                    </NavAction>
                }
            />

            {query.isPending && <DocumentSkeletons count={3} />}
            {query.isError && <ErrorNote>Impossible de charger les taches.</ErrorNote>}

            {!query.isPending && total === 0 && (
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
                            <path d="m4 7 2 2 3.5-3.5" />
                            <path d="m4 17 2 2 3.5-3.5" />
                            <path d="M13 7.5h7M13 17.5h7" />
                        </svg>
                    }
                    title="Rien a suivre"
                >
                    Les echeances apparaissent ici toutes seules, des qu'un document scanne en contient.
                </EmptyState>
            )}

            {BUCKET_ORDER.map((bucket) => {
                const items = grouped.get(bucket) ?? [];
                if (items.length === 0) return null;

                return (
                    <section key={bucket}>
                        <SectionTitle tone={bucket === 'late' ? 'late' : 'default'}>
                            {BUCKET_LABELS[bucket]} · {items.length}
                        </SectionTitle>

                        <Group>
                            {items.map((todo, index) => {
                                const done = todo.status === 'done';
                                const late = bucket === 'late' && !done;

                                return (
                                    <div
                                        key={todo.id}
                                        className="rise px-3.5 py-3"
                                        style={{ ['--i' as string]: Math.min(index, 8) }}
                                    >
                                        <div className="flex items-start gap-3">
                                            <button
                                                type="button"
                                                aria-label={done ? 'Rouvrir la tache' : 'Marquer comme faite'}
                                                onClick={() =>
                                                    update.mutate({
                                                        id: todo.id,
                                                        patch: { status: done ? 'pending' : 'done' },
                                                    })
                                                }
                                                className="pressable -m-2.5 flex size-11 shrink-0 items-center justify-center p-2.5"
                                            >
                                                <span
                                                    className={cn(
                                                        'flex size-6.5 items-center justify-center rounded-full border-2 transition-colors',
                                                        done
                                                            ? 'border-done-fg bg-done-fg text-white'
                                                            : 'border-edge',
                                                    )}
                                                >
                                                    {done && (
                                                        <svg
                                                            viewBox="0 0 14 14"
                                                            className="size-3.5"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth={2.4}
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                            aria-hidden="true"
                                                        >
                                                            <path d="m2 7.5 3.5 3.5L12 4" />
                                                        </svg>
                                                    )}
                                                </span>
                                            </button>

                                            <When todo={todo} late={late} />

                                            <div className="min-w-0 flex-1">
                                                <p
                                                    className={cn(
                                                        'leading-[1.375rem]',
                                                        done && 'text-fg-3 line-through',
                                                    )}
                                                >
                                                    {todo.title}
                                                </p>

                                                {todo.details && (
                                                    <p className="mt-0.5 text-[0.8125rem] text-fg-2">
                                                        {todo.details}
                                                    </p>
                                                )}

                                                <p className="mt-0.5 text-[0.8125rem] leading-[1.125rem] text-fg-3">
                                                    {todo.document && (
                                                        <Link
                                                            to={`/documents/${todo.document.id}`}
                                                            className="text-accent"
                                                        >
                                                            {todo.document.title}
                                                        </Link>
                                                    )}
                                                    {todo.document && todo.due_at && ' · '}
                                                    <CalendarMark todo={todo} />
                                                </p>
                                            </div>

                                            <button
                                                type="button"
                                                aria-label={
                                                    editing === todo.id
                                                        ? 'Fermer la modification'
                                                        : 'Modifier la tache'
                                                }
                                                onClick={() =>
                                                    setEditing((c) => (c === todo.id ? null : todo.id))
                                                }
                                                className="pressable -m-2.5 flex size-11 shrink-0 items-center justify-center text-fg-3"
                                            >
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    className="size-5"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth={1.8}
                                                    strokeLinecap="round"
                                                    aria-hidden="true"
                                                >
                                                    <circle cx="5" cy="12" r="1.4" fill="currentColor" />
                                                    <circle cx="12" cy="12" r="1.4" fill="currentColor" />
                                                    <circle cx="19" cy="12" r="1.4" fill="currentColor" />
                                                </svg>
                                            </button>
                                        </div>

                                        {editing === todo.id && (
                                            <div className="mt-3 flex flex-col gap-3 border-t border-hairline pt-3">
                                                <label className="flex flex-col gap-1.5">
                                                    <span className="text-[0.8125rem] text-fg-2">
                                                        Echeance
                                                    </span>
                                                    <input
                                                        type="datetime-local"
                                                        defaultValue={
                                                            todo.due_at
                                                                ? new Date(todo.due_at)
                                                                      .toISOString()
                                                                      .slice(0, 16)
                                                                : ''
                                                        }
                                                        onChange={(event) =>
                                                            update.mutate({
                                                                id: todo.id,
                                                                patch: {
                                                                    due_at: event.target.value
                                                                        ? new Date(
                                                                              event.target.value,
                                                                          ).toISOString()
                                                                        : null,
                                                                },
                                                            })
                                                        }
                                                        className="h-11 rounded-sm border border-edge bg-surface px-3"
                                                    />
                                                </label>

                                                <button
                                                    type="button"
                                                    onClick={() => remove.mutate(todo.id)}
                                                    className="pressable flex h-11 items-center self-start font-medium text-late-fg"
                                                >
                                                    Supprimer cette tache
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </Group>
                    </section>
                );
            })}
        </div>
    );
}
