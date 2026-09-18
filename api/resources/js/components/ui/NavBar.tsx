import type { ReactNode } from 'react';
import { useNavigate } from 'react-router';

interface NavBarProps {
    /** Grand titre de l'ecran. Omis sur les ecrans qui affichent un en-tete propre. */
    title?: string;
    /** Libelle du retour arriere. Sa presence affiche le bouton. */
    back?: string;
    /** Destination du retour. Par defaut : l'entree precedente de l'historique. */
    backTo?: string;
    /** Action de droite (bouton texte, 44 px de cible). */
    action?: ReactNode;
    /** Ligne secondaire sous le titre. */
    subtitle?: ReactNode;
    /** Selecteur, recherche… ancre sous le titre, dans la zone collante. */
    children?: ReactNode;
}

/**
 * Barre de navigation iOS.
 *
 * Trois ecrans (document, calendrier, raccourci iOS) n'avaient aucun retour :
 * on y entrait et on ne pouvait ressortir que par la barre d'onglets. C'est ce
 * composant qui le fournit.
 *
 * Le grand titre reste dans le flux et defile ; seule la barre elle-meme est
 * collante, avec un fond translucide. On n'anime pas la reduction du titre :
 * Safari ne donne pas de scroll-driven animation fiable en standalone, et un
 * suivi au scroll en JS coute un reflow par frame sur un ecran qui affiche
 * deja des vignettes.
 */
export function NavBar({ title, back, backTo, action, subtitle, children }: NavBarProps) {
    const navigate = useNavigate();

    return (
        <div className="nav-bar app-chrome">
            {(back || action) && (
                <div className="flex min-h-11 items-center justify-between gap-3">
                    {back ? (
                        <button
                            type="button"
                            onClick={() => (backTo ? navigate(backTo) : navigate(-1))}
                            className="pressable -ml-2 flex min-h-11 items-center gap-1 pr-2 pl-2 text-accent"
                        >
                            <svg
                                viewBox="0 0 12 20"
                                className="h-[19px] w-[11px]"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth={2.4}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M10 2 2 10l8 8" />
                            </svg>
                            <span className="truncate">{back}</span>
                        </button>
                    ) : (
                        <span />
                    )}

                    {action}
                </div>
            )}

            {title && (
                <h1 className="mt-0.5 mb-2.5 text-[2.125rem] leading-[2.5625rem] font-bold tracking-[-0.022em]">
                    {title}
                </h1>
            )}

            {subtitle && <div className="-mt-1.5 mb-3 text-[0.9375rem] text-fg-2">{subtitle}</div>}

            {children}
        </div>
    );
}

/** Bouton texte pour la zone d'action de la barre. 44 px de cible. */
export function NavAction({
    children,
    onClick,
    disabled,
}: {
    children: ReactNode;
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="pressable -mr-2 flex min-h-11 items-center px-2 font-medium text-accent disabled:opacity-40"
        >
            {children}
        </button>
    );
}

export default NavBar;
