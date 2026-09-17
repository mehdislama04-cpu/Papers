interface UpdatePromptProps {
    open: boolean;
    onApply: () => void;
    onDismiss: () => void;
}

/**
 * Prompt de mise a jour du service worker.
 *
 * `registerType: 'prompt'` : on ne recharge jamais sous les pieds de
 * l'utilisateur, il peut etre en train de scanner. Le bandeau flotte
 * au-dessus de la barre d'onglets, safe-area comprise.
 */
export function UpdatePrompt({ open, onApply, onDismiss }: UpdatePromptProps) {
    if (!open) return null;

    return (
        <div
            className="app-chrome fixed inset-x-0 z-50 px-4"
            style={{ bottom: 'calc(var(--tabbar-h) + var(--safe-b) + 0.75rem)' }}
            role="alertdialog"
            aria-label="Mise a jour disponible"
        >
            <div className="mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-neutral-900 px-4 py-3 text-white shadow-lg dark:bg-neutral-100 dark:text-neutral-900">
                <p className="flex-1 text-sm">Une nouvelle version est disponible.</p>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="tap-target px-2 text-sm text-neutral-400 dark:text-neutral-500"
                >
                    Plus tard
                </button>
                <button
                    type="button"
                    onClick={onApply}
                    className="tap-target rounded-full bg-brand-500 px-4 text-sm font-semibold text-white"
                >
                    Mettre a jour
                </button>
            </div>
        </div>
    );
}

export default UpdatePrompt;
