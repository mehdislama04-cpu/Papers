import { useCallback, useEffect, useRef, useState } from 'react';

import type { Corner, Quad } from './types';

interface CornerEditorProps {
    /** URL objet de la photo source. */
    src: string;
    /** Dimensions de l'image SOURCE (repere des coins). */
    width: number;
    height: number;
    quad: Quad;
    onChange: (quad: Quad) => void;
}

const HANDLE_LABELS = ['Coin haut gauche', 'Coin haut droit', 'Coin bas droit', 'Coin bas gauche'];

/** Decalage vertical du point de saisie : le doigt ne doit pas masquer le coin. */
const TOUCH_OFFSET = 28;

/**
 * Ajustement manuel des quatre coins.
 *
 * Les poignees font 44 px de cible tactile (minimum recommande par Apple), et
 * le point suivi est decale au-dessus du doigt pour rester visible pendant le
 * glissement.
 */
export function CornerEditor({ src, width, height, quad, onChange }: CornerEditorProps) {
    const frameRef = useRef<HTMLDivElement>(null);
    const [dragging, setDragging] = useState<number | null>(null);

    const toLocal = useCallback(
        (clientX: number, clientY: number): Corner | null => {
            const frame = frameRef.current;
            if (!frame) return null;

            const rect = frame.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return null;

            const x = ((clientX - rect.left) / rect.width) * width;
            const y = ((clientY - rect.top - TOUCH_OFFSET) / rect.height) * height;

            return {
                x: Math.max(0, Math.min(width, x)),
                y: Math.max(0, Math.min(height, y)),
            };
        },
        [width, height],
    );

    useEffect(() => {
        if (dragging === null) return;

        const move = (event: PointerEvent) => {
            event.preventDefault();
            const point = toLocal(event.clientX, event.clientY);
            if (!point) return;

            const next = [...quad] as Quad;
            next[dragging] = point;
            onChange(next);
        };

        const stop = () => setDragging(null);

        // passive: false — sans cela iOS declenche le scroll de la page pendant
        // le glissement et le coin part en vrille.
        window.addEventListener('pointermove', move, { passive: false });
        window.addEventListener('pointerup', stop);
        window.addEventListener('pointercancel', stop);

        return () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', stop);
            window.removeEventListener('pointercancel', stop);
        };
    }, [dragging, quad, onChange, toLocal]);

    const polygon = quad.map((c) => `${c.x},${c.y}`).join(' ');

    return (
        <div
            ref={frameRef}
            className="relative w-full touch-none select-none overflow-hidden rounded-md bg-black"
            style={{ aspectRatio: `${width} / ${height}` }}
        >
            <img src={src} alt="Photo a recadrer" className="h-full w-full object-contain" draggable={false} />

            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="pointer-events-none absolute inset-0 h-full w-full"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <defs>
                    <mask id="papers-quad-mask">
                        <rect x="0" y="0" width={width} height={height} fill="white" />
                        <polygon points={polygon} fill="black" />
                    </mask>
                </defs>

                <rect
                    x="0"
                    y="0"
                    width={width}
                    height={height}
                    fill="rgba(0,0,0,0.55)"
                    mask="url(#papers-quad-mask)"
                />
                <polygon
                    points={polygon}
                    fill="none"
                    stroke="var(--color-capture)"
                    strokeWidth={Math.max(2, width / 250)}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>

            {quad.map((corner, index) => (
                <button
                    key={index}
                    type="button"
                    aria-label={HANDLE_LABELS[index]}
                    onPointerDown={(event) => {
                        event.preventDefault();
                        setDragging(index);
                    }}
                    className="absolute flex size-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full"
                    style={{
                        left: `${(corner.x / width) * 100}%`,
                        top: `calc(${(corner.y / height) * 100}% + ${TOUCH_OFFSET}px)`,
                    }}
                >
                    <span
                        className={`block rounded-full border-2 border-white bg-[var(--color-capture)] shadow-lg transition-all ${
                            dragging === index ? 'size-7' : 'size-5'
                        }`}
                    />
                </button>
            ))}
        </div>
    );
}

export default CornerEditor;
