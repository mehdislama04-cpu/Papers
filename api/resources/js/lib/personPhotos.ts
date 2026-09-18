import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Envelope } from './api';
import type { Person } from './people';

/**
 * Photos des personnes.
 *
 * Le serveur ne connait pas les « personnes » au sens ou le front les entend :
 * il ne stocke que celles dont l'utilisateur a decide quelque chose. La liste
 * renvoyee ici est donc courte — un visage choisi a la main, pas un annuaire —
 * et se recolle aux groupes calcules dans people.ts par la cle de rapprochement.
 */

/** Personne telle que le serveur la renvoie. */
export interface StoredPerson {
    id: string;
    /** La cle de rapprochement : c'est par elle qu'on recolle. */
    key: string;
    name: string;
    photo_url: string | null;
    photo_updated_at: string | null;
    expires_at: string | null;
}

/**
 * `staleTime` genereux : une photo change a la main, pas toute seule. Les URLs
 * sont signees pour une heure, on se refait une idee bien avant.
 */
export function useStoredPeople() {
    return useQuery({
        queryKey: ['people'],
        queryFn: () => api.get<Envelope<StoredPerson[]>>('/people'),
        staleTime: 10 * 60 * 1000,
    });
}

/**
 * Envoi d'une photo.
 *
 * On envoie la personne entiere, pas seulement l'image : au moment du geste
 * elle n'existe peut-etre pas encore en base. Les graphies partent avec, pour
 * que le serveur puisse rejouer un changement de normalisation sans perdre le
 * rattachement (cf. la migration person_aliases).
 */
export function useSetPersonPhoto() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ person, file }: { person: Person; file: File }) => {
            const form = new FormData();

            form.append('key', person.key);
            form.append('name', person.name);
            // Meme plafond que cote serveur : au-dela, c'est un bug de
            // normalisation, pas un usage.
            person.variants.slice(0, 25).forEach((variant) => {
                form.append('aliases[]', variant.raw);
            });
            form.append('photo', file);

            return api.upload<Envelope<StoredPerson>>('/people/photo', form);
        },
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['people'] });
        },
    });
}

export function useRemovePersonPhoto() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: (personId: string) => api.delete(`/people/${personId}/photo`),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['people'] });
        },
    });
}

/** Recolle les photos stockees sur les groupes calcules localement. */
export function attachPhotos(people: Person[], stored: StoredPerson[]): Person[] {
    if (stored.length === 0) return people;

    const byKey = new Map(stored.map((entry) => [entry.key, entry]));

    return people.map((person) => {
        const match = byKey.get(person.key);

        return match ? { ...person, id: match.id, photoUrl: match.photo_url } : person;
    });
}
