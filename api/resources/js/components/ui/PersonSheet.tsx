import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { createPortal } from 'react-dom';

import { ValidationError } from '../../lib/api';
import type { Person } from '../../lib/people';
import { useRemovePersonPhoto, useRenamePerson, useSetPersonPhoto } from '../../lib/personPhotos';

/**
 * Feuille d'actions d'une personne.
 *
 * Elle existe pour une raison technique autant que pour une raison d'usage.
 *
 * Technique : sur iOS, `input.click()` n'ouvre le sélecteur de photos QUE s'il
 * est appelé synchroniquement depuis un vrai geste. Un appui long mesuré par
 * un setTimeout casse la chaîne d'activation — le sélecteur ne s'ouvrait donc
 * jamais sur iPhone, sans la moindre erreur. En passant par une feuille, c'est
 * le tap sur « Changer la photo » qui ouvre le sélecteur : un geste, un clic,
 * la chaîne tient.
 *
 * Usage : un appui long n'a de la place que pour une seule action. Une feuille
 * en accueille plusieurs, et c'est là que le renommage a sa place.
 *
 * Rendue en PORTAIL : dans les listes, l'avatar vit à l'intérieur d'un <Link>,
 * et des boutons imbriqués dans un lien sont invalides — le lien avalerait les
 * clics.
 */
interface PersonSheetProps {
    person: Person;
    open: boolean;
    onClose: () => void;
}

export function PersonSheet({ person, open, onClose }: PersonSheetProps) {
    const [mode, setMode] = useState<'actions' | 'rename'>('actions');
    const [draft, setDraft] = useState(person.name);
    const [error, setError] = useState<string | null>(null);

    const input = useRef<HTMLInputElement>(null);
    const field = useRef<HTMLInputElement>(null);

    const setPhoto = useSetPersonPhoto();
    const removePhoto = useRemovePersonPhoto();
    const rename = useRenamePerson();

    const busy = setPhoto.isPending || removePhoto.isPending || rename.isPending;

    // Rouvrir la feuille repart toujours de la liste d'actions, avec le nom
    // courant : garder un brouillon d'une session précédente serait un piège.
    useEffect(() => {
        if (!open) return;

        setMode('actions');
        setDraft(person.name);
        setError(null);
    }, [open, person.name]);

    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    useEffect(() => {
        if (mode === 'rename') field.current?.focus();
    }, [mode]);

    if (!open) return null;

    const fail = (cause: unknown, fallback: string) => {
        // Un échec muet est pire que pas de fonctionnalité : l'utilisateur
        // recommence indéfiniment sans savoir ce qui cloche.
        if (cause instanceof ValidationError) {
            /*
             | Les messages par CHAMP, jamais le résumé. Laravel renvoie dans
             | `message` la première erreur suivie de « (and 2 more errors) » :
             | ça dit qu'il y a un problème, pas lequel ni où.
             */
            const messages = Object.values(cause.errors).flat();

            setError(messages.length > 0 ? messages.join(' ') : cause.message);

            return;
        }

        setError(cause instanceof Error ? cause.message : fallback);
    };

    const onPick = (event: ChangeEvent<HTMLInputElement>) => {
        const field = event.target;
        const file = field.files?.[0];

        if (!file) return;

        setError(null);
        setPhoto.mutate(
            { person, file },
            {
                onSuccess: onClose,
                onError: (cause) => fail(cause, "La photo n'a pas pu être envoyée."),
                /*
                 | La remise à zéro attend que la requête soit retombée. La
                 | faire avant, comme au premier jet, revient à couper la
                 | référence au fichier pendant qu'on est encore en train de le
                 | lire. Elle reste nécessaire : sans elle, rechoisir le MÊME
                 | fichier n'émet aucun change et le geste paraît ignoré.
                 */
                onSettled: () => {
                    field.value = '';
                },
            },
        );
    };

    const onRemove = () => {
        if (!person.id) return;

        setError(null);
        removePhoto.mutate(person.id, {
            onSuccess: onClose,
            onError: (cause) => fail(cause, "La photo n'a pas pu être supprimée."),
        });
    };

    const onRename = (event: FormEvent) => {
        event.preventDefault();

        const name = draft.trim();

        if (name === '') {
            setError('Le nom ne peut pas être vide.');

            return;
        }

        setError(null);
        rename.mutate(
            { person, name },
            {
                onSuccess: onClose,
                onError: (cause) => fail(cause, "Le nom n'a pas pu être enregistré."),
            },
        );
    };

    const row =
        'flex h-13 w-full items-center justify-center rounded-md bg-surface-2 text-[1.0625rem] font-medium disabled:opacity-40';

    return createPortal(
        <div className="fixed inset-0 z-50 flex flex-col justify-end" role="presentation">
            <button
                type="button"
                aria-label="Fermer"
                onClick={onClose}
                className="absolute inset-0 bg-fg/40"
            />

            <div
                role="dialog"
                aria-modal="true"
                aria-label={person.name}
                className="land relative mx-2 flex flex-col gap-2 rounded-lg bg-surface p-2"
                style={{ marginBottom: 'calc(0.5rem + var(--safe-b))' }}
            >
                <p className="px-3 pt-2 pb-1 text-center text-[0.8125rem] text-fg-3">{person.name}</p>

                {error && (
                    <p role="alert" className="rounded-md bg-late-bg px-3.5 py-2.5 text-[0.875rem] text-late-fg">
                        {error}
                    </p>
                )}

                {mode === 'actions' ? (
                    <>
                        {/*
                          Ce bouton est le cœur du correctif : c'est SON clic,
                          un vrai geste, qui ouvre le sélecteur. Déclenché
                          depuis le minuteur de l'appui long, iOS ne faisait
                          rien du tout.
                        */}
                        <button type="button" onClick={() => input.current?.click()} disabled={busy} className={row}>
                            {person.photoUrl ? 'Changer la photo' : 'Ajouter une photo'}
                        </button>

                        {person.photoUrl && person.id && (
                            <button
                                type="button"
                                onClick={onRemove}
                                disabled={busy}
                                className={`${row} text-late-fg`}
                            >
                                Supprimer la photo
                            </button>
                        )}

                        <button type="button" onClick={() => setMode('rename')} disabled={busy} className={row}>
                            Modifier le nom
                        </button>

                        <button type="button" onClick={onClose} className={`${row} bg-surface font-semibold text-accent`}>
                            Annuler
                        </button>
                    </>
                ) : (
                    <form onSubmit={onRename} className="flex flex-col gap-2">
                        <label htmlFor="person-name" className="sr-only">
                            Nom de la personne
                        </label>
                        <input
                            ref={field}
                            id="person-name"
                            type="text"
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            autoComplete="off"
                            autoCapitalize="words"
                            enterKeyHint="done"
                            className="h-13 w-full rounded-md border border-edge bg-surface px-3.5 text-base outline-none focus:border-accent"
                        />

                        <p className="px-1 text-[0.8125rem] leading-[1.125rem] text-fg-3">
                            Ce nom remplace celui lu sur les documents. Il ne changera plus tout seul.
                        </p>

                        <button
                            type="submit"
                            disabled={busy}
                            className={`${row} bg-accent font-semibold text-on-accent`}
                        >
                            {rename.isPending ? 'Enregistrement…' : 'Enregistrer'}
                        </button>

                        <button
                            type="button"
                            onClick={() => setMode('actions')}
                            className={`${row} bg-surface font-semibold text-accent`}
                        >
                            Retour
                        </button>
                    </form>
                )}

                <input
                    ref={input}
                    type="file"
                    accept="image/jpeg,image/png"
                    className="sr-only"
                    onChange={onPick}
                    tabIndex={-1}
                />
            </div>
        </div>,
        document.body,
    );
}

export default PersonSheet;
