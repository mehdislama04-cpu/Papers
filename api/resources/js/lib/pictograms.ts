/**
 * Pictogrammes des categories et des personnes.
 *
 * Des IMAGES, pas des traces SVG : ce sont des illustrations en relief, avec
 * degrades et reflets, qu'un <path> monochrome ne rend pas. Servies en WebP a
 * 192 px, soit 64 pt en @3x — le plus grand affichage prevu. Environ 4 ko
 * piece, donc moins qu'une police d'icones pour dix dessins.
 *
 * Sept categories sur douze en ont un. Les cinq autres — sante, administratif,
 * scolaire, immobilier, autre — gardent leur glyphe au trait dans une pastille
 * teintee. Un jeu incomplet assume vaut mieux qu'un pictogramme approximatif
 * pris pour un autre : un camion de livraison pose sur « Sante » serait lu
 * comme une information, pas comme un remplissage.
 */
const CATEGORY_PICTOGRAMS: Record<string, string> = {
    facture: '/pictograms/facture.webp',
    contrat: '/pictograms/contrat.webp',
    banque: '/pictograms/banque.webp',
    assurance: '/pictograms/assurance.webp',
    impots: '/pictograms/impots.webp',
    emploi: '/pictograms/emploi.webp',
    vehicule: '/pictograms/vehicule.webp',
};

/** Une personne seule. */
export const PICTOGRAM_PERSON = '/pictograms/personne.webp';

/** Plusieurs personnes — sert aux regroupements de graphies. */
export const PICTOGRAM_PEOPLE = '/pictograms/personnes.webp';

/** Un foyer. Sert aux documents sans destinataire, qui concernent tout le monde. */
export const PICTOGRAM_FAMILY = '/pictograms/famille.webp';

export function categoryPictogram(slug: string | null | undefined): string | null {
    if (!slug) return null;

    return CATEGORY_PICTOGRAMS[slug] ?? null;
}
