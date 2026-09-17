import { Outlet } from 'react-router';
import { StatusBanner } from './StatusBanner';
import { TabBar } from './TabBar';

/**
 * Coquille de l'app : contenu scrollable + barre d'onglets fixe.
 *
 * `.app-shell` reprend la safe-area haute (encoche de l'iPhone 14 Plus) et
 * `.scroller` reserve en bas la hauteur de la barre d'onglets plus les 34 pt
 * du home indicator.
 */
export function AppLayout() {
    return (
        <div className="app-shell">
            <StatusBanner />
            <main className="scroller">
                <Outlet />
            </main>
            <TabBar />
        </div>
    );
}

export default AppLayout;
