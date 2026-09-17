import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import type { Todo, TodoStatus } from '../lib/types';

type Bucket = 'late' | 'today' | 'week' | 'later' | 'undated';

const BUCKET_LABELS: Record<Bucket, string> = {
    late: 'En retard',
    today: 'Aujourd’hui',
    week: 'Cette semaine',
    later: 'Plus tard',
    undated: 'Sans echeance',
};

const BUCKET_ORDER: Bucket[] = ['late', 'today', 'week', 'later', 'undated'];

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

function formatDue(todo: Todo): string {
    if (!todo.due_at) return 'Sans echeance';

    const date = new Date(todo.due_at);
    if (Number.isNaN(date.getTime())) return todo.due_at;

    return todo.all_day
        ? date.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' })
        : date.toLocaleString('fr-FR', {
              weekday: 'short',
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

function CalendarMark({ todo }: { todo: Todo }) {
    if (!todo.due_at) return null;

    if (!todo.calendar) {
        return <span className="text-[11px] text-neutral-400">hors calendrier</span>;
    }

    if (todo.calendar.sync_status === 'synced') {
        return <span className="text-[11px] text-emerald-600 dark:text-emerald-400">dans le calendrier</span>;
    }

    if (todo.calendar.sync_status === 'failed') {
        return (
            <span className="text-[11px] text-red-600 dark:text-red-400" title={todo.calendar.last_error ?? undefined}>
                synchronisation en echec
            </span>
        );
    }

    return <span className="text-[11px] text-neutral-400">synchronisation en attente</span>;
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
            const bucket = todo.status === 'done' ? 'undated' : bucketOf(todo, now);
            const key = todo.status === 'done' ? 'later' : bucket;
            const list = map.get(key) ?? [];
            list.push(todo);
            map.set(key, list);
        }

        return map;
    }, [query.data]);

    return (
        <div className="flex flex-col gap-4 px-4 pb-8 pt-2">
            <header className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">Taches</h1>
                <label className="flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400">
                    <input
                        type="checkbox"
                        checked={showDone}
                        onChange={(event) => setShowDone(event.target.checked)}
                        className="size-4"
                    />
                    Afficher les terminees
                </label>
            </header>

            {query.isPending && (
                <p className="py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">Chargement…</p>
            )}

            {!query.isPending && (query.data?.data.length ?? 0) === 0 && (
                <p className="py-14 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    Aucune tache. Elles apparaitront automatiquement quand un document scanne en contiendra.
                </p>
            )}

            {BUCKET_ORDER.map((bucket) => {
                const items = grouped.get(bucket) ?? [];
                if (items.length === 0) return null;

                return (
                    <section key={bucket}>
                        <h2
                            className={`mb-1.5 text-sm font-semibold ${
                                bucket === 'late' ? 'text-red-600 dark:text-red-400' : ''
                            }`}
                        >
                            {BUCKET_LABELS[bucket]}
                            <span className="ml-1.5 font-normal text-neutral-400">{items.length}</span>
                        </h2>

                        <ul className="flex flex-col gap-1.5">
                            {items.map((todo) => (
                                <li
                                    key={todo.id}
                                    className="rounded-xl border border-neutral-200 p-3 dark:border-neutral-800"
                                >
                                    <div className="flex items-start gap-3">
                                        <button
                                            type="button"
                                            aria-label={todo.status === 'done' ? 'Rouvrir la tache' : 'Marquer comme faite'}
                                            onClick={() =>
                                                update.mutate({
                                                    id: todo.id,
                                                    patch: { status: todo.status === 'done' ? 'pending' : 'done' },
                                                })
                                            }
                                            className={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2 ${
                                                todo.status === 'done'
                                                    ? 'border-emerald-500 bg-emerald-500 text-white'
                                                    : 'border-neutral-300 dark:border-neutral-600'
                                            }`}
                                        >
                                            {todo.status === 'done' && '✓'}
                                        </button>

                                        <div className="min-w-0 flex-1">
                                            <p
                                                className={`text-sm font-medium ${
                                                    todo.status === 'done' ? 'text-neutral-400 line-through' : ''
                                                }`}
                                            >
                                                {todo.title}
                                            </p>

                                            {todo.details && (
                                                <p className="mt-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                                                    {todo.details}
                                                </p>
                                            )}

                                            <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                <span
                                                    className={`text-xs ${
                                                        bucket === 'late' && todo.status !== 'done'
                                                            ? 'font-medium text-red-600 dark:text-red-400'
                                                            : 'text-neutral-500 dark:text-neutral-400'
                                                    }`}
                                                >
                                                    {formatDue(todo)}
                                                </span>
                                                <CalendarMark todo={todo} />
                                            </div>

                                            {todo.document && (
                                                <Link
                                                    to={`/documents/${todo.document.id}`}
                                                    className="mt-1 inline-block truncate text-xs text-sky-600 dark:text-sky-400"
                                                >
                                                    {todo.document.title}
                                                </Link>
                                            )}

                                            {editing === todo.id && (
                                                <div className="mt-2 flex flex-col gap-2">
                                                    <label className="flex flex-col gap-1 text-xs">
                                                        <span className="text-neutral-500 dark:text-neutral-400">
                                                            Echeance
                                                        </span>
                                                        <input
                                                            type="datetime-local"
                                                            defaultValue={
                                                                todo.due_at
                                                                    ? new Date(todo.due_at).toISOString().slice(0, 16)
                                                                    : ''
                                                            }
                                                            onChange={(event) =>
                                                                update.mutate({
                                                                    id: todo.id,
                                                                    patch: {
                                                                        due_at: event.target.value
                                                                            ? new Date(event.target.value).toISOString()
                                                                            : null,
                                                                    },
                                                                })
                                                            }
                                                            className="rounded-lg border border-neutral-300 px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                                        />
                                                    </label>

                                                    <button
                                                        type="button"
                                                        onClick={() => remove.mutate(todo.id)}
                                                        className="self-start text-xs font-medium text-red-600 dark:text-red-400"
                                                    >
                                                        Supprimer cette tache
                                                    </button>
                                                </div>
                                            )}
                                        </div>

                                        <button
                                            type="button"
                                            aria-label="Modifier"
                                            onClick={() => setEditing((c) => (c === todo.id ? null : todo.id))}
                                            className="shrink-0 rounded-lg px-2 py-1 text-xs text-neutral-500 dark:text-neutral-400"
                                        >
                                            {editing === todo.id ? 'Fermer' : 'Modifier'}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}
