import { lazy, Suspense, type ComponentType, type ReactElement } from 'react';

/**
 * Resolution paresseuse des ecrans.
 *
 * Les ecrans metier (Documents, Scanner, Todos, Reglages...) sont livres par
 * d'autres lots et n'existent pas forcement encore. Un `import()` statique
 * ferait echouer le build tant qu'un fichier manque ; `import.meta.glob` est
 * resolu a la compilation sur ce qui EXISTE, et on retombe sur un ecran
 * « a venir » pour le reste. La coquille se construit et se deploie donc
 * independamment des autres lots.
 */
const modules = import.meta.glob([
    '../screens/*.tsx',
    // Login/Register sont importes statiquement par app.tsx (premier ecran
    // possible au demarrage) : les exclure evite un chunk dynamique mort.
    '!../screens/Login.tsx',
    '!../screens/Register.tsx',
]) as Record<string, () => Promise<{ default: ComponentType }>>;

function ScreenFallback() {
    return (
        <div className="flex h-full items-center justify-center p-8" aria-busy="true">
            <span className="sr-only">Chargement</span>
            <span className="size-6 animate-spin rounded-full border-2 border-neutral-300 border-t-brand-600 dark:border-neutral-700 dark:border-t-brand-400" />
        </div>
    );
}

function MissingScreen({ title, name }: { title: string; name: string }) {
    return (
        <div className="mx-auto flex max-w-md flex-col items-center gap-3 px-6 py-16 text-center">
            <h1 className="text-lg font-semibold">{title}</h1>
            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                Cet ecran n’est pas encore livre.
            </p>
            <code className="rounded bg-neutral-100 px-2 py-1 font-mono text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                resources/js/screens/{name}.tsx
            </code>
        </div>
    );
}

/**
 * @param name  nom du fichier attendu dans resources/js/screens (sans extension),
 *              exportant le composant par defaut.
 * @param title libelle affiche tant que l'ecran n'existe pas.
 */
export function screen(name: string, title: string): ReactElement {
    const loader = modules[`../screens/${name}.tsx`];

    if (!loader) {
        return <MissingScreen title={title} name={name} />;
    }

    const Lazy = lazy(loader);

    return (
        <Suspense fallback={<ScreenFallback />}>
            <Lazy />
        </Suspense>
    );
}
