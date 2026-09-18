import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type PaginatedEnvelope } from '../lib/api';
import { groupByRecipient, PEOPLE_PAGE } from '../lib/people';
import { attachStored, useStoredPeople } from '../lib/personPhotos';
import { categoryPictogram } from '../lib/pictograms';
import type { Document } from '../lib/types';

import { useCategories, useDeleteCategory } from '../lib/useCategories';

import { NavBar, NavAction } from '../components/ui/NavBar';
import { CategoryChip } from '../components/ui/Chips';
import { DocumentRow } from '../components/ui/DocumentRow';
import { DocumentSkeletons, EmptyState, ErrorNote, Group, SectionTitle } from '../components/ui/Layout';
import { Segmented } from '../components/ui/Segmented';

const UNASSIGNED = 'sans-destinataire';

/** Pseudo-categories : tout ce qui n'est pas un slug servi par l'API. */
const ALL = 'tout';
const UNCLASSIFIED = 'sans-categorie';

type Sort = 'date' | 'amount';

const SORTS: Array<{ value: Sort; label: string }> = [
    { value: 'date', label: 'Par date' },
    { value: 'amount', label: 'Par montant' },
];

/**
 * La periode d'un document : ce qui fait les intertitres de la liste.
 *
 * On prefere `doc_date` — la date IMPRIMEE sur le papier — a la date de scan :
 * une facture d'aout scannee en octobre appartient a aout. Sans date lue, on
 * retombe sur la date de creation, qui est au moins vraie.
 */
function periodOf(document: Document, now: Date): { key: string; label: string } {
    const raw = document.doc_date ?? document.created_at;
    const date = new Date(raw);

    if (Number.isNaN(date.getTime())) return { key: 'sans-date', label: 'Sans date' };

    const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();

    if (sameDay(date, now)) return { key: 'today', label: "Aujourd'hui" };

    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(date, yesterday)) return { key: 'yesterday', label: 'Hier' };

    const label = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

    return {
        key: `${date.getFullYear()}-${String(date.getMonth()).padStart(2, '0')}`,
        label: label.charAt(0).toUpperCase() + label.slice(1),
    };
}

function timeOf(document: Document): number {
    const date = new Date(document.doc_date ?? document.created_at);

    return Number.isNaN(date.getTime()) ? 0 : date.getTime();
}

function amountOf(document: Document): number {
    const value = Number.parseFloat(document.total_amount ?? '');

    return Number.isNaN(value) ? -1 : value;
}

/**
 * Troisieme marche : les documents d'une personne dans une categorie.
 *
 * Les intertitres par periode ne sont pas decoratifs. Une liste plate de
 * quarante factures se parcourt a l'aveugle ; groupee par mois, on sait
 * toujours ou l'on se trouve, et un mois manquant se voit.
 */
export default function PersonCategoryDocuments() {
    const { person: key, slug } = useParams<{ person: string; slug: string }>();
    const decoded = key ? decodeURIComponent(key) : '';
    const isUnassigned = decoded === UNASSIGNED;

    const [sort, setSort] = useState<Sort>('date');
    const [confirmDelete, setConfirmDelete] = useState(false);

    const navigate = useNavigate();
    const categories = useCategories();
    const removeCategory = useDeleteCategory();

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
    const owned = isUnassigned ? unassigned : (person?.documents ?? []);
    const personName = isUnassigned ? 'Sans destinataire' : (person?.name ?? 'Personne');

    const list = useMemo(() => {
        const filtered = owned.filter((document) => {
            if (slug === ALL) return true;
            if (slug === UNCLASSIFIED) return !document.category;

            return document.category?.slug === slug;
        });

        return [...filtered].sort((a, b) =>
            sort === 'amount' ? amountOf(b) - amountOf(a) : timeOf(b) - timeOf(a),
        );
    }, [owned, slug, sort]);

    /*
     | La categorie vient de la LISTE des categories, pas d'un document.
     |
     | La deduire des documents presents marchait tant qu'il y en avait : une
     | categorie vide — celle qu'on vient justement de creer — s'affichait
     | « Categorie », sans nom ni couleur.
     */
    const category =
        (categories.data?.data ?? []).find((item) => item.slug === slug) ??
        list.find((document) => document.category?.slug === slug)?.category;

    const title =
        slug === ALL
            ? 'Tous ses documents'
            : slug === UNCLASSIFIED
              ? 'Sans categorie'
              : (category?.name ?? 'Categorie');

    const pictogram = categoryPictogram(slug);

    /** Les periodes, dans l'ordre ou la liste triee les rencontre. */
    const periods = useMemo(() => {
        if (sort !== 'date') return [];

        const now = new Date();
        const buckets = new Map<string, { label: string; documents: Document[] }>();

        for (const document of list) {
            const period = periodOf(document, now);
            const bucket = buckets.get(period.key) ?? { label: period.label, documents: [] };
            bucket.documents.push(document);
            buckets.set(period.key, bucket);
        }

        return [...buckets.values()];
    }, [list, sort]);

    return (
        <div className="px-4 pb-8">
            <NavBar
                back={personName}
                backTo={`/people/${encodeURIComponent(decoded)}`}
                action={
                    /*
                     * Seulement les categories PERSONNELLES. Les douze du socle
                     * sont le vocabulaire du modele d'extraction : en retirer
                     * une casserait le classement pour tout le monde.
                     */
                    category && !category.is_system ? (
                        <NavAction
                            onClick={() => {
                                if (!confirmDelete) {
                                    setConfirmDelete(true);

                                    return;
                                }

                                removeCategory.mutate(category.id, {
                                    onSuccess: () =>
                                        navigate(`/people/${encodeURIComponent(decoded)}`, {
                                            replace: true,
                                        }),
                                });
                            }}
                            disabled={removeCategory.isPending}
                        >
                            {confirmDelete ? 'Confirmer' : 'Supprimer'}
                        </NavAction>
                    ) : undefined
                }
            />

            {confirmDelete && category && (
                <p className="mb-3 rounded-md bg-soon-bg px-3.5 py-2.5 text-[0.9375rem] text-soon-fg">
                    {/* « Les 0 document qu'elle contient » : le cas vide merite sa
                        propre phrase, il est le plus frequent juste apres une
                        creation ratee. */}
                    {list.length === 0
                        ? `Supprimer « ${category.name} » ? Elle ne contient aucun document.`
                        : `Supprimer « ${category.name} » ? ${
                              list.length === 1
                                  ? 'Le document qu’elle contient ne sera pas supprimé : il redeviendra'
                                  : `Les ${list.length} documents qu’elle contient ne seront pas supprimés : ils redeviendront`
                          } simplement sans catégorie.`}
                </p>
            )}

            <div className="flex flex-col items-center gap-2 pt-1 pb-3">
                {pictogram ? (
                    <img src={pictogram} alt="" width={64} height={64} className="size-16" />
                ) : (
                    category && <CategoryChip category={category} size="lg" />
                )}

                <h1 className="text-center font-serif text-[1.625rem] leading-8 font-semibold tracking-[-0.02em]">
                    {title}
                </h1>

                <p className="text-center text-[0.9375rem] text-fg-3">
                    {personName} · {list.length} document{list.length > 1 ? 's' : ''}
                </p>
            </div>

            {list.length > 1 && (
                <Segmented value={sort} onChange={setSort} options={SORTS} label="Classement" />
            )}

            {documents.isPending && <DocumentSkeletons />}
            {documents.isError && <ErrorNote>Impossible de charger les documents.</ErrorNote>}

            {!documents.isPending && list.length === 0 && (
                <EmptyState title="Aucun document">
                    Rien dans cette categorie pour {personName}.
                </EmptyState>
            )}

            {sort === 'date'
                ? periods.map((period) => (
                      <div key={period.label}>
                          <div className="flex items-baseline justify-between">
                              <SectionTitle>{period.label}</SectionTitle>
                              <span className="mt-5.5 mb-2 text-[0.8125rem] text-fg-3">
                                  {period.documents.length} document
                                  {period.documents.length > 1 ? 's' : ''}
                              </span>
                          </div>
                          <Group>
                              {period.documents.map((document, index) => (
                                  <DocumentRow
                                      key={document.id}
                                      document={document}
                                      index={index}
                                      context="person"
                                  />
                              ))}
                          </Group>
                      </div>
                  ))
                : list.length > 0 && (
                      <Group className="mt-2">
                          {list.map((document, index) => (
                              <DocumentRow
                                  key={document.id}
                                  document={document}
                                  index={index}
                                  context="person"
                              />
                          ))}
                      </Group>
                  )}

            {/* La meme categorie, mais pour tout le monde : utile quand un
                papier a ete range sous le mauvais destinataire. */}
            {category && withStored.length > 1 && (
                <p className="mt-5 text-center">
                    <Link
                        to={`/categories/${category.slug}`}
                        className="text-[0.9375rem] font-medium text-accent"
                    >
                        Voir « {category.name} » pour tout le monde
                    </Link>
                </p>
            )}
        </div>
    );
}
