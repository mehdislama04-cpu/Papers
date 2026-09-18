import { useRef, type ChangeEvent } from 'react';

import { cn } from '../../lib/cn';
import type { Person } from '../../lib/people';
import { useSetPersonPhoto } from '../../lib/personPhotos';
import { useLongPress } from '../../lib/useLongPress';
import { Monogram } from './Chips';

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
     * ouvre alors le selecteur.
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
 * Le choix de la photo passe par un <input type="file"> classique. Pas de
 * `capture` : on veut laisser le choix entre la photothèque et l'appareil
 * photo, et c'est le menu natif d'iOS qui le propose. `accept` n'annonce que
 * JPEG et PNG — jamais HEIC, qui ferait renvoyer du HEIC par Safari 17+
 * (ARCHITECTURE.md §3), soit exactement l'inverse de l'effet recherche.
 */
export function PersonAvatar({ person, size = 'md', as = 'span' }: PersonAvatarProps) {
    const input = useRef<HTMLInputElement>(null);
    const setPhoto = useSetPersonPhoto();

    const openPicker = () => input.current?.click();
    const press = useLongPress(openPicker);

    const onPick = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        // Remis a zero tout de suite : sans ca, rechoisir le MEME fichier
        // n'emet aucun change et le geste parait ignore.
        event.target.value = '';

        if (file) setPhoto.mutate({ person, file });
    };

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

    const busy = setPhoto.isPending;

    const inner = (
        <>
            {face}
            {busy && (
                <span
                    className="absolute inset-0 flex items-center justify-center rounded-full bg-fg/45"
                    aria-hidden="true"
                >
                    <span className="size-4 animate-spin rounded-full border-2 border-surface border-t-transparent" />
                </span>
            )}
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png"
                className="sr-only"
                onChange={onPick}
                tabIndex={-1}
            />
        </>
    );

    // `-webkit-touch-callout: none` est obligatoire : sans lui, Safari ouvre
    // son apercu de lien sur appui long et le geste n'arrive jamais.
    const shell = 'relative inline-flex shrink-0 [-webkit-touch-callout:none] select-none';

    if (as === 'button') {
        return (
            <button
                type="button"
                onClick={openPicker}
                disabled={busy}
                aria-label={
                    person.photoUrl
                        ? `Changer la photo de ${person.name}`
                        : `Ajouter une photo pour ${person.name}`
                }
                className={cn(shell, 'pressable rounded-full')}
            >
                {inner}
            </button>
        );
    }

    return (
        <span className={shell} {...press}>
            {inner}
        </span>
    );
}

export default PersonAvatar;
