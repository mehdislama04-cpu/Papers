import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope, type Envelope } from '../lib/api';
import { categoryColor } from '../lib/categories';
import { useOutbox } from '../lib/hooks';
import { groupByRecipient, needsAttention, PEOPLE_PAGE } from '../lib/people';
import { attachPhotos, useStoredPeople } from '../lib/personPhotos';
import type { Category, Document, DocumentStatus } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { Segmented } from '../components/ui/Segmented';
import { CategoryChip, Chevron } from '../components/ui/Chips';
import { PersonAvatar } from '../components/ui/PersonAvatar';
import { DocumentRow } from '../components/ui/DocumentRow';
import {
    Button,
    DocumentSkeletons,
    EmptyState,
    ErrorNote,
    Group,
    SectionTitle,
} from '../components/ui/Layout';

type View = 'all' | 'people' | 'categories';

const VIEWS: Array<{ value: View; label: string }> = [
    { value: 'all', label: 'Tous' },
    { value: 'people', label: 'Personnes' },
    { value: 'categories', label: 'Categories' },
];

const STATUS_FILTERS: Array<{ value: DocumentStatus | ''; label: string }> = [
    { value: '', label: 'Tous' },
    { value: 'processing', label: 'En analyse' },
    { value: 'analyzed', label: 'Analyses' },
    { value: 'failed', label: 'En echec' },
];

/** Rafraichit tant qu'un document n'a pas fini son analyse. iOS n'a pas de
 *  Background Sync : le polling est le seul moyen de voir la fin du traitement
 *  sans action de l'utilisateur. */
function pollWhilePending(data: PaginatedEnvelope<Document> | undefined) {
    return data?.data.some((doc) => !doc.is_terminal) ? 4000 : false;
}

function SearchField({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="mb-3 flex h-11 items-center gap-2 rounded-sm bg-surface-2 px-3">
            <svg
                viewBox="0 0 20 20"
                className="size-[1.125rem] shrink-0 text-fg-3"
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                aria-hidden="true"
            >
                <circle cx="8.5" cy="8.5" r="5.5" />
                <path d="m13 13 4 4" />
            </svg>
            <input
                type="search"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder="Rechercher"
                aria-label="Rechercher un document"
                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-fg-3"
            />
        </div>
    );
}

/* -------------------------------------------------------------------------
 * Vue « Tous » — la liste chronologique, la recherche et les filtres.
 * ---------------------------------------------------------------------- */
function AllView() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<DocumentStatus | ''>('');
    const [semantic, setSemantic] = useState(false);

    const query = useMemo(() => {
        const params = new URLSearchParams();
        if (search.trim()) params.set('search', search.trim());
        if (status) params.set('status', status);
        if (semantic && search.trim()) params.set('semantic', '1');
        return params.toString();
    }, [search, status, semantic]);

    const documents = useQuery({
        queryKey: ['documents', query],
        queryFn: () => api.get<PaginatedEnvelope<Document>>(`/documents${query ? `?${query}` : ''}`),
        placeholderData: keepPreviousData,
        refetchInterval: (q) => pollWhilePending(q.state.data as PaginatedEnvelope<Document> | undefined),
    });

    const list = documents.data?.data ?? [];
    const filtering = Boolean(search || status);

    return (
        <>
            <SearchField value={search} onChange={setSearch} />

            <div className="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1">
                {STATUS_FILTERS.map((filter) => (
                    <button
                        key={filter.value}
                        type="button"
                        onClick={() => setStatus(filter.value)}
                        className={`pressable flex h-9 shrink-0 items-center rounded-full px-3.5 text-[0.9375rem] ${
                            status === filter.value
                                ? 'bg-accent font-semibold text-on-accent'
                                : 'bg-surface-2 font-medium text-fg-2'
                        }`}
                    >
                        {filter.label}
                    </button>
                ))}
            </div>

            {search.trim() && (
                <label className="mb-4 flex min-h-11 items-center gap-2.5 text-[0.9375rem] text-fg-2">
                    <input
                        type="checkbox"
                        checked={semantic}
                        onChange={(event) => setSemantic(event.target.checked)}
                        className="size-5 accent-[var(--color-accent)]"
                    />
                    Chercher par le sens, et non par mots exacts
                </label>
            )}

            {documents.isPending && <DocumentSkeletons />}

            {documents.isError && <ErrorNote>Impossible de charger les documents.</ErrorNote>}

            {!documents.isPending && list.length === 0 && (
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
                            <path d="M6 3h7l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                            <path d="M13 3v5h5" />
                            <path d="M8.5 13h7M8.5 16.5h4.5" />
                        </svg>
                    }
                    title={filtering ? 'Aucun resultat' : 'Rien a classer'}
                    action={
                        filtering ? undefined : (
                            <Link to="/scan" className="w-full max-w-64">
                                <Button>Scanner un document</Button>
                            </Link>
                        )
                    }
                >
                    {filtering
                        ? 'Aucun document ne correspond a cette recherche.'
                        : 'Photographiez une facture, un courrier, une ordonnance. Papers en tire le resume, les montants et les echeances.'}
                </EmptyState>
            )}

            {list.length > 0 && (
                <Group>
                    {list.map((document, index) => (
                        <DocumentRow key={document.id} document={document} index={index} />
                    ))}
                </Group>
            )}
        </>
    );
}

/* -------------------------------------------------------------------------
 * Vue « Categories » — les tuiles.
 * ---------------------------------------------------------------------- */
function CategoryTile({ category }: { category: Category }) {
    const count = category.documents_count ?? 0;

    return (
        <Link
            to={`/categories/${category.slug}`}
            className="pressable flex min-h-23 flex-col justify-between gap-2 rounded-md bg-surface p-3.5 shadow-[0_1px_2px_oklch(0.2_0.01_258/0.05)]"
        >
            <CategoryChip category={category} size="md" />
            <div>
                <p className="font-semibold tracking-[-0.01em]">{category.name}</p>
                <p className="text-[0.8125rem] text-fg-3">
                    {count === 0 ? 'Vide' : `${count} document${count > 1 ? 's' : ''}`}
                </p>
            </div>
        </Link>
    );
}

function CategoriesView() {
    const categories = useQuery({
        queryKey: ['categories'],
        queryFn: () => api.get<Envelope<Category[]>>('/categories'),
        staleTime: 5 * 60 * 1000,
    });

    // Le serveur trie par nom. On remonte ce qui est reellement rempli : une
    // categorie a 34 documents interesse plus qu'une categorie vide, et
    // « Administratif » n'a aucune raison de passer devant « Facture ».
    const list = useMemo(
        () =>
            [...(categories.data?.data ?? [])].sort(
                (a, b) =>
                    (b.documents_count ?? 0) - (a.documents_count ?? 0) ||
                    a.name.localeCompare(b.name, 'fr'),
            ),
        [categories.data],
    );

    if (categories.isPending) {
        return (
            <div className="grid grid-cols-2 gap-2.5">
                {Array.from({ length: 8 }, (_, index) => (
                    <div key={index} className="h-23 rounded-md bg-surface-2" aria-hidden="true" />
                ))}
            </div>
        );
    }

    if (categories.isError) return <ErrorNote>Impossible de charger les categories.</ErrorNote>;

    return (
        <>
            <div className="grid grid-cols-2 gap-2.5">
                {list.map((category) => (
                    <CategoryTile key={category.id} category={category} />
                ))}
            </div>
            <p className="mt-4 px-2.5 text-center text-[0.875rem] leading-5 text-fg-3">
                Une categorie vide reste affichee : c'est un rangement possible, pas une absence.
            </p>
        </>
    );
}

/* -------------------------------------------------------------------------
 * Vue « Personnes » — regroupement des destinataires.
 * ---------------------------------------------------------------------- */
function PeopleView() {
    // Une page large : le regroupement se fait cote client, faute d'entite
    // « personne » cote serveur (cf. lib/people.ts).
    const documents = useQuery({
        queryKey: ['documents', 'people'],
        queryFn: () => api.get<PaginatedEnvelope<Document>>(PEOPLE_PAGE),
        placeholderData: keepPreviousData,
    });

    const storedPeople = useStoredPeople();

    const { people: grouped, unassigned } = useMemo(
        () => groupByRecipient(documents.data?.data ?? []),
        [documents.data],
    );

    // Les groupes viennent des documents, les photos du serveur : on recolle
    // les deux par la cle de rapprochement.
    const people = useMemo(
        () => attachPhotos(grouped, storedPeople.data?.data ?? []),
        [grouped, storedPeople.data],
    );

    const toMerge = useMemo(() => needsAttention(people), [people]);

    if (documents.isPending) return <DocumentSkeletons count={3} />;
    if (documents.isError) return <ErrorNote>Impossible de charger les documents.</ErrorNote>;

    if (people.length === 0 && unassigned.length === 0) {
        return (
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
                        <circle cx="12" cy="8" r="3.6" />
                        <path d="M4.8 20a7.4 7.4 0 0 1 14.4 0" />
                    </svg>
                }
                title="Personne, pour l'instant"
            >
                Le destinataire est extrait du document lui-meme. Il apparaitra des qu'un document
                analyse en portera un.
            </EmptyState>
        );
    }

    return (
        <>
            <Group>
                {people.map((person, index) => (
                    <Link
                        key={person.key}
                        to={`/people/${encodeURIComponent(person.key)}`}
                        className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                        style={{ ['--i' as string]: Math.min(index, 8) }}
                    >
                        <PersonAvatar person={person} />
                        <div className="min-w-0 flex-1">
                            <p className="truncate leading-[1.375rem] font-semibold">{person.name}</p>
                            <p className="flex items-center gap-1.5 text-[0.875rem] text-fg-3">
                                {person.spread.length > 0 && (
                                    <span className="flex gap-[3px]" aria-hidden="true">
                                        {person.spread.slice(0, 3).map((entry) => (
                                            <span
                                                key={entry.category.slug}
                                                className="size-2 rounded-[3px]"
                                                style={{ backgroundColor: categoryColor(entry.category) }}
                                            />
                                        ))}
                                    </span>
                                )}
                                {person.documents.length} document
                                {person.documents.length > 1 ? 's' : ''}
                            </p>
                        </div>
                        <Chevron />
                    </Link>
                ))}

                {unassigned.length > 0 && (
                    <Link
                        to="/people/sans-destinataire"
                        className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                    >
                        <span className="inline-flex size-[2.875rem] shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-3">
                            <svg
                                viewBox="0 0 24 24"
                                className="size-5.5"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth={1.8}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="8" r="3.6" />
                                <path d="M4.8 20a7.4 7.4 0 0 1 14.4 0" />
                            </svg>
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate leading-[1.375rem] font-semibold text-fg-2">
                                Sans destinataire
                            </p>
                            <p className="text-[0.875rem] text-fg-3">
                                {unassigned.length} document{unassigned.length > 1 ? 's' : ''}
                            </p>
                        </div>
                        <Chevron />
                    </Link>
                )}
            </Group>

            {toMerge.length > 0 && (
                <>
                    <SectionTitle>Regroupements</SectionTitle>
                    <Group>
                        <Link
                            to="/people/groups"
                            className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                        >
                            <span className="inline-flex size-[2.875rem] shrink-0 items-center justify-center rounded-full bg-soon-bg text-[1.0625rem] font-semibold text-soon-fg">
                                {toMerge.length}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="leading-[1.375rem] font-semibold">
                                    {toMerge.length === 1
                                        ? 'Un nom rapproche de plusieurs graphies'
                                        : `${toMerge.length} noms rapproches de plusieurs graphies`}
                                </p>
                                <p className="truncate text-[0.875rem] text-fg-3">
                                    {toMerge[0].variants
                                        .slice(0, 2)
                                        .map((variant) => `« ${variant.raw} »`)
                                        .join(', ')}
                                    …
                                </p>
                            </div>
                            <Chevron />
                        </Link>
                    </Group>
                    <p className="mt-3 px-2.5 text-center text-[0.875rem] leading-5 text-fg-3">
                        Papers ne rapproche que les graphies d'un meme nom. Verifiez qu'aucune ne l'a
                        ete a tort.
                    </p>
                </>
            )}
        </>
    );
}

/* -------------------------------------------------------------------------- */

export default function Documents() {
    // La vue vit dans l'URL, pas dans un etat local : sans cela, revenir d'une
    // categorie ou d'une personne ramenerait toujours sur « Tous ».
    // Query param et non fragment : sur iOS, un changement de hash revoque la
    // permission camera d'une PWA installee (ARCHITECTURE.md §3).
    const [params, setParams] = useSearchParams();
    const raw = params.get('view');
    const view: View = raw === 'people' || raw === 'categories' ? raw : 'all';

    const setView = (next: View) => {
        const copy = new URLSearchParams(params);
        if (next === 'all') copy.delete('view');
        else copy.set('view', next);
        setParams(copy, { replace: true });
    };

    const outbox = useOutbox();

    return (
        <div className="px-4 pb-8">
            <NavBar title="Documents">
                <Segmented value={view} onChange={setView} options={VIEWS} label="Classement" />
            </NavBar>

            {outbox.length > 0 && (
                <p className="mb-3 rounded-sm bg-soon-bg px-3.5 py-2.5 text-[0.9375rem] text-soon-fg">
                    {outbox.length} document{outbox.length > 1 ? 's' : ''} en attente d'envoi. Ils
                    partiront des que la connexion le permettra.
                </p>
            )}

            {view === 'all' && <AllView />}
            {view === 'people' && <PeopleView />}
            {view === 'categories' && <CategoriesView />}
        </div>
    );
}
