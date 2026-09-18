import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQuery } from '@tanstack/react-query';

import { api, type Envelope } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useOnline, useOutbox, useStandalone } from '../lib/hooks';
import { clearQueue } from '../lib/queue';
import type { CalendarAccount } from '../lib/types';

import { NavBar } from '../components/ui/NavBar';
import { Chevron, Monogram } from '../components/ui/Chips';
import { Button, Group, SectionTitle } from '../components/ui/Layout';

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
            className="pressable flex min-h-14 items-center justify-between gap-3 px-3.5 py-3 active:bg-surface-2"
        >
            <div className="min-w-0">
                <p className="font-medium">{title}</p>
                <p
                    className={`truncate text-[0.8125rem] ${
                        tone === 'warning'
                            ? 'text-soon-fg'
                            : tone === 'ok'
                              ? 'text-done-fg'
                              : 'text-fg-3'
                    }`}
                >
                    {subtitle}
                </p>
            </div>
            <Chevron />
        </Link>
    );
}

function StateRow({ label, value, tone }: { label: string; value: string; tone?: 'ok' | 'warning' }) {
    return (
        <div className="flex items-center justify-between gap-4 px-3.5 py-2.5">
            <dt className="text-[0.9375rem] text-fg-2">{label}</dt>
            <dd
                className={`text-[0.9375rem] font-medium ${
                    tone === 'ok' ? 'text-done-fg' : tone === 'warning' ? 'text-soon-fg' : ''
                }`}
            >
                {value}
            </dd>
        </div>
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
        <div className="px-4 pb-8">
            <NavBar title="Reglages" />

            {user && (
                <Group className="mb-1">
                    <div className="flex items-center gap-3.5 px-3.5 py-3.5">
                        <Monogram name={user.name} self />
                        <div className="min-w-0">
                            <p className="truncate font-semibold">{user.name}</p>
                            <p className="truncate text-[0.8125rem] text-fg-3">{user.email}</p>
                        </div>
                    </div>
                </Group>
            )}

            <SectionTitle>Connexions</SectionTitle>
            <Group>
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
            </Group>

            <SectionTitle>Etat</SectionTitle>
            <Group>
                <dl className="contents">
                    <StateRow
                        label="Reseau"
                        value={online ? 'En ligne' : 'Hors ligne'}
                        tone={online ? 'ok' : 'warning'}
                    />
                    <StateRow
                        label="Installee sur l’ecran d’accueil"
                        value={standalone ? 'Oui' : 'Non'}
                        tone={standalone ? 'ok' : undefined}
                    />
                    <StateRow label="Documents en attente d’envoi" value={String(outbox.length)} />
                </dl>
            </Group>

            {!standalone && (
                <p className="mt-2.5 px-1 text-[0.8125rem] leading-[1.125rem] text-fg-2">
                    Pour installer : bouton Partager de Safari, puis « Sur l’ecran d’accueil ». Sans cela,
                    les notifications et le retour depuis Raccourcis ne fonctionnent pas.
                </p>
            )}

            <div className="mt-7 flex flex-col gap-2.5">
                <Button
                    variant="ghost"
                    onClick={async () => {
                        await clearQueue();
                        setCleared(true);
                    }}
                    disabled={outbox.length === 0}
                >
                    {cleared ? 'File videe' : `Vider la file d’envoi (${outbox.length})`}
                </Button>

                {outbox.length > 0 && (
                    <p className="-mt-1 px-1 text-[0.8125rem] text-soon-fg">
                        Les documents non envoyes seront definitivement perdus.
                    </p>
                )}

                <Button
                    variant="danger"
                    onClick={async () => {
                        await logout();
                        void navigate('/login', { replace: true });
                    }}
                >
                    Se deconnecter
                </Button>
            </div>
        </div>
    );
}
