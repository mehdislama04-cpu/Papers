import { useState } from 'react';

import { cn } from '../../lib/cn';
import type { Person } from '../../lib/people';
import { useLongPress } from '../../lib/useLongPress';
import { Monogram } from './Chips';
import { PersonSheet } from './PersonSheet';

type Size = 'sm' | 'md' | 'lg';

/** Memes gabarits que Monogram : les deux doivent etre interchangeables. */
const BOX: Record<Size, string> = {
    sm: 'size-10',
    md: 'size-[2.875rem]',
    lg: 'size-[4.75rem]',
};

interface PersonAvatarProps {
    person: Person;
    size?: Size;
    /**
     * `span` dans une liste : l'avatar vit a l'interieur d'un <Link>, on ne
     * peut pas y imbriquer un bouton sans casser les deux. Seul l'appui long
     * ouvre alors la feuille d'actions.
     *
     * `button` sur la fiche de la personne, ou l'avatar est libre : un appui
     * simple suffit, le geste devient atteignable au clavier et annonce par
     * VoiceOver. C'est la contrepartie indispensable d'un geste invisible.
     */
    as?: 'span' | 'button';
}

/**
 * Avatar d'une personne : sa photo, ou son monogramme.
 *
 * L'avatar n'ouvre PAS le selecteur de photos lui-meme, il ouvre une feuille
 * d'actions. Ce detour n'est pas cosmetique : sur iOS, `input.click()` n'ouvre
 * rien s'il n'est pas appele depuis un vrai geste, et le minuteur de l'appui
 * long casse justement cette chaine. C'est le tap dans la feuille qui ouvre le
 * selecteur (cf. PersonSheet).
 */
export function PersonAvatar({ person, size = 'md', as = 'span' }: PersonAvatarProps) {
    const [open, setOpen] = useState(false);

    const press = useLongPress(() => setOpen(true));

    const face = person.photoUrl ? (
        <img
            src={person.photoUrl}
            alt=""
            loading="lazy"
            className={cn('shrink-0 rounded-full bg-surface-2 object-cover', BOX[size])}
        />
    ) : (
        <Monogram name={person.name} size={size} />
    );

    // `-webkit-touch-callout: none` est obligatoire : sans lui, Safari ouvre
    // son apercu de lien sur appui long et le geste n'arrive jamais.
    const shell = 'relative inline-flex shrink-0 [-webkit-touch-callout:none] select-none';

    const sheet = <PersonSheet person={person} open={open} onClose={() => setOpen(false)} />;

    if (as === 'button') {
        return (
            <>
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    aria-label={`Modifier ${person.name}`}
                    aria-haspopup="dialog"
                    className={cn(shell, 'pressable rounded-full')}
                >
                    {face}
                </button>
                {sheet}
            </>
        );
    }

    return (
        <>
            <span className={shell} {...press}>
                {face}
            </span>
            {sheet}
        </>
    );
}

export default PersonAvatar;
