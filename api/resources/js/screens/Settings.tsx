import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQuery } from '@tanstack/react-query';

import { api, type Envelope } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useOnline, useOutbox, useStandalone } from '../lib/hooks';
import { clearQueue } from '../lib/queue';
import type { CalendarAccount } from '../lib/types';

function Item({
    to,
    title,
    subtitle,
    tone,
}: {
    to: string;
    title: string;
    subtitle: string;
    tone?: 'warning' | 'ok';
}) {
    return (
        <Link
            to={to}
            className="flex items-center justify-between gap-3 rounded-xl border border-neutral-200 p-3 active:bg-neutral-50 dark:border-neutral-800 dark:active:bg-neutral-900"
        >
            <div className="min-w-0">
                <p className="text-sm font-medium">{title}</p>
                <p
                    className={`truncate text-xs ${
                        tone === 'warning'
                            ? 'text-amber-700 dark:text-amber-400'
                            : tone === 'ok'
                              ? 'text-emerald-700 dark:text-emerald-400'
                              : 'text-neutral-500 dark:text-neutral-400'
                    }`}
                >
                    {subtitle}
                </p>
            </div>
            <span aria-hidden="true" className="shrink-0 text-neutral-400">
                ›
            </span>
        </Link>
    );
}

export default function Settings() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const online = useOnline();
    const standalone = useStandalone();
    const outbox = useOutbox();
    const [cleared, setCleared] = useState(false);

    const account = useQuery({
        queryKey: ['calendar-account'],
        queryFn: () => api.get<Envelope<CalendarAccount | null>>('/calendar/account'),
    });

    const calendar = account.data?.data ?? null;

    const calendarSubtitle = !calendar
        ? 'Non connecte — les echeances resteront dans l’app'
        : calendar.invalid_credentials
          ? 'A reconnecter : Apple refuse le mot de passe'
          : calendar.is_ready
            ? `Connecte — ${calendar.apple_id}`
            : 'Connexion en cours de verification';

    return (
        <div className="flex flex-col gap-5 px-4 pb-8 pt-2">
            <header>
                <h1 className="text-xl font-semibold">Reglages</h1>
            </header>

            {user && (
                <section className="rounded-xl border border-neutral-200 p-3 dark:border-neutral-800">
                    <p className="text-sm font-medium">{user.name}</p>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{user.email}</p>
                </section>
            )}

            <section className="flex flex-col gap-2">
                <Item
                    to="/settings/calendar"
                    title="Calendrier iCloud"
                    subtitle={calendarSubtitle}
                    tone={calendar?.invalid_credentials ? 'warning' : calendar?.is_ready ? 'ok' : undefined}
                />
                <Item
                    to="/settings/shortcut"
                    title="Scanner natif Apple"
                    subtitle="Passer par l’app Raccourcis plutot que le scanner integre"
                />
            </section>

            <section className="rounded-xl bg-neutral-50 p-3 dark:bg-neutral-900">
                <h2 className="text-sm font-semibold">Etat</h2>
                <dl className="mt-1.5 flex flex-col gap-1 text-xs">
                    <div className="flex justify-between">
                        <dt className="text-neutral-500 dark:text-neutral-400">Reseau</dt>
                        <dd className={online ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600'}>
                            {online ? 'En ligne' : 'Hors ligne'}
                        </dd>
                    </div>
                    <div className="flex justify-between">
                        <dt className="text-neutral-500 dark:text-neutral-400">Installee sur l’ecran d’accueil</dt>
                        <dd>{standalone ? 'Oui' : 'Non'}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt className="text-neutral-500 dark:text-neutral-400">Documents en attente d’envoi</dt>
                        <dd>{outbox.length}</dd>
                    </div>
                </dl>

                {!standalone && (
                    <p className="mt-2 text-xs text-neutral-600 dark:text-neutral-400">
                        Pour installer : bouton Partager de Safari, puis « Sur l’ecran d’accueil ». Sans cela, les
                        notifications et le retour depuis Raccourcis ne fonctionnent pas.
                    </p>
                )}
            </section>

            <section className="flex flex-col gap-2">
                <button
                    type="button"
                    onClick={async () => {
                        await clearQueue();
                        setCleared(true);
                    }}
                    disabled={outbox.length === 0}
                    className="rounded-xl border border-neutral-300 px-4 py-3 text-sm disabled:opacity-40 dark:border-neutral-700"
                >
                    {cleared ? 'File videe' : `Vider la file d’envoi (${outbox.length})`}
                </button>
                {outbox.length > 0 && (
                    <p className="-mt-1 text-xs text-amber-700 dark:text-amber-400">
                        Attention : les documents non envoyes seront definitivement perdus.
                    </p>
                )}

                <button
                    type="button"
                    onClick={async () => {
                        await logout();
                        void navigate('/login', { replace: true });
                    }}
                    className="rounded-xl border border-red-300 px-4 py-3 text-sm font-medium text-red-600 dark:border-red-900 dark:text-red-400"
                >
                    Se deconnecter
                </button>
            </section>
        </div>
    );
}
