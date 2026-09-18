import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { groupByRecipient, PEOPLE_PAGE } from '../lib/people';
import { attachStored, useStoredPeople } from '../lib/personPhotos';
import { PICTOGRAM_FAMILY } from '../lib/pictograms';
import { useCategories } from '../lib/useCategories';
import type { Document } from '../lib/types';

import { NavBar, NavAction } from '../components/ui/NavBar';
import { Chevron } from '../components/ui/Chips';
import { CategorySheet } from '../components/ui/CategorySheet';
import { CategoryTile } from '../components/ui/CategoryTile';
import { PersonAvatar } from '../components/ui/PersonAvatar';
import { DocumentSkeletons, EmptyState, ErrorNote, Group } from '../components/ui/Layout';

const UNASSIGNED = 'sans-destinataire';

/**
 * Deuxieme marche : les categories d'une personne.
 *
 * Cet ecran ne montre plus la liste des documents — elle est descendue d'un
 * cran. Une personne qui accumule trente papiers ne se lit pas en une liste
 * plate : ses categories disent d'un coup d'oeil ce qu'elle genere comme
 * paperasse, et c'est cette repartition qu'on vient chercher ici.
 *
 * Seules les categories REELLEMENT presentes sont affichees. Une grille de
 * douze tuiles dont neuf vides ferait passer un rangement possible pour un
 * rangement existant.
 */
export default function PersonDocuments() {
    const { person: key } = useParams<{ person: string }>();
    const decoded = key ? decodeURIComponent(key) : '';
    const isUnassigned = decoded === UNASSIGNED;

    const [creating, setCreating] = useState(false);

    const categories = useCategories();

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

    const withStored = useMemo(
        () => attachStored(people, storedPeople.data?.data ?? []),
        [people, storedPeople.data],
    );

    const person = withStored.find((candidate) => candidate.key === decoded);
    const list = isUnassigned ? unassigned : (person?.documents ?? []);
    const name = isUnassigned ? 'Sans destinataire' : (person?.name ?? 'Personne');

    /*
     | Repartition par categorie. Pour une personne, `spread` l'a deja calculee ;
     | pour les documents sans destinataire il faut la refaire, ils ne forment
     | pas une personne.
     */
    const spread = useMemo(() => {
        if (!isUnassigned) return person?.spread ?? [];

        const map = new Map<string, { category: NonNullable<Document['category']>; count: number }>();

        for (const document of unassigned) {
            if (!document.category) continue;
            const entry = map.get(document.category.slug) ?? { category: document.category, count: 0 };
            entry.count += 1;
            map.set(document.category.slug, entry);
        }

        return [...map.values()].sort((a, b) => b.count - a.count);
    }, [isUnassigned, person, unassigned]);

    /*
     | Les tuiles affichees.
     |
     | Une categorie SYSTEME vide reste cachee : douze tuiles dont neuf a zero
     | feraient passer un rangement possible pour un rangement existant.
     |
     | Une categorie PERSONNELLE vide, elle, s'affiche. La difference n'est pas
     | cosmetique : celle-la, quelqu'un l'a creee expres, a l'instant. La cacher
     | jusqu'a ce qu'elle contienne un document donnait exactement l'impression
     | que la creation n'avait rien fait.
     */
    const tiles = useMemo(() => {
        const byCategory = new Map(spread.map((entry) => [entry.category.slug, entry]));

        for (const category of categories.data?.data ?? []) {
            if (category.is_system || byCategory.has(category.slug)) continue;

            byCategory.set(category.slug, { category, count: 0 });
        }

        return [...byCategory.values()].sort(
            (a, b) => b.count - a.count || a.category.name.localeCompare(b.category.name, 'fr'),
        );
    }, [spread, categories.data]);

    const classified = spread.reduce((total, entry) => total + entry.count, 0);
    const unclassified = list.length - classified;

    const base = `/people/${encodeURIComponent(decoded)}`;

    return (
        <div className="px-4 pb-8">
            <NavBar
                back="Documents"
                backTo="/"
                action={
                    <NavAction onClick={() => setCreating(true)}>
                        <span className="sr-only">Nouvelle catégorie</span>
                        <svg
                            viewBox="0 0 24 24"
                            className="size-6"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={2.2}
                            strokeLinecap="round"
                            aria-hidden="true"
                        >
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </NavAction>
                }
            />

            <CategorySheet open={creating} onClose={() => setCreating(false)} startInCreate />

            <div className="flex flex-col items-center gap-2 pt-1 pb-4">
                {isUnassigned || !person ? (
                    <img src={PICTOGRAM_FAMILY} alt="" width={76} height={76} className="size-19" />
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
                    <p className="text-center text-[0.9375rem] text-fg-3">
                        Choisissez une categorie de documents.
                    </p>
                )}
            </div>

            {documents.isPending && <DocumentSkeletons count={2} />}
            {documents.isError && <ErrorNote>Impossible de charger les documents.</ErrorNote>}

            {!documents.isPending && list.length === 0 && (
                <EmptyState title="Aucun document">Rien n'est rattache a ce destinataire.</EmptyState>
            )}

            {list.length > 0 && (
                <>
                    <Group className="mb-3">
                        <Link
                            to={`${base}/tout`}
                            className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="leading-[1.375rem] font-semibold">Tous ses documents</p>
                                <p className="text-[0.875rem] text-fg-3">
                                    {list.length} document{list.length > 1 ? 's' : ''}, classes par date
                                </p>
                            </div>
                            <Chevron />
                        </Link>
                    </Group>

                    <div className="grid grid-cols-3 gap-2.5">
                        {tiles.map((entry) => (
                            <CategoryTile
                                key={entry.category.slug}
                                category={entry.category}
                                count={entry.count}
                                to={`${base}/${entry.category.slug}`}
                            />
                        ))}

                        {unclassified > 0 && (
                            <CategoryTile
                                category={{
                                    slug: 'sans-categorie',
                                    name: 'Sans categorie',
                                    color: null,
                                    icon: 'folder',
                                }}
                                count={unclassified}
                                to={`${base}/sans-categorie`}
                            />
                        )}
                    </div>
                </>
            )}

            {person && person.variants.length > 1 && (
                <p className="mt-5 px-2.5 text-center text-[0.875rem] leading-5 text-fg-3">
                    Regroupe {person.variants.length} graphies :{' '}
                    {person.variants.map((variant) => `« ${variant.raw} »`).join(', ')}.
                </p>
            )}
        </div>
    );
}
