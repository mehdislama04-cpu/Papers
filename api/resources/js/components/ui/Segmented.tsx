import { cn } from '../../lib/cn';

interface SegmentedProps<T extends string> {
    value: T;
    onChange: (value: T) => void;
    options: Array<{ value: T; label: string }>;
    label: string;
}

/**
 * Selecteur segmente iOS.
 *
 * Le conteneur fait 36 px comme sur iOS, mais la zone tactile de chaque
 * segment est portee a 44 px par un pseudo-element deborde : on garde le
 * gabarit natif sans descendre sous la cible minimale des HIG.
 */
export function Segmented<T extends string>({ value, onChange, options, label }: SegmentedProps<T>) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className="mb-3.5 grid h-9 gap-0.5 rounded-[0.5625rem] bg-surface-2 p-0.5"
            style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'relative flex items-center justify-center rounded-[0.4375rem] text-sm transition-colors',
                            // Cible tactile de 44 px, sans changer la hauteur visible.
                            'before:absolute before:inset-x-0 before:-inset-y-1 before:content-[""]',
                            active
                                ? 'bg-surface font-semibold text-fg shadow-[0_1px_3px_oklch(0.2_0.01_258/0.13)]'
                                : 'font-medium text-fg-2',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

export default Segmented;
