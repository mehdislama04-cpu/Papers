import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { categoryColor } from '../lib/categories';
import { useOutbox } from '../lib/hooks';
import { groupByRecipient, needsAttention, PEOPLE_PAGE, type Person } from '../lib/people';
import { attachStored, useStoredPeople } from '../lib/personPhotos';
import { PICTOGRAM_FAMILY, PICTOGRAM_PEOPLE } from '../lib/pictograms';
import type { Document, DocumentStatus } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { Chevron } from '../components/ui/Chips';
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

function SearchField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
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
 * Les personnes — premiere marche de la navigation.
 *
 * On entre dans ses papiers par QUI ils concernent, pas par ce qu'ils sont :
 * une personne rassemble naturellement des categories heterogenes, alors
 * qu'une categorie eparpille les personnes.
 * ---------------------------------------------------------------------- */
function PersonRow({ person }: { person: Person }) {
    return (
        <Link
            to={`/people/${encodeURIComponent(person.key)}`}
            className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
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
                    {person.documents.length} document{person.documents.length > 1 ? 's' : ''}
                </p>
            </div>
            <Chevron />
        </Link>
    );
}

function PeopleSection() {
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

    const people = useMemo(
        () => attachStored(grouped, storedPeople.data?.data ?? []),
        [grouped, storedPeople.data],
    );

    const toMerge = useMemo(() => needsAttention(people), [people]);

    if (documents.isPending) {
        return (
            <>
                <SectionTitle>Personnes</SectionTitle>
                <Group>
                    <div className="h-[4.25rem] animate-pulse bg-surface-2" aria-hidden="true" />
                    <div className="h-[4.25rem] animate-pulse bg-surface-2" aria-hidden="true" />
                </Group>
            </>
        );
    }

    if (people.length === 0 && unassigned.length === 0) return null;

    return (
        <>
            <SectionTitle>Personnes</SectionTitle>
            <Group>
                {people.map((person) => (
                    <PersonRow key={person.key} person={person} />
                ))}

                {unassigned.length > 0 && (
                    <Link
                        to="/people/sans-destinataire"
                        className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                    >
                        <img
                            src={PICTOGRAM_FAMILY}
                            alt=""
                            width={46}
                            height={46}
                            className="size-[2.875rem] shrink-0"
                        />
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

                {toMerge.length > 0 && (
                    <Link
                        to="/people/groups"
                        className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                    >
                        <img
                            src={PICTOGRAM_PEOPLE}
                            alt=""
                            width={46}
                            height={46}
                            className="size-[2.875rem] shrink-0"
                        />
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
                )}
            </Group>
        </>
    );
}

/* -------------------------------------------------------------------------
 * Tous les documents, sous les personnes.
 * ---------------------------------------------------------------------- */
function AllDocuments({
    search,
    status,
    onStatus,
    semantic,
    onSemantic,
}: {
    search: string;
    status: DocumentStatus | '';
    onStatus: (value: DocumentStatus | '') => void;
    semantic: boolean;
    onSemantic: (value: boolean) => void;
}) {
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
            <SectionTitle>{filtering ? 'Resultats' : 'Tous les documents'}</SectionTitle>

            <div className="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1">
                {STATUS_FILTERS.map((filter) => (
                    <button
                        key={filter.value}
                        type="button"
                        onClick={() => onStatus(filter.value)}
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
                <label className="mb-3 flex min-h-11 items-center gap-2.5 text-[0.9375rem] text-fg-2">
                    <input
                        type="checkbox"
                        checked={semantic}
                        onChange={(event) => onSemantic(event.target.checked)}
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

/* -------------------------------------------------------------------------- */

/**
 * Premiere marche : les personnes, puis tous les documents.
 *
 * Le selecteur « Tous / Personnes / Categories » a disparu. Il demandait de
 * choisir un classement AVANT de savoir ce qu'on cherchait, et les categories
 * n'ont de sens qu'une fois la personne connue — un « Facture » qui melange
 * trois membres du foyer ne range rien. Elles vivent maintenant une marche plus
 * bas, dans la fiche de chaque personne.
 *
 * Pendant une recherche, la liste des personnes s'efface : la recherche porte
 * sur les documents, la garder afficherait un resultat qui ne bouge pas.
 */
export default function Documents() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<DocumentStatus | ''>('');
    const [semantic, setSemantic] = useState(false);

    const outbox = useOutbox();
    const filtering = Boolean(search.trim() || status);

    return (
        <div className="px-4 pb-8">
            <NavBar title="Documents">
                <SearchField value={search} onChange={setSearch} />
            </NavBar>

            {outbox.length > 0 && (
                <p className="mb-3 rounded-sm bg-soon-bg px-3.5 py-2.5 text-[0.9375rem] text-soon-fg">
                    {outbox.length} document{outbox.length > 1 ? 's' : ''} en attente d'envoi. Ils
                    partiront des que la connexion le permettra.
                </p>
            )}

            {!filtering && <PeopleSection />}

            <AllDocuments
                search={search}
                status={status}
                onStatus={setStatus}
                semantic={semantic}
                onSemantic={setSemantic}
            />
        </div>
    );
}
