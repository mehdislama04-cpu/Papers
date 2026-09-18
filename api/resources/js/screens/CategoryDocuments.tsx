import { useParams } from 'react-router';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api, type Envelope, type PaginatedEnvelope } from '../lib/api';
import type { Category, Document } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { CategoryChip } from '../components/ui/Chips';
import { DocumentRow } from '../components/ui/DocumentRow';
import { DocumentSkeletons, EmptyState, ErrorNote, Group } from '../components/ui/Layout';

/**
 * Les documents d'une categorie.
 *
 * Le filtre `?category=` existe cote serveur depuis l'origine : cet ecran ne
 * demande aucune route nouvelle.
 */
export default function CategoryDocuments() {
    const { slug } = useParams<{ slug: string }>();

    const categories = useQuery({
        queryKey: ['categories'],
        queryFn: () => api.get<Envelope<Category[]>>('/categories'),
        staleTime: 5 * 60 * 1000,
    });

    const documents = useQuery({
        queryKey: ['documents', 'category', slug],
        queryFn: () =>
            api.get<PaginatedEnvelope<Document>>(`/documents?category=${slug}&per_page=100`),
        enabled: Boolean(slug),
        placeholderData: keepPreviousData,
        refetchInterval: (query) => {
            const data = query.state.data as PaginatedEnvelope<Document> | undefined;
            return data?.data.some((doc) => !doc.is_terminal) ? 4000 : false;
        },
    });

    const category = (categories.data?.data ?? []).find((item) => item.slug === slug);
    const list = documents.data?.data ?? [];

    // Combien de destinataires distincts : c'est ce qui dit si la categorie
    // concerne une personne ou tout le foyer.
    const people = new Set(
        list.map((document) => document.recipient?.trim()).filter((name): name is string => Boolean(name)),
    );

    return (
        <div className="px-4 pb-8">
            <NavBar back="Categories" backTo="/?view=categories" />

            <div className="flex flex-col items-center gap-2.5 pt-1 pb-5">
                {category && <CategoryChip category={category} size="lg" />}
                <h1 className="text-[1.625rem] leading-8 font-bold tracking-[-0.02em]">
                    {category?.name ?? 'Categorie'}
                </h1>
                {!documents.isPending && (
                    <p className="text-[0.9375rem] text-fg-3">
                        {list.length} document{list.length > 1 ? 's' : ''}
                        {people.size > 1 && ` · ${people.size} personnes`}
                    </p>
                )}
            </div>

            {documents.isPending && <DocumentSkeletons />}
            {documents.isError && <ErrorNote>Impossible de charger cette categorie.</ErrorNote>}

            {!documents.isPending && list.length === 0 && (
                <EmptyState title="Categorie vide">
                    Aucun document n'est range ici pour le moment. Papers classe chaque document au
                    moment de l'analyse.
                </EmptyState>
            )}

            {list.length > 0 && (
                <Group>
                    {list.map((document, index) => (
                        <DocumentRow
                            key={document.id}
                            document={document}
                            index={index}
                            context="category"
                        />
                    ))}
                </Group>
            )}
        </div>
    );
}
