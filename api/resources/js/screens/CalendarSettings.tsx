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
                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    Chaque echeance detectee dans vos documents devient un evenement avec rappel, dans un
                    calendrier dedie. Rien n’est ecrit ailleurs dans votre compte.
                </p>
            </header>

            {message && (
                <p className="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-950/50 dark:text-sky-300">
                    {message}
                </p>
            )}

            {current && (
                <section className="rounded-xl border border-neutral-200 p-3 dark:border-neutral-800">
                    <div className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium">{current.apple_id}</p>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                {current.status_label}
                                {current.calendar_name && ` · ${current.calendar_name}`}
                            </p>
                        </div>
                        <span
                            className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                                current.is_ready
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                    : current.invalid_credentials
                                      ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300'
                                      : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                            }`}
                        >
                            {current.is_ready ? 'Actif' : current.invalid_credentials ? 'A reconnecter' : 'En attente'}
                        </span>
                    </div>

                    {current.invalid_credentials && (
                        <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950/50 dark:text-red-300">
                            Apple a refuse la connexion. C’est normal si vous avez change le mot de passe
                            principal de votre compte Apple : cela <strong>revoque automatiquement tous</strong> les
                            mots de passe d’application. Generez-en un nouveau et reconnectez-vous ci-dessous.
                        </p>
                    )}

                    {current.last_error && !current.invalid_credentials && (
                        <p className="mt-2 text-xs text-red-600 dark:text-red-400">{current.last_error}</p>
                    )}

                    <div className="mt-3 flex gap-2">
                        <button
                            type="button"
                            onClick={() => resync.mutate()}
                            disabled={resync.isPending || !current.is_ready}
                            className="flex-1 rounded-lg border border-neutral-300 px-3 py-2 text-sm disabled:opacity-40 dark:border-neutral-700"
                        >
                            {resync.isPending ? 'Synchronisation…' : 'Resynchroniser'}
                        </button>
                        <button
                            type="button"
                            onClick={() => disconnect.mutate()}
                            disabled={disconnect.isPending}
                            className="flex-1 rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 disabled:opacity-40 dark:border-red-900 dark:text-red-400"
                        >
                            Deconnecter
                        </button>
                    </div>
                </section>
            )}

            <section className="rounded-xl bg-neutral-50 p-4 dark:bg-neutral-900">
                <h2 className="text-sm font-semibold">Obtenir un mot de passe d’application</h2>
                <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                    C’est gratuit. Ce n’est pas le mot de passe de votre compte Apple : c’est un code dedie,
                    revocable a tout moment, qui ne donne acces qu’au calendrier.
                </p>
                <ol className="mt-2 flex list-decimal flex-col gap-1.5 pl-4 text-xs text-neutral-700 dark:text-neutral-300">
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
                <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
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
                        className="rounded-xl border border-neutral-300 px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-900"
                        required
                    />
                    {errors.apple_id?.map((error) => (
                        <span key={error} className="text-xs text-red-600 dark:text-red-400">
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
                        className="rounded-xl border border-neutral-300 px-3 py-2.5 font-mono dark:border-neutral-700 dark:bg-neutral-900"
                        required
                    />
                    {errors.app_password?.map((error) => (
                        <span key={error} className="text-xs text-red-600 dark:text-red-400">
                            {error}
                        </span>
                    ))}
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        Stocke chiffre sur le serveur. Il n’est jamais reaffiche ni renvoye au navigateur.
                    </span>
                </label>

                <button
                    type="submit"
                    disabled={connect.isPending}
                    className="rounded-xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
                >
                    {connect.isPending ? 'Connexion a iCloud…' : 'Connecter'}
                </button>
            </form>
        </div>
    );
}
