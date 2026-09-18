import { useMemo } from 'react';
import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { groupByRecipient, needsAttention, PEOPLE_PAGE } from '../lib/people';
import { attachPhotos, useStoredPeople } from '../lib/personPhotos';
import type { Document } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { PersonAvatar } from '../components/ui/PersonAvatar';
import { DocumentSkeletons, EmptyState, ErrorNote, Group } from '../components/ui/Layout';

/**
 * Ce que Papers a rapproche tout seul.
 *
 * L'ecran est volontairement en LECTURE. Fusionner ou separer deux
 * destinataires suppose de memoriser la decision, donc une entite « personne »
 * cote serveur — table `people` + `person_aliases`, colonne
 * `documents.person_id`. Elle n'existe pas encore : offrir ici un bouton
 * « Fusionner » qui ne survivrait pas au rechargement serait un faux.
 *
 * En attendant, montrer les regroupements a une vraie valeur : l'utilisateur
 * peut VERIFIER qu'aucune graphie n'a ete rapprochee a tort.
 */
export default function PeopleGroups() {
    const documents = useQuery({
        queryKey: ['documents', 'people'],
        queryFn: () => api.get<PaginatedEnvelope<Document>>(PEOPLE_PAGE),
    });

    const storedPeople = useStoredPeople();

    const { people } = useMemo(() => groupByRecipient(documents.data?.data ?? []), [documents.data]);

    const withPhotos = useMemo(
        () => attachPhotos(people, storedPeople.data?.data ?? []),
        [people, storedPeople.data],
    );

    const grouped = useMemo(() => needsAttention(withPhotos), [withPhotos]);

    return (
        <div className="px-4 pb-8">
            <NavBar back="Personnes" backTo="/?view=people" title="Regroupements" />

            <p className="mb-4 px-0.5 text-[0.9375rem] leading-[1.3125rem] text-fg-2">
                Un document imprime le nom du destinataire comme il veut. Papers rapproche les graphies
                qui ne different que par la casse, les accents, la civilite ou l'ordre du nom et du
                prenom.
            </p>

            {documents.isPending && <DocumentSkeletons count={2} />}
            {documents.isError && <ErrorNote>Impossible de charger les documents.</ErrorNote>}

            {!documents.isPending && grouped.length === 0 && (
                <EmptyState title="Rien a rapprocher">
                    Chaque destinataire n'apparait que sous une seule graphie.
                </EmptyState>
            )}

            <div className="flex flex-col gap-2.5">
                {grouped.map((person) => (
                    <Group key={person.key}>
                        <Link
                            to={`/people/${encodeURIComponent(person.key)}`}
                            className="pressable flex items-center gap-3 px-3.5 py-3 active:bg-surface-2"
                        >
                            <PersonAvatar person={person} size="sm" />
                            <div className="min-w-0">
                                <p className="truncate font-semibold">{person.name}</p>
                                <p className="text-[0.8125rem] text-fg-3">
                                    {person.variants.length} graphies · {person.documents.length}{' '}
                                    document{person.documents.length > 1 ? 's' : ''}
                                </p>
                            </div>
                        </Link>

                        {person.variants.map((variant) => (
                            <div
                                key={variant.raw}
                                className="flex items-center gap-2.5 py-2 pr-3.5 pl-4 text-[0.9375rem] text-fg-2"
                            >
                                <span
                                    className="flex size-5.5 shrink-0 items-center justify-center rounded-full bg-done-bg text-[0.8125rem] text-done-fg"
                                    aria-hidden="true"
                                >
                                    ✓
                                </span>
                                <span className="truncate">{variant.raw}</span>
                                <span className="ml-auto shrink-0 text-[0.8125rem] text-fg-3">
                                    {variant.count} doc.
                                </span>
                            </div>
                        ))}
                    </Group>
                ))}
            </div>

            {grouped.length > 0 && (
                <p className="mt-4 px-2.5 text-center text-[0.875rem] leading-5 text-fg-3">
                    Un rapprochement vous semble faux ? Corrigez le destinataire sur le document
                    concerne. La fusion et la separation manuelles arriveront avec la gestion des
                    personnes cote serveur.
                </p>
            )}
        </div>
    );
}
