import type { ReactNode } from 'react';
import { cn } from '../../lib/cn';
import { categoryColor, categoryIconPath, initialsOf } from '../../lib/categories';
import type { Category } from '../../lib/types';

/**
 * Pastille de categorie : un CARRE arrondi, toujours.
 *
 * C'est la forme qui porte le registre. Les categories portent des couleurs
 * vives dont trois tombent sur les couleurs d'etat de l'app (Sante sur le rouge
 * du retard, Banque sur le vert du « fait », Scolaire sur l'ambre de l'echeance
 * proche). Une pastille carree ne se confond pas avec une pilule d'etat, meme
 * a couleur egale et du coin de l'oeil — ce que deux pilules feraient.
 */
export function CategoryChip({
    category,
    size = 'sm',
}: {
    category: Pick<Category, 'slug' | 'name' | 'color' | 'icon'>;
    size?: 'sm' | 'md' | 'lg';
}) {
    const box = size === 'lg' ? 'size-16 rounded-[1.25rem]' : size === 'md' ? 'size-11 rounded-[0.875rem]' : 'size-[1.875rem] rounded-[0.5625rem]';
    const glyph = size === 'lg' ? 'size-8' : size === 'md' ? 'size-6' : 'size-[1.0625rem]';

    return (
        <span
            className={cn('inline-flex shrink-0 items-center justify-center text-white', box)}
            style={{ backgroundColor: categoryColor(category) }}
            aria-hidden="true"
        >
            <svg
                viewBox="0 0 24 24"
                className={glyph}
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d={categoryIconPath(category.icon)} />
            </svg>
        </span>
    );
}

type PillTone = 'neutral' | 'late' | 'soon' | 'done' | 'working';

/**
 * Etiquette d'etat : une PILULE, toujours. Couleurs de signal uniquement.
 *
 * Les tons de signal sont des APLATS pleins, pas des teintes pales : c'est ce
 * qui les fait exister a 12 px au milieu d'une ligne chargee. L'encre posee
 * dessus vient de `on-<ton>` et n'est pas negociable — le blanc echoue sur le
 * jaune et sur le vert, l'encre echoue sur le violet. Le ton neutre, lui, reste
 * sourd : il porte un montant ou un compte, pas un etat.
 */
export function Pill({
    tone = 'neutral',
    children,
}: {
    tone?: PillTone;
    children: ReactNode;
}) {
    const tones: Record<PillTone, string> = {
        neutral: 'bg-surface-2 text-fg-2',
        late: 'bg-late text-on-late',
        soon: 'bg-soon text-on-soon',
        done: 'bg-done text-on-done',
        working: 'bg-accent text-on-accent',
    };

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-[3px] text-xs leading-4 font-semibold',
                tones[tone],
            )}
        >
            {tone === 'working' && (
                <span className="pulse-dot size-1.5 rounded-full bg-current" aria-hidden="true" />
            )}
            {children}
        </span>
    );
}

/**
 * Monogramme d'une personne. Pas de photo : on n'en a aucune, et en inventer
 * une (couleur tiree du nom, illustration) ferait passer une deduction pour
 * une information.
 */
export function Monogram({
    name,
    self,
    size = 'md',
}: {
    name: string;
    self?: boolean;
    size?: 'sm' | 'md' | 'lg';
}) {
    const box =
        size === 'lg'
            ? 'size-[4.75rem] text-[1.6875rem]'
            : size === 'sm'
              ? 'size-10 text-[0.9375rem]'
              : 'size-[2.875rem] text-[1.0625rem]';

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
                self ? 'bg-accent text-on-accent' : 'bg-accent-bg text-accent',
                box,
            )}
            aria-hidden="true"
        >
            {initialsOf(name)}
        </span>
    );
}

/** Chevron « on peut entrer ici ». */
export function Chevron() {
    return (
        <svg
            viewBox="0 0 8 14"
            className="size-3.5 shrink-0 text-paper-400"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m1 1 6 6-6 6" />
        </svg>
    );
}
