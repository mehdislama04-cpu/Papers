/**
 * Filet de securite pour la hauteur du viewport.
 *
 * 100dvh existe depuis iOS 15.4 mais a un bug de cold-start connu en mode
 * standalone : la valeur est fausse au tout premier paint. On pose donc une
 * variable --vh-px mesuree en JS, qui prend le relais via :root[data-vh-px].
 */
export function installViewportFix(): () => void {
    const apply = () => {
        const height = window.visualViewport?.height ?? window.innerHeight;
        if (!height) return;
        document.documentElement.style.setProperty('--vh-px', `${Math.round(height)}px`);
        document.documentElement.dataset.vhPx = '1';
    };

    apply();

    const onResize = () => apply();
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onResize);
    window.visualViewport?.addEventListener('resize', onResize);

    return () => {
        window.removeEventListener('resize', onResize);
        window.removeEventListener('orientationchange', onResize);
        window.visualViewport?.removeEventListener('resize', onResize);
    };
}

/** L'app tourne-t-elle installee sur l'ecran d'accueil ? */
export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}
