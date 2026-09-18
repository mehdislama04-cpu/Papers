import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, ValidationError, type Envelope } from '../lib/api';
import type { CalendarAccount } from '../lib/types';

export default function CalendarSettings() {
    const client = useQueryClient();

    const [appleId, setAppleId] = useState('');
    const [appPassword, setAppPassword] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);

    const account = useQuery({
        queryKey: ['calendar-account'],
        queryFn: () => api.get<Envelope<CalendarAccount | null>>('/calendar/account'),
    });

    const connect = useMutation({
        mutationFn: () =>
            api.post<Envelope<CalendarAccount>>('/calendar/account', {
                apple_id: appleId.trim(),
                app_password: appPassword.trim(),
            }),
        onSuccess: (response) => {
            setAppPassword('');
            setErrors({});
            setMessage(
                response.data.is_ready
                    ? `Connecte. Les echeances iront dans le calendrier « ${response.data.calendar_name ?? 'Papers'} ».`
                    : 'Compte enregistre, la decouverte du calendrier est en cours.',
            );
            void client.invalidateQueries({ queryKey: ['calendar-account'] });
        },
        onError: (error) => {
            if (error instanceof ValidationError) {
                setErrors(error.errors);
                setMessage(null);
            } else {
                setMessage(error instanceof Error ? error.message : 'La connexion a echoue.');
            }
        },
    });

    const disconnect = useMutation({
        mutationFn: () => api.delete('/calendar/account'),
        onSuccess: () => {
            setMessage('Compte deconnecte.');
            void client.invalidateQueries({ queryKey: ['calendar-account'] });
        },
    });

    const resync = useMutation({
        mutationFn: () => api.post('/calendar/resync'),
        onSuccess: () => {
            setMessage('Resynchronisation lancee.');
            void client.invalidateQueries({ queryKey: ['todos'] });
        },
    });

    function onSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!connect.isPending) connect.mutate();
    }

    const current = account.data?.data ?? null;

    return (
        <div className="flex flex-col gap-5 px-4 pb-8 pt-2">
            <header>
                <h1 className="text-xl font-semibold">Calendrier iCloud</h1>
                <p className="mt-1 text-sm text-fg-3">
                    Chaque echeance detectee dans vos documents devient un evenement avec rappel, dans un
                    calendrier dedie. Rien n’est ecrit ailleurs dans votre compte.
                </p>
            </header>

            {message && (
                <p className="rounded-sm bg-accent-bg px-3 py-2 text-sm text-accent">
                    {message}
                </p>
            )}

            {current && (
                <section className="rounded-md border border-edge p-3">
                    <div className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium">{current.apple_id}</p>
                            <p className="text-xs text-fg-3">
                                {current.status_label}
                                {current.calendar_name && ` · ${current.calendar_name}`}
                            </p>
                        </div>
                        <span
                            className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                                current.is_ready
                                    ? 'bg-done-bg text-done-fg'
                                    : current.invalid_credentials
                                      ? 'bg-late-bg text-late-fg'
                                      : 'bg-soon-bg text-soon-fg'
                            }`}
                        >
                            {current.is_ready ? 'Actif' : current.invalid_credentials ? 'A reconnecter' : 'En attente'}
                        </span>
                    </div>

                    {current.invalid_credentials && (
                        <p className="mt-2 rounded-sm bg-late-bg px-3 py-2 text-xs text-late-fg">
                            Apple a refuse la connexion. C’est normal si vous avez change le mot de passe
                            principal de votre compte Apple : cela <strong>revoque automatiquement tous</strong> les
                            mots de passe d’application. Generez-en un nouveau et reconnectez-vous ci-dessous.
                        </p>
                    )}

                    {current.last_error && !current.invalid_credentials && (
                        <p className="mt-2 text-xs text-late-fg">{current.last_error}</p>
                    )}

                    <div className="mt-3 flex gap-2">
                        <button
                            type="button"
                            onClick={() => resync.mutate()}
                            disabled={resync.isPending || !current.is_ready}
                            className="flex-1 rounded-sm border border-edge px-3 py-2 text-sm disabled:opacity-40"
                        >
                            {resync.isPending ? 'Synchronisation…' : 'Resynchroniser'}
                        </button>
                        <button
                            type="button"
                            onClick={() => disconnect.mutate()}
                            disabled={disconnect.isPending}
                            className="flex-1 rounded-sm border border-late-fg px-3 py-2 text-sm text-late-fg disabled:opacity-40"
                        >
                            Deconnecter
                        </button>
                    </div>
                </section>
            )}

            <section className="rounded-md bg-surface-2 p-4">
                <h2 className="text-sm font-semibold">Obtenir un mot de passe d’application</h2>
                <p className="mt-1 text-xs text-fg-2">
                    C’est gratuit. Ce n’est pas le mot de passe de votre compte Apple : c’est un code dedie,
                    revocable a tout moment, qui ne donne acces qu’au calendrier.
                </p>
                <ol className="mt-2 flex list-decimal flex-col gap-1.5 pl-4 text-xs text-fg-2">
                    <li>
                        Votre compte Apple doit avoir l’<strong>authentification a deux facteurs activee</strong>.
                        Sans elle, Apple ne propose pas cette option.
                    </li>
                    <li>
                        Ouvrez <span className="font-mono">account.apple.com</span> et connectez-vous.
                    </li>
                    <li>
                        Allez dans <strong>Connexion et securite</strong>, puis{' '}
                        <strong>Mots de passe pour applications</strong>.
                    </li>
                    <li>
                        Creez-en un, nommez-le « Papers ». Apple affiche alors un code du type{' '}
                        <span className="font-mono">abcd-efgh-ijkl-mnop</span>.
                    </li>
                    <li>Recopiez-le ci-dessous. Il ne sera plus jamais reaffiche par Apple.</li>
                </ol>
                <p className="mt-2 text-xs text-fg-3">
                    Vous pouvez avoir 25 mots de passe d’application au maximum, et le revoquer quand vous voulez
                    depuis la meme page.
                </p>
            </section>

            <form onSubmit={onSubmit} className="flex flex-col gap-3">
                <h2 className="text-sm font-semibold">{current ? 'Reconnecter' : 'Connecter mon calendrier'}</h2>

                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Identifiant Apple</span>
                    <input
                        type="email"
                        inputMode="email"
                        autoComplete="username"
                        value={appleId}
                        onChange={(event) => setAppleId(event.target.value)}
                        placeholder="prenom.nom@icloud.com"
                        className="rounded-md border border-edge px-3 py-2.5"
                        required
                    />
                    {errors.apple_id?.map((error) => (
                        <span key={error} className="text-xs text-late-fg">
                            {error}
                        </span>
                    ))}
                </label>

                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Mot de passe d’application</span>
                    <input
                        type="password"
                        autoComplete="current-password"
                        value={appPassword}
                        onChange={(event) => setAppPassword(event.target.value)}
                        placeholder="abcd-efgh-ijkl-mnop"
                        className="rounded-md border border-edge px-3 py-2.5 font-mono"
                        required
                    />
                    {errors.app_password?.map((error) => (
                        <span key={error} className="text-xs text-late-fg">
                            {error}
                        </span>
                    ))}
                    <span className="text-xs text-fg-3">
                        Stocke chiffre sur le serveur. Il n’est jamais reaffiche ni renvoye au navigateur.
                    </span>
                </label>

                <button
                    type="submit"
                    disabled={connect.isPending}
                    className="rounded-md bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                >
                    {connect.isPending ? 'Connexion a iCloud…' : 'Connecter'}
                </button>
            </form>
        </div>
    );
}
