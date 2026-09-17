import type { ReactNode } from 'react';
import { NavLink } from 'react-router';
import { cn } from '../../lib/cn';

interface Tab {
    to: string;
    label: string;
    icon: (active: boolean) => ReactNode;
    end?: boolean;
}

const stroke = {
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.6,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
};

const TABS: Tab[] = [
    {
        to: '/',
        label: 'Documents',
        end: true,
        icon: () => (
            <svg viewBox="0 0 24 24" className="size-6" aria-hidden="true" {...stroke}>
                <path d="M6 3h7l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                <path d="M13 3v5h5" />
                <path d="M8.5 13h7M8.5 16.5h4.5" />
            </svg>
        ),
    },
    {
        to: '/scan',
        label: 'Scanner',
        icon: () => (
            <svg viewBox="0 0 24 24" className="size-6" aria-hidden="true" {...stroke}>
                <path d="M3 8V5.5A1.5 1.5 0 0 1 4.5 4H7" />
                <path d="M21 8V5.5A1.5 1.5 0 0 0 19.5 4H17" />
                <path d="M3 16v2.5A1.5 1.5 0 0 0 4.5 20H7" />
                <path d="M21 16v2.5a1.5 1.5 0 0 1-1.5 1.5H17" />
                <circle cx="12" cy="12" r="3.25" />
            </svg>
        ),
    },
    {
        to: '/todos',
        label: 'Taches',
        icon: () => (
            <svg viewBox="0 0 24 24" className="size-6" aria-hidden="true" {...stroke}>
                <path d="m4 7 2 2 3.5-3.5" />
                <path d="m4 17 2 2 3.5-3.5" />
                <path d="M13 7.5h7M13 17.5h7" />
            </svg>
        ),
    },
    {
        to: '/settings',
        label: 'Reglages',
        icon: () => (
            <svg viewBox="0 0 24 24" className="size-6" aria-hidden="true" {...stroke}>
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 14.5a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.11a1.7 1.7 0 0 0-1.11-1.56 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.11a1.7 1.7 0 0 0 1.56-1.11 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34h.08A1.7 1.7 0 0 0 10.14 3.1V3a2 2 0 1 1 4 0v.11a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87v.08a1.7 1.7 0 0 0 1.56 1.03H21a2 2 0 1 1 0 4h-.11a1.7 1.7 0 0 0-1.49 1.03Z" />
            </svg>
        ),
    },
];

/**
 * Barre d'onglets iOS. Le padding bas reprend `env(safe-area-inset-bottom)`
 * (34 pt de home indicator sur iPhone 14 Plus) via la classe `.tabbar`.
 * Chaque cible fait au moins 44x44 pt (HIG).
 */
export function TabBar() {
    return (
        <nav
            className="tabbar app-chrome border-t border-neutral-200/80 bg-white/85 backdrop-blur-xl dark:border-neutral-800/80 dark:bg-neutral-950/85"
            aria-label="Navigation principale"
        >
            <ul className="grid h-tabbar grid-cols-4">
                {TABS.map((tab) => (
                    <li key={tab.to} className="contents">
                        <NavLink
                            to={tab.to}
                            end={tab.end}
                            className={({ isActive }) =>
                                cn(
                                    'tap-target flex flex-col items-center justify-center gap-0.5 text-[0.625rem] font-medium transition-colors',
                                    isActive
                                        ? 'text-brand-600 dark:text-brand-400'
                                        : 'text-neutral-500 dark:text-neutral-400',
                                )
                            }
                        >
                            {({ isActive }) => (
                                <>
                                    {tab.icon(isActive)}
                                    <span>{tab.label}</span>
                                </>
                            )}
                        </NavLink>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

export default TabBar;
