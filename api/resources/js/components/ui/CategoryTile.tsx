import { Link } from 'react-router';

import { categoryColor, categoryIconPath } from '../../lib/categories';
import { categoryPictogram } from '../../lib/pictograms';
import type { Category } from '../../lib/types';

/**
 * Tuile d'une categorie, dans la grille d'une personne.
 *
 * Deux rendus possibles pour le meme emplacement, et c'est assume : les sept
 * categories qui ont un pictogramme l'affichent tel quel ; les cinq autres
 * retombent sur leur glyphe au trait dans une pastille teintee de leur couleur.
 * Les deux restent un rond colore de meme diametre, donc la grille tient.
 */
export function CategoryTile({
    category,
    count,
    to,
}: {
    category: Pick<Category, 'slug' | 'name' | 'color' | 'icon'>;
    count: number;
    to: string;
}) {
    const pictogram = categoryPictogram(category.slug);
    const color = categoryColor(category);

    return (
        <Link
            to={to}
            className="pressable flex flex-col items-center gap-1.5 rounded-md bg-surface px-2 py-3.5 text-center shadow-[0_1px_2px_oklch(0.2_0.01_258/0.05)]"
        >
            {pictogram ? (
                <img
                    src={pictogram}
                    alt=""
                    width={56}
                    height={56}
                    loading="lazy"
                    className="size-14"
                />
            ) : (
                <span
                    className="flex size-14 items-center justify-center rounded-full"
                    style={{ backgroundColor: `color-mix(in oklab, ${color} 18%, transparent)`, color }}
                    aria-hidden="true"
                >
                    <svg
                        viewBox="0 0 24 24"
                        className="size-7"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={1.9}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d={categoryIconPath(category.icon)} />
                    </svg>
                </span>
            )}

            <span className="mt-0.5 line-clamp-1 font-semibold tracking-[-0.01em]">{category.name}</span>
            <span className="text-[0.8125rem] leading-[1.125rem] text-fg-3">
                {count} document{count > 1 ? 's' : ''}
            </span>
        </Link>
    );
}

export default CategoryTile;
