import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Envelope } from './api';
import type { Person } from './people';

/**
 * Ce que l'utilisateur a decide d'une personne.
 *
 * Le serveur ne connait pas les « personnes » au sens ou le front les entend :
 * il ne stocke que celles dont une decision a ete prise — un nom choisi, une
 * photo. La liste renvoyee ici est donc courte, et se recolle aux groupes
 * calcules dans people.ts par la cle de rapprochement.
 */

/** Personne telle que le serveur la renvoie. */
export interface StoredPerson {
    id: string;
    /** La cle de rapprochement : c'est par elle qu'on recolle. */
    key: string;
    name: string;
    /** Le nom a-t-il ete choisi a la main ? Si oui, il prime sur les documents. */
    name_overridden: boolean;
    photo_url: string | null;
    photo_updated_at: string | null;
    expires_at: string | null;
}

/**
 * `staleTime` genereux : un nom et une photo changent a la main, pas toutes
 * seules. Les URLs sont signees pour une heure, on se refait une idee bien avant.
 */
export function useStoredPeople() {
    return useQuery({
        queryKey: ['people'],
        queryFn: () => api.get<Envelope<StoredPerson[]>>('/people'),
        staleTime: 10 * 60 * 1000,
    });
}

/**
 * Corps commun aux deux envois : la personne entiere, pas seulement ce qu'on
 * change. Au moment du geste elle n'existe peut-etre pas encore en base, et
 * les graphies partent avec pour que le serveur puisse rejouer un changement
 * de normalisation sans perdre le rattachement.
 */
function personFields(person: Person, form: FormData): void {
    form.append('key', person.key);
    // Meme plafond que cote serveur : au-dela, c'est un bug de normalisation.
    person.variants.slice(0, 25).forEach((variant) => {
        form.append('aliases[]', variant.raw);
    });
}

export function useSetPersonPhoto() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ person, file }: { person: Person; file: File }) => {
            const form = new FormData();

            personFields(person, form);
            form.append('name', person.name);
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

/**
 * Renommage. Le nom envoye ici est FIGE cote serveur : plus aucun document ne
 * le reecrira, y compris lors d'un futur envoi de photo.
 */
export function useRenamePerson() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ person, name }: { person: Person; name: string }) =>
            api.post<Envelope<StoredPerson>>('/people', {
                key: person.key,
                name,
                aliases: person.variants.slice(0, 25).map((variant) => variant.raw),
            }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ['people'] });
        },
    });
}

/**
 * Recolle sur les groupes calcules localement ce que le serveur a memorise.
 *
 * Le nom stocke ne prime QUE s'il a ete choisi a la main : sinon il n'est
 * qu'une copie de la graphie la plus frequente, et la valeur fraiche, celle
 * calculee a l'instant depuis les documents, est meilleure.
 */
export function attachStored(people: Person[], stored: StoredPerson[]): Person[] {
    if (stored.length === 0) return people;

    const byKey = new Map(stored.map((entry) => [entry.key, entry]));

    return people.map((person) => {
        const match = byKey.get(person.key);

        if (!match) return person;

        return {
            ...person,
            id: match.id,
            photoUrl: match.photo_url,
            name: match.name_overridden ? match.name : person.name,
        };
    });
}
