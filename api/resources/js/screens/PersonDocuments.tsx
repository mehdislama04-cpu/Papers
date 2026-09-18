import { useMemo } from 'react';
import { useParams } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { categoryColor } from '../lib/categories';
import { groupByRecipient, PEOPLE_PAGE } from '../lib/people';
import { attachStored, useStoredPeople } from '../lib/personPhotos';
import type { Document } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { PersonAvatar } from '../components/ui/PersonAvatar';
import { DocumentRow } from '../components/ui/DocumentRow';
import {
    DocumentSkeletons,
    EmptyState,
    ErrorNote,
    Group,
    SectionTitle,
} from '../components/ui/Layout';

const UNASSIGNED = 'sans-destinataire';

/** Une echeance depassee ou proche, sur un document non termine. */
function pressing(document: Document): boolean {
    return (document.todos_count ?? 0) > 0;
}

export default function PersonDocuments() {
    const { person: key } = useParams<{ person: string }>();
    const decoded = key ? decodeURIComponent(key) : '';
    const isUnassigned = decoded === UNASSIGNED;

    const documents = useQuery({
        queryKey: ['documents', 'people'],
        queryFn: () => api.get<PaginatedEnvelope<Document>>(PEOPLE_PAGE),
        placeholderData: keepPreviousData,
    });

    const storedPeople = useStoredPeople();

    const { people, unassigned } = useMemo(
        () => groupByRecipient(documents.data?.data ?? []),
        [documents.data],
    );

    const withPhotos = useMemo(
        () => attachStored(people, storedPeople.data?.data ?? []),
        [people, storedPeople.data],
    );

    const person = withPhotos.find((candidate) => candidate.key === decoded);
    const list = isUnassigned ? unassigned : (person?.documents ?? []);
    const urgent = list.filter(pressing);
    const rest = list.filter((document) => !pressing(document));

    const name = isUnassigned ? 'Sans destinataire' : (person?.name ?? 'Personne');

    return (
        <div className="px-4 pb-8">
            <NavBar back="Personnes" backTo="/?view=people" />

            <div className="flex flex-col items-center gap-2.5 pt-1 pb-5">
                {isUnassigned || !person ? (
                    <span className="inline-flex size-19 items-center justify-center rounded-full bg-surface-2 text-fg-3">
                        <svg
                            viewBox="0 0 24 24"
                            className="size-9"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={1.6}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="8" r="3.6" />
                            <path d="M4.8 20a7.4 7.4 0 0 1 14.4 0" />
                        </svg>
                    </span>
                ) : (
                    /*
                     * Ici l'avatar n'est pas dans un lien : il devient un vrai
                     * bouton. Un appui simple suffit, le geste est atteignable
                     * au clavier et annonce par VoiceOver — la contrepartie
                     * necessaire de l'appui long, qui ne s'annonce nulle part.
                     */
                    <PersonAvatar person={person} size="lg" as="button" />
                )}

                <h1 className="text-center font-serif text-[1.625rem] leading-8 font-semibold tracking-[-0.02em]">
                    {name}
                </h1>

                {!documents.isPending && (
                    <p className="text-[0.9375rem] text-fg-3">
                        {list.length} document{list.length > 1 ? 's' : ''}
                    </p>
                )}
            </div>

            {/* Repartition par categorie : ce que cette personne genere comme
                paperasse, d'un coup d'oeil. */}
            {person && person.spread.length > 1 && (
                <>
                    <div className="mb-2 flex h-2.5 overflow-hidden rounded-full">
                        {person.spread.map((entry) => (
                            <span
                                key={entry.category.slug}
                                style={{
                                    backgroundColor: categoryColor(entry.category),
                                    width: `${(entry.count / person.documents.length) * 100}%`,
                                }}
                            />
                        ))}
                    </div>
                    <div className="mb-1 flex flex-wrap justify-center gap-x-3 gap-y-1.5">
                        {person.spread.map((entry) => (
                            <span
                                key={entry.category.slug}
                                className="inline-flex items-center gap-1.5 text-xs text-fg-2"
                            >
                                <span
                                    className="size-2.5 rounded-[3px]"
                                    style={{ backgroundColor: categoryColor(entry.category) }}
                                    aria-hidden="true"
                                />
                                {entry.category.name} {entry.count}
                            </span>
                        ))}
                    </div>
                </>
            )}

            {documents.isPending && <DocumentSkeletons />}
            {documents.isError && <ErrorNote>Impossible de charger les documents.</ErrorNote>}

            {!documents.isPending && list.length === 0 && (
                <EmptyState title="Aucun document">
                    Rien n'est rattache a ce destinataire.
                </EmptyState>
            )}

            {urgent.length > 0 && (
                <>
                    <SectionTitle>A traiter</SectionTitle>
                    <Group>
                        {urgent.map((document, index) => (
                            <DocumentRow
                                key={document.id}
                                document={document}
                                index={index}
                                context="person"
                            />
                        ))}
                    </Group>
                </>
            )}

            {rest.length > 0 && (
                <>
                    {urgent.length > 0 && <SectionTitle>Tous ses documents</SectionTitle>}
                    <Group className={urgent.length > 0 ? undefined : 'mt-2'}>
                        {rest.map((document, index) => (
                            <DocumentRow
                                key={document.id}
                                document={document}
                                index={index}
                                context="person"
                            />
                        ))}
                    </Group>
                </>
            )}

            {person && person.variants.length > 1 && (
                <p className="mt-4 px-2.5 text-center text-[0.875rem] leading-5 text-fg-3">
                    Regroupe {person.variants.length} graphies :{' '}
                    {person.variants.map((variant) => `« ${variant.raw} »`).join(', ')}.
                </p>
            )}
        </div>
    );
}
