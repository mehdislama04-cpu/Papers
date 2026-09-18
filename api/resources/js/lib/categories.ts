import type { Category } from './types';

/**
 * Presentation des categories.
 *
 * Le serveur sert deja un `color` et un `icon` par categorie. Les couleurs en
 * base sont des valeurs Tailwind v3 brutes, dont la luminosite va de 0,55 a
 * 0,78 : certaines pastilles crient, d'autres s'effacent. Trois tombent en plus
 * exactement sur les couleurs d'etat de l'app — Sante sur le rouge du retard,
 * Banque sur le vert du « fait », Scolaire sur l'ambre de l'echeance proche —
 * ce qui rendrait l'urgence illisible.
 *
 * On reharmonise donc ici, a luminosite constante (L ~ 0,60) et chroma contenu,
 * avec Sante / Banque / Scolaire decalees en teinte. C'est une couche de
 * PRESENTATION : une categorie personnelle creee par l'utilisateur, absente de
 * cette table, garde la couleur servie par l'API.
 *
 * A terme ces valeurs ont leur place en base (une migration sur `color`), ce
 * qui permettra de supprimer cette table.
 */
const SYSTEM_COLORS: Record<string, string> = {
    facture: 'oklch(0.655 0.170 50)',
    contrat: 'oklch(0.580 0.155 285)',
    sante: 'oklch(0.610 0.175 10)',
    impots: 'oklch(0.600 0.105 212)',
    banque: 'oklch(0.605 0.120 178)',
    assurance: 'oklch(0.585 0.170 305)',
    administratif: 'oklch(0.575 0.035 258)',
    scolaire: 'oklch(0.680 0.145 90)',
    immobilier: 'oklch(0.635 0.120 195)',
    vehicule: 'oklch(0.600 0.140 238)',
    emploi: 'oklch(0.605 0.180 325)',
    autre: 'oklch(0.620 0.014 258)',
};

/** Repli quand ni la table ci-dessus ni l'API ne donnent de couleur. */
const FALLBACK_COLOR = 'oklch(0.620 0.014 258)';

export function categoryColor(category: Pick<Category, 'slug' | 'color'> | null | undefined): string {
    if (!category) return FALLBACK_COLOR;
    return SYSTEM_COLORS[category.slug] ?? category.color ?? FALLBACK_COLOR;
}

/**
 * Traces des icones, indexes par le nom servi dans `Category.icon` (jeu Lucide).
 * Dessinees a la main plutot qu'importees : douze traces pesent moins qu'une
 * dependance d'icones entiere, et le scanner charge deja 10,8 Mo d'OpenCV.
 */
const ICON_PATHS: Record<string, string> = {
    receipt: 'M4 3v18l2.5-1.6L9 21l2.5-1.6L14 21l2.5-1.6L19 21V3l-2.5 1.6L14 3l-2.5 1.6L9 3 6.5 4.6 4 3ZM8 9h8M8 13h5',
    'file-signature': 'M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h5M14 3v5h5V8m-4.5 11.5 5-5a1.6 1.6 0 0 1 2.3 2.3l-5 5-3 .7.7-3Z',
    'heart-pulse': 'M20.8 6.6a5 5 0 0 0-8.8-1.9 5 5 0 0 0-8.8 1.9c-.6 2.4.8 4.4 2.3 5.9L12 19l6.5-6.5c1.5-1.5 2.9-3.5 2.3-5.9Z',
    landmark: 'M3 10h18L12 4 3 10ZM5 10v8M10 10v8M14 10v8M19 10v8M3 20h18',
    banknote: 'M2 6h20v12H2zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z',
    'shield-check': 'M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3Zm-3 9 2 2 4-4',
    'building-2': 'M4 3h16v18H4zM8 7h3M8 11h3M8 15h3M14 7h2M14 11h2M14 15h2',
    'graduation-cap': 'M22 9 12 4 2 9l10 5 10-5ZM6 11.5V16c0 1.3 2.7 2.5 6 2.5s6-1.2 6-2.5v-4.5',
    home: 'm3 10 9-7 9 7M5 9v11h14V9M10 20v-6h4v6',
    car: 'M5 17h14v-4l-1.8-4.2A2 2 0 0 0 15.4 7.5H8.6a2 2 0 0 0-1.8 1.3L5 13v4Zm3 0a1.6 1.6 0 1 0 0-.1M16 17a1.6 1.6 0 1 0 0-.1',
    briefcase: 'M2.5 7h19v13h-19zM9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2M2.5 12.5h19',
    folder: 'M3 7.5A1.5 1.5 0 0 1 4.5 6h4l2 2.5h9A1.5 1.5 0 0 1 21 10v8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18V7.5Z',
};

export function categoryIconPath(icon: string | null | undefined): string {
    return (icon && ICON_PATHS[icon]) || ICON_PATHS.folder;
}

/**
 * Initiales d'un nom de personne, pour le monogramme.
 * « M. Jean Dupont » -> « JD ». Un seul mot -> ses deux premieres lettres.
 */
export function initialsOf(name: string): string {
    const words = name
        .replace(/^(m\.|mme|mlle|mr|dr|me|pr)\s+/i, '')
        .split(/[\s-]+/)
        .filter((word) => word.length > 0);

    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}
