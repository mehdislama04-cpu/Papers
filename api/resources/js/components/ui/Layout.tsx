import type { ReactNode } from 'react';
import { cn } from '../../lib/cn';

/**
 * Liste groupee facon iOS : UN conteneur arrondi, des separateurs internes.
 *
 * Remplace le « une bordure par element » qui donnait a l'app son rendu
 * filaire — une ligne de 1 px autour de chaque carte, soit trois fois plus de
 * bruit visuel pour la meme information.
 */
export function Group({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-md bg-surface shadow-[0_1px_2px_oklch(0.2_0.01_258/0.05)]',
                '[&>*:not(:last-child)]:border-b [&>*:not(:last-child)]:border-hairline',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** Titre de section, au-dessus d'un Group. */
export function SectionTitle({
    children,
    tone,
}: {
    children: ReactNode;
    tone?: 'late' | 'default';
}) {
    return (
        <h2
            className={cn(
                'mt-5.5 mb-2 ml-0.5 text-[0.8125rem] leading-[1.125rem] font-semibold tracking-[0.05em] uppercase',
                tone === 'late' ? 'text-late-fg' : 'text-fg-3',
            )}
        >
            {children}
        </h2>
    );
}

/** Ligne libelle / valeur d'une fiche. */
export function Row({ label, value }: { label: string; value: ReactNode }) {
    if (value === null || value === undefined || value === '') return null;

    return (
        <div className="flex items-center gap-4 px-3.5 py-3">
            <dt className="shrink-0 text-[0.9375rem] text-fg-2">{label}</dt>
            <dd className="ml-auto text-right font-medium">{value}</dd>
        </div>
    );
}

/**
 * Etat vide. Il invite, il ne s'excuse pas : un titre qui dit ce qui manque,
 * une phrase qui dit ce que l'app fera, et le geste a portee de pouce.
 */
export function EmptyState({
    icon,
    title,
    children,
    action,
}: {
    icon?: ReactNode;
    title: string;
    children?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-1.5 px-8 py-16 text-center">
            {icon && (
                <div className="mb-3 flex size-27 items-center justify-center rounded-[1.625rem] bg-accent-bg text-accent">
                    {icon}
                </div>
            )}
            <h2 className="text-xl leading-[1.625rem] font-semibold tracking-[-0.01em]">{title}</h2>
            {children && (
                <p className="mb-4 text-[0.9375rem] leading-[1.3125rem] text-fg-2">{children}</p>
            )}
            {action}
        </div>
    );
}

/** Bouton d'action principal. 52 px de haut. */
export function Button({
    children,
    onClick,
    type = 'button',
    variant = 'primary',
    disabled,
    className,
}: {
    children: ReactNode;
    onClick?: () => void;
    type?: 'button' | 'submit';
    variant?: 'primary' | 'ghost' | 'danger';
    disabled?: boolean;
    className?: string;
}) {
    const variants = {
        primary: 'bg-accent text-on-accent',
        ghost: 'bg-surface text-accent shadow-[inset_0_0_0_1px_var(--color-edge)]',
        danger: 'bg-surface text-late-fg shadow-[inset_0_0_0_1px_var(--color-edge)]',
    };

    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'pressable flex h-13 w-full items-center justify-center rounded-md font-semibold disabled:opacity-50',
                variants[variant],
                className,
            )}
        >
            {children}
        </button>
    );
}

/**
 * Squelette de chargement. Prend la forme de ce qui arrive, pour que rien ne
 * saute quand le contenu se pose.
 */
export function Skeleton({ className }: { className?: string }) {
    return <div className={cn('rounded-sm bg-surface-2', className)} aria-hidden="true" />;
}

/** Liste de documents en cours de chargement. */
export function DocumentSkeletons({ count = 4 }: { count?: number }) {
    return (
        <Group>
            {Array.from({ length: count }, (_, index) => (
                <div key={index} className="flex items-center gap-3.5 px-3.5 py-3">
                    <Skeleton className="h-[3.5625rem] w-11 shrink-0 rounded-[0.4375rem]" />
                    <div className="flex-1 space-y-2">
                        <Skeleton className="h-3.5 w-3/5 rounded-full" />
                        <Skeleton className="h-3 w-2/5 rounded-full" />
                    </div>
                </div>
            ))}
        </Group>
    );
}

/** Message d'erreur de chargement. */
export function ErrorNote({ children }: { children: ReactNode }) {
    return (
        <p role="alert" className="rounded-md bg-late-bg px-3.5 py-3 text-[0.9375rem] text-late-fg">
            {children}
        </p>
    );
}
