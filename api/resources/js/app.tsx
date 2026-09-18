import { StrictMode, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router';
import { QueryClientProvider } from '@tanstack/react-query';

import { AuthProvider } from './lib/auth';
import { queryClient } from './lib/queryClient';
import { installQueueTriggers } from './lib/queue';
import { registerServiceWorker, type PwaHandle } from './lib/pwa';
import { screen } from './lib/screens';
import { installViewportFix } from './lib/viewport';

import { AppLayout } from './components/layout/AppLayout';
import { RequireAuth, RequireGuest } from './components/layout/RequireAuth';
import { UpdatePrompt } from './components/layout/UpdatePrompt';
import Login from './screens/Login';
import Register from './screens/Register';

/**
 * Routage par History API — JAMAIS par hash.
 *
 * Sur iOS, la permission camera d'une PWA installee est revoquee a chaque
 * changement de hash d'URL (WebKit 215884). Un routeur a hash rendrait le
 * scanner inutilisable des la deuxieme navigation.
 */
const router = createBrowserRouter([
    {
        element: <RequireGuest />,
        children: [
            { path: '/login', element: <Login /> },
            { path: '/register', element: <Register /> },
        ],
    },
    {
        element: <RequireAuth />,
        children: [
            {
                element: <AppLayout />,
                children: [
                    { index: true, element: screen('Documents', 'Documents') },
                    { path: 'documents/:document', element: screen('DocumentDetail', 'Document') },
                    { path: 'categories/:slug', element: screen('CategoryDocuments', 'Categorie') },
                    // Statique AVANT dynamique : sans cela « groups » serait lu
                    // comme une cle de personne.
                    { path: 'people/groups', element: screen('PeopleGroups', 'Regroupements') },
                    { path: 'people/:person', element: screen('PersonDocuments', 'Personne') },
                    { path: 'scan', element: screen('Scanner', 'Scanner') },
                    { path: 'todos', element: screen('Todos', 'Taches') },
                    { path: 'settings', element: screen('Settings', 'Reglages') },
                    { path: 'settings/calendar', element: screen('CalendarSettings', 'Calendrier') },
                    { path: 'settings/shortcut', element: screen('ShortcutOnboarding', 'Raccourci iOS') },
                ],
            },
        ],
    },
    { path: '*', element: <Navigate to="/" replace /> },
]);

function Root() {
    const [updateReady, setUpdateReady] = useState(false);
    const pwa = useRef<PwaHandle | null>(null);

    useEffect(() => {
        const teardownViewport = installViewportFix();
        const teardownQueue = installQueueTriggers();

        pwa.current = registerServiceWorker({
            onUpdateAvailable: () => setUpdateReady(true),
        });

        // Ouverture depuis une notification : le SW demande la navigation.
        const onMessage = (event: MessageEvent) => {
            const data = event.data as { type?: string; url?: string } | undefined;
            if (data?.type === 'NAVIGATE' && data.url) {
                void router.navigate(data.url);
            }
        };
        navigator.serviceWorker?.addEventListener('message', onMessage);

        return () => {
            navigator.serviceWorker?.removeEventListener('message', onMessage);
            pwa.current?.unregisterTriggers();
            teardownQueue();
            teardownViewport();
        };
    }, []);

    return (
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                <RouterProvider router={router} />
                <UpdatePrompt
                    open={updateReady}
                    onApply={() => pwa.current?.applyUpdate()}
                    onDismiss={() => setUpdateReady(false)}
                />
            </AuthProvider>
        </QueryClientProvider>
    );
}

const container = document.getElementById('app');

if (!container) {
    throw new Error('Element #app introuvable : verifiez resources/views/app.blade.php.');
}

/**
 * La racine est memorisee sur le conteneur.
 *
 * En dev, React Refresh re-execute ce module a chaque modification. Sans ce
 * garde, `createRoot` serait appele une seconde fois sur le meme `#app` : deux
 * racines se disputent alors les memes noeuds et le rendu casse sur un
 * « removeChild : the node to be removed is not a child of this node ».
 * En production le module n'est evalue qu'une fois et le garde ne coute rien.
 */
type RootContainer = HTMLElement & { _papersRoot?: ReturnType<typeof createRoot> };

const host = container as RootContainer;
host._papersRoot ??= createRoot(host);

host._papersRoot.render(
    <StrictMode>
        <Root />
    </StrictMode>,
);
