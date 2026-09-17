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
                            className="size-16 rounded-2xl shadow-sm"
                        />
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                            {subtitle && (
                                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                    {subtitle}
                                </p>
                            )}
                        </div>
                    </header>

                    {children}

                    {footer && (
                        <footer className="text-center text-sm text-neutral-500 dark:text-neutral-400">
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
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <input
                id={id}
                {...props}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className={cn(
                    'w-full rounded-xl border bg-white px-3.5 py-3 text-base outline-none transition',
                    'dark:bg-neutral-900',
                    error
                        ? 'border-red-400 focus:border-red-500'
                        : 'border-neutral-300 focus:border-brand-500 dark:border-neutral-700',
                )}
            />
            {error && (
                <p id={`${id}-error`} className="text-sm text-red-600 dark:text-red-400">
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
            className="tap-target w-full rounded-xl bg-brand-600 px-4 py-3 text-base font-semibold text-white transition active:bg-brand-700 disabled:opacity-60"
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
            className="rounded-xl bg-red-50 px-3.5 py-3 text-sm text-red-700 dark:bg-red-950/60 dark:text-red-300"
        >
            {message}
        </p>
    );
}
