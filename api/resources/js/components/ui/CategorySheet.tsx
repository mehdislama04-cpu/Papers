import { useEffect, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';

import { ValidationError } from '../../lib/api';
import { CATEGORY_PALETTE, categoryColor, categoryIconPath, ICON_NAMES } from '../../lib/categories';
import { categoryPictogram } from '../../lib/pictograms';
import { useCategories, useCreateCategory } from '../../lib/useCategories';
import type { Category } from '../../lib/types';

/**
 * Feuille des categories : en choisir une, ou en creer une.
 *
 * Les deux gestes tiennent dans la meme feuille parce qu'ils arrivent au meme
 * moment. On decouvre qu'il manque un rangement PENDANT qu'on cherche ou
 * ranger : renvoyer vers un ecran de reglages a ce moment-la, c'est perdre le
 * document qu'on avait en main.
 */
interface CategorySheetProps {
    open: boolean;
    onClose: () => void;
    /** Ouvre directement sur le formulaire de creation. */
    startInCreate?: boolean;
    /** Slug actuellement retenu, pour le marquer dans la liste. */
    value?: string | null;
    /** Absent : la feuille ne sert qu'a creer. */
    onPick?: (slug: string | null) => void;
    /** Propose « Sans categorie » — un etat legitime, pas une absence. */
    allowNone?: boolean;
}

function Glyph({ category }: { category: Pick<Category, 'slug' | 'color' | 'icon'> }) {
    const pictogram = categoryPictogram(category.slug);
    const color = categoryColor(category);

    if (pictogram) {
        return <img src={pictogram} alt="" width={32} height={32} className="size-8 shrink-0" />;
    }

    return (
        <span
            className="flex size-8 shrink-0 items-center justify-center rounded-full"
            style={{ backgroundColor: `color-mix(in oklab, ${color} 18%, transparent)`, color }}
            aria-hidden="true"
        >
            <svg
                viewBox="0 0 24 24"
                className="size-4.5"
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

export function CategorySheet({
    open,
    onClose,
    startInCreate = false,
    value,
    onPick,
    allowNone = false,
}: CategorySheetProps) {
    const [mode, setMode] = useState<'pick' | 'create'>(startInCreate ? 'create' : 'pick');
    const [name, setName] = useState('');
    const [color, setColor] = useState(CATEGORY_PALETTE[0]);
    const [icon, setIcon] = useState(ICON_NAMES[0]);
    const [error, setError] = useState<string | null>(null);

    const field = useRef<HTMLInputElement>(null);

    const categories = useCategories();
    const create = useCreateCategory();

    useEffect(() => {
        if (!open) return;

        setMode(startInCreate ? 'create' : 'pick');
        setName('');
        setColor(CATEGORY_PALETTE[0]);
        setIcon(ICON_NAMES[0]);
        setError(null);
    }, [open, startInCreate]);

    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    useEffect(() => {
        if (mode === 'create') field.current?.focus();
    }, [mode]);

    if (!open) return null;

    const list = categories.data?.data ?? [];

    const onCreate = (event: FormEvent) => {
        event.preventDefault();

        const label = name.trim();

        if (label === '') {
            setError('Le nom ne peut pas être vide.');

            return;
        }

        setError(null);
        create.mutate(
            { name: label, color, icon },
            {
                onSuccess: (created) => {
                    // Creee depuis un document : on l'y range dans la foulee.
                    // Sinon la creation seule suffit et on ferme.
                    onPick?.(created.data.slug);
                    onClose();
                },
                onError: (cause) => {
                    if (cause instanceof ValidationError) {
                        const messages = Object.values(cause.errors).flat();
                        setError(messages.length > 0 ? messages.join(' ') : cause.message);

                        return;
                    }

                    setError(
                        cause instanceof Error ? cause.message : "La catégorie n'a pas pu être créée.",
                    );
                },
            },
        );
    };

    const row =
        'flex w-full items-center gap-3 rounded-md bg-surface-2 px-3.5 py-3 text-left text-[1.0625rem]';

    return createPortal(
        <div className="fixed inset-0 z-50 flex flex-col justify-end" role="presentation">
            <button type="button" aria-label="Fermer" onClick={onClose} className="absolute inset-0 bg-fg/40" />

            <div
                role="dialog"
                aria-modal="true"
                aria-label={mode === 'create' ? 'Nouvelle catégorie' : 'Choisir une catégorie'}
                className="land relative mx-2 flex max-h-[80vh] flex-col gap-2 overflow-y-auto rounded-lg bg-surface p-2"
                style={{ marginBottom: 'calc(0.5rem + var(--safe-b))' }}
            >
                <p className="px-3 pt-2 pb-1 text-center text-[0.8125rem] text-fg-3">
                    {mode === 'create' ? 'Nouvelle catégorie' : 'Ranger dans…'}
                </p>

                {error && (
                    <p role="alert" className="rounded-md bg-late-bg px-3.5 py-2.5 text-[0.875rem] text-late-fg">
                        {error}
                    </p>
                )}

                {mode === 'pick' ? (
                    <>
                        {allowNone && (
                            <button
                                type="button"
                                onClick={() => {
                                    onPick?.(null);
                                    onClose();
                                }}
                                className={`${row} ${value ? '' : 'font-semibold'}`}
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-surface text-fg-3">
                                    <svg viewBox="0 0 24 24" className="size-4.5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden="true">
                                        <path d="M6 6l12 12M18 6 6 18" />
                                    </svg>
                                </span>
                                Sans catégorie
                            </button>
                        )}

                        {list.map((category) => (
                            <button
                                key={category.id}
                                type="button"
                                onClick={() => {
                                    onPick?.(category.slug);
                                    onClose();
                                }}
                                className={`${row} ${category.slug === value ? 'font-semibold' : ''}`}
                            >
                                <Glyph category={category} />
                                <span className="flex-1 truncate">{category.name}</span>
                                {category.slug === value && (
                                    <svg viewBox="0 0 24 24" className="size-5 shrink-0 text-accent" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <path d="m5 12.5 4.2 4.2L19 7" />
                                    </svg>
                                )}
                            </button>
                        ))}

                        <button
                            type="button"
                            onClick={() => setMode('create')}
                            className={`${row} font-semibold text-accent`}
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent-bg">
                                <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" aria-hidden="true">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                            </span>
                            Nouvelle catégorie
                        </button>

                        <button
                            type="button"
                            onClick={onClose}
                            className="flex h-13 w-full items-center justify-center rounded-md bg-surface font-semibold text-accent"
                        >
                            Annuler
                        </button>
                    </>
                ) : (
                    <form onSubmit={onCreate} className="flex flex-col gap-3 p-1">
                        <label htmlFor="category-name" className="sr-only">
                            Nom de la catégorie
                        </label>
                        <input
                            ref={field}
                            id="category-name"
                            type="text"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Mutuelle, Copropriété, Voyages…"
                            autoComplete="off"
                            autoCapitalize="sentences"
                            enterKeyHint="done"
                            className="h-13 w-full rounded-md border border-edge bg-surface px-3.5 text-base outline-none focus:border-accent"
                        />

                        <div className="flex flex-wrap gap-2">
                            {CATEGORY_PALETTE.map((swatch) => (
                                <button
                                    key={swatch}
                                    type="button"
                                    aria-label={`Couleur ${swatch}`}
                                    aria-pressed={swatch === color}
                                    onClick={() => setColor(swatch)}
                                    className="size-9 rounded-full"
                                    style={{
                                        backgroundColor: swatch,
                                        boxShadow:
                                            swatch === color
                                                ? '0 0 0 2px var(--color-surface), 0 0 0 4px var(--color-fg)'
                                                : undefined,
                                    }}
                                />
                            ))}
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {ICON_NAMES.map((name_) => (
                                <button
                                    key={name_}
                                    type="button"
                                    aria-label={`Icône ${name_}`}
                                    aria-pressed={name_ === icon}
                                    onClick={() => setIcon(name_)}
                                    className="flex size-10 items-center justify-center rounded-md"
                                    style={{
                                        backgroundColor:
                                            name_ === icon
                                                ? `color-mix(in oklab, ${color} 20%, transparent)`
                                                : 'var(--color-surface-2)',
                                        color: name_ === icon ? color : 'var(--color-fg-2)',
                                    }}
                                >
                                    <svg viewBox="0 0 24 24" className="size-5.5" fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <path d={categoryIconPath(name_)} />
                                    </svg>
                                </button>
                            ))}
                        </div>

                        <p className="text-[0.8125rem] leading-[1.125rem] text-fg-3">
                            Le classement automatique ne vise que les catégories d’origine : les
                            documents se rangent ici à la main.
                        </p>

                        <button
                            type="submit"
                            disabled={create.isPending}
                            className="flex h-13 w-full items-center justify-center rounded-md bg-accent font-semibold text-on-accent disabled:opacity-50"
                        >
                            {create.isPending ? 'Création…' : 'Créer'}
                        </button>

                        <button
                            type="button"
                            onClick={() => (startInCreate ? onClose() : setMode('pick'))}
                            className="flex h-13 w-full items-center justify-center rounded-md bg-surface font-semibold text-accent"
                        >
                            {startInCreate ? 'Annuler' : 'Retour'}
                        </button>
                    </form>
                )}
            </div>
        </div>,
        document.body,
    );
}

export default CategorySheet;
