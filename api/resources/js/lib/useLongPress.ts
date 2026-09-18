import { useCallback, useEffect, useRef, type PointerEvent as ReactPointerEvent } from 'react';

/**
 * Appui long, sur un element qui vit DANS un lien.
 *
 * Trois pieges, et ce sont eux qui justifient un hook plutot qu'un setTimeout
 * pose a la main :
 *
 *  1. Le defilement. Un doigt qui part en scroll passe par pointerdown : sans
 *     seuil de deplacement, faire defiler la liste des personnes declencherait
 *     le geste. On annule des que le doigt bouge de plus de quelques pixels.
 *
 *  2. Le clic qui suit. Sur iOS, lever le doigt apres un appui long emet quand
 *     meme un click, qui remonterait au <Link> parent et naviguerait. On le
 *     tue en phase de CAPTURE, avant qu'il n'atteigne l'ancetre.
 *
 *  3. Le menu contextuel. Safari ouvre son apercu de lien sur appui long. Il
 *     faut a la fois preventDefault sur contextmenu et `-webkit-touch-callout:
 *     none` en CSS cote appelant : l'un sans l'autre ne suffit pas.
 */

/** 500 ms : le seuil d'iOS lui-meme. Plus court, on declenche sur un tap lent. */
const DELAY_MS = 500;

/** Au-dela, le doigt fait defiler, il n'appuie pas. */
const MOVE_TOLERANCE_PX = 10;

export interface LongPressHandlers {
    onPointerDown: (event: ReactPointerEvent<HTMLElement>) => void;
    onPointerMove: (event: ReactPointerEvent<HTMLElement>) => void;
    onPointerUp: () => void;
    onPointerCancel: () => void;
    onClickCapture: (event: { preventDefault: () => void; stopPropagation: () => void }) => void;
    onContextMenu: (event: { preventDefault: () => void }) => void;
}

export function useLongPress(onLongPress: () => void, enabled = true): LongPressHandlers {
    const timer = useRef<number | null>(null);
    const origin = useRef<{ x: number; y: number } | null>(null);
    const fired = useRef(false);
    const callback = useRef(onLongPress);

    // La closure passee au setTimeout ne doit pas figer une version perimee du
    // callback : on la relit au moment ou le minuteur tombe.
    callback.current = onLongPress;

    const clear = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
        origin.current = null;
    }, []);

    useEffect(() => clear, [clear]);

    const onPointerDown = useCallback(
        (event: ReactPointerEvent<HTMLElement>) => {
            if (!enabled) return;

            fired.current = false;
            origin.current = { x: event.clientX, y: event.clientY };

            timer.current = window.setTimeout(() => {
                timer.current = null;
                fired.current = true;
                callback.current();
            }, DELAY_MS);
        },
        [enabled],
    );

    const onPointerMove = useCallback(
        (event: ReactPointerEvent<HTMLElement>) => {
            if (timer.current === null || origin.current === null) return;

            const dx = event.clientX - origin.current.x;
            const dy = event.clientY - origin.current.y;

            if (Math.hypot(dx, dy) > MOVE_TOLERANCE_PX) clear();
        },
        [clear],
    );

    const onClickCapture = useCallback(
        (event: { preventDefault: () => void; stopPropagation: () => void }) => {
            if (!fired.current) return;

            event.preventDefault();
            event.stopPropagation();
            fired.current = false;
        },
        [],
    );

    const onContextMenu = useCallback(
        (event: { preventDefault: () => void }) => {
            if (enabled) event.preventDefault();
        },
        [enabled],
    );

    return {
        onPointerDown,
        onPointerMove,
        onPointerUp: clear,
        onPointerCancel: clear,
        onClickCapture,
        onContextMenu,
    };
}

export default useLongPress;
