import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from '../../lib/cn';

export function AuthShell({
    title,
    subtitle,
    children,
    footer,
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div className="app-shell">
            <div className="scroller !pb-8">
                <div className="mx-auto flex min-h-full w-full max-w-sm flex-col justify-center gap-8 px-6 py-10">
                    <header className="app-chrome flex flex-col items-center gap-3 text-center">
                        <img
                            src="/icons/pwa-192x192.png"
                            alt=""
                            width={64}
                            height={64}
                            className="size-16 rounded-lg shadow-sm"
                        />
                        <div>
                            <h1 className="text-[1.75rem] leading-8 font-bold tracking-[-0.02em]">{title}</h1>
                            {subtitle && (
                                <p className="mt-1 text-[0.9375rem] text-fg-2">
                                    {subtitle}
                                </p>
                            )}
                        </div>
                    </header>

                    {children}

                    {footer && (
                        <footer className="text-center text-[0.9375rem] text-fg-2">
                            {footer}
                        </footer>
                    )}
                </div>
            </div>
        </div>
    );
}

export function Field({
    id,
    label,
    error,
    ...props
}: {
    id: string;
    label: string;
    error?: string;
} & InputHTMLAttributes<HTMLInputElement>) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-[0.9375rem] font-medium">
                {label}
            </label>
            <input
                id={id}
                {...props}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className={cn(
                    'w-full rounded-md border bg-surface px-3.5 py-3 text-base outline-none transition-colors',
                    error
                        ? 'border-late-fg focus:border-late-fg'
                        : 'border-edge focus:border-accent',
                )}
            />
            {error && (
                <p id={`${id}-error`} className="text-[0.8125rem] text-late-fg">
                    {error}
                </p>
            )}
        </div>
    );
}

export function SubmitButton({
    children,
    pending,
}: {
    children: ReactNode;
    pending?: boolean;
}) {
    return (
        <button
            type="submit"
            disabled={pending}
            className="pressable flex h-13 w-full items-center justify-center rounded-md bg-accent px-4 font-semibold text-on-accent disabled:opacity-50"
        >
            {pending ? 'Un instant…' : children}
        </button>
    );
}

export function FormError({ message }: { message?: string | null }) {
    if (!message) return null;
    return (
        <p
            role="alert"
            className="rounded-md bg-late-bg px-3.5 py-3 text-[0.9375rem] text-late-fg"
        >
            {message}
        </p>
    );
}
