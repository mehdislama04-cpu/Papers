import type { Category, Document } from './types';

/**
 * Requete servant au regroupement par destinataire.
 *
 * 100 est le plafond de `per_page` cote serveur (IndexDocumentRequest). Au-dela
 * de cent documents, le regroupement client devient partiel : c'est la limite
 * qui imposera tot ou tard la table `people` et un `GET /api/people`.
 */
export const PEOPLE_PAGE = '/documents?per_page=100';

export interface Person {
    /** Cle stable, derivee du nom normalise. Sert d'identifiant d'URL. */
    key: string;
    /**
     * Identifiant serveur — present UNIQUEMENT si l'utilisateur a decide
     * quelque chose de cette personne (aujourd'hui : lui donner une photo).
     * Une personne sans decision n'existe qu'ici, le temps du rendu.
     */
    id?: string;
    /** Photo choisie a la main. Absente : on retombe sur le monogramme. */
    photoUrl?: string | null;
    /** Nom affiche : la variante la plus frequente, telle qu'imprimee. */
    name: string;
    /** Toutes les graphies rencontrees, de la plus frequente a la plus rare. */
    variants: Array<{ raw: string; count: number }>;
    documents: Document[];
    /** Repartition par categorie, de la plus fournie a la plus rare. */
    spread: Array<{ category: Category; count: number }>;
}

/**
 * Normalise un destinataire pour le rapprochement.
 *
 * Un document imprime le nom comme il veut : « M. Jean Dupont », « DUPONT
 * Jean », « jean dupont ». On retire la civilite, les accents et la casse, puis
 * on trie les mots — l'ordre nom/prenom varie d'un emetteur a l'autre et ne
 * doit pas separer deux graphies de la meme personne.
 */
export function normalizeRecipient(raw: string): string {
    return raw
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[.,;:()"']/g, ' ')
        .replace(/\b(m|mr|mme|mlle|dr|me|pr|monsieur|madame|mademoiselle|maitre|docteur)\b/g, ' ')
        .split(/\s+/)
        .filter((word) => word.length > 1)
        .sort()
        .join(' ')
        .trim();
}

/**
 * Regroupe une liste de documents par destinataire.
 *
 * Regroupement cote client, sur la page de documents deja chargee. C'est une
 * premiere version assumee : il n'existe pas d'entite « personne » cote
 * serveur, `recipient` est une colonne de texte libre sans index trigramme
 * (contrairement a `issuer`). Tant que le volume tient dans une page, le
 * resultat est exact ; au-dela il faudra la table `people` et un
 * `GET /api/people`.
 *
 * Aucune fusion n'est decidee ici au-dela de la normalisation stricte :
 * rapprocher deux graphies proches mais distinctes (« M. Slama » / « Mme
 * Slama ») serait faux ET invisible. C'est a l'utilisateur de trancher.
 */
export function groupByRecipient(documents: Document[]): {
    people: Person[];
    unassigned: Document[];
} {
    interface Bucket {
        raws: Map<string, number>;
        documents: Document[];
    }

    const buckets = new Map<string, Bucket>();
    const unassigned: Document[] = [];

    for (const document of documents) {
        const raw = document.recipient?.trim();

        if (!raw) {
            unassigned.push(document);
            continue;
        }

        const key = normalizeRecipient(raw);

        if (!key) {
            unassigned.push(document);
            continue;
        }

        const bucket: Bucket = buckets.get(key) ?? { raws: new Map(), documents: [] };
        bucket.raws.set(raw, (bucket.raws.get(raw) ?? 0) + 1);
        bucket.documents.push(document);
        buckets.set(key, bucket);
    }

    const people: Person[] = [...buckets.entries()].map(([key, bucket]) => {
        const variants = [...bucket.raws.entries()]
            .map(([raw, count]) => ({ raw, count }))
            .sort((a, b) => b.count - a.count);

        const spreadMap = new Map<string, { category: Category; count: number }>();

        for (const document of bucket.documents) {
            if (!document.category) continue;
            const entry = spreadMap.get(document.category.slug) ?? {
                category: document.category,
                count: 0,
            };
            entry.count += 1;
            spreadMap.set(document.category.slug, entry);
        }

        return {
            key,
            name: variants[0].raw,
            variants,
            documents: bucket.documents,
            spread: [...spreadMap.values()].sort((a, b) => b.count - a.count),
        };
    });

    people.sort((a, b) => b.documents.length - a.documents.length);

    return { people, unassigned };
}

/**
 * Graphies distinctes d'une meme personne : ce que l'ecran de rattachement
 * proposera de fusionner. Une seule graphie = rien a rattacher.
 */
export function needsAttention(people: Person[]): Person[] {
    return people.filter((person) => person.variants.length > 1);
}
