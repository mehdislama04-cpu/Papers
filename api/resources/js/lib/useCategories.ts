import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Envelope } from './api';
import type { Category, Document } from './types';

/**
 * Categories et classement.
 *
 * Le classement automatique reste la regle : l'analyse choisit un slug et le
 * document est range sans que personne n'y touche. Ce qui suit sert aux deux
 * cas ou la machine ne suffit pas — creer un rangement qu'elle ne connait pas,
 * et corriger un document qu'elle a mal place.
 */
export function useCategories() {
    return useQuery({
        queryKey: ['categories'],
        queryFn: () => api.get<Envelope<Category[]>>('/categories'),
        staleTime: 5 * 60 * 1000,
    });
}

export interface NewCategory {
    name: string;
    color?: string;
    icon?: string;
}

export function useCreateCategory() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (category: NewCategory) =>
            api.post<Envelope<Category>>('/categories', category),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['categories'] });
        },
    });
}

/**
 * Deplace un document.
 *
 * `category: null` est une valeur, pas un oubli : « sans categorie » est un
 * etat legitime. Le serveur exige d'ailleurs la cle presente.
 */
export function useMoveDocument() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, category }: { id: string; category: string | null }) =>
            api.patch<Envelope<Document>>(`/documents/${id}`, { category }),
        onSuccess: () => {
            // Les listes par personne et par categorie sont derivees des
            // documents : tout ce qui les alimente doit repartir.
            void client.invalidateQueries({ queryKey: ['documents'] });
            void client.invalidateQueries({ queryKey: ['document'] });
            void client.invalidateQueries({ queryKey: ['categories'] });
        },
    });
}
