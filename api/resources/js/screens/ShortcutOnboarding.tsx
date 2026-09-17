import { useCallback, useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { api, type Envelope } from '../lib/api';
import { useStandalone } from '../lib/hooks';
import type { IngestToken } from '../lib/types';

const SHORTCUT_NAME = 'Papers';

/**
 * Pont vers le scanner natif d'Apple.
 *
 * Aucune API web n'expose VisionKit, et iOS ne supporte pas le Web Share
 * Target : la PWA ne peut donc pas recevoir un scan depuis Notes ou Fichiers.
 * Le seul chemin possible est un raccourci iOS, construit a la main par
 * l'utilisateur — un .shortcut ne peut pas etre genere par programme (la
 * signature AEA est obligatoire depuis iOS 15 et `shortcuts://import-shortcut`
 * n'accepte que des liens iCloud).
 */
export default function ShortcutOnboarding() {
    const standalone = useStandalone();
    const [status, setStatus] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const endpoint = `${window.location.origin}/api/ingest/shortcut`;

    const launch = useMutation({
        mutationFn: () => api.post<Envelope<IngestToken>>('/ingest/token'),
        onSuccess: (response) => {
            setStatus('Ouverture de Raccourcis…');

            // Le jeton part en INPUT du raccourci : jamais de secret code en dur
            // dans le raccourci lui-meme, qui est partage entre utilisateurs.
            window.location.href = response.data.shortcut_url;

            // Si Raccourcis n'est pas installe ou si le raccourci a ete renomme,
            // rien ne se passe et l'echec est SILENCIEUX cote iOS : on ne peut
            // que constater qu'on est toujours la.
            window.setTimeout(() => {
                if (!window.document.hidden) {
                    setStatus(
                        `Raccourcis n’a pas repondu. Verifiez que le raccourci s’appelle exactement « ${SHORTCUT_NAME} », ou utilisez le scanner integre.`,
                    );
                }
            }, 2500);
        },
        onError: (error) => setStatus(error instanceof Error ? error.message : 'Impossible de preparer le jeton.'),
    });

    const copyEndpoint = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(endpoint);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setStatus('Copie impossible : selectionnez l’adresse a la main.');
        }
    }, [endpoint]);

    return (
        <div className="flex flex-col gap-5 px-4 pb-8 pt-2">
            <header>
                <h1 className="text-xl font-semibold">Scanner natif Apple</h1>
                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    Utiliser le scanner de documents d’iOS — celui de Notes et Fichiers — plutot que le scanner
                    integre a l’application.
                </p>
            </header>

            <section className="rounded-xl bg-amber-50 p-3 dark:bg-amber-950/40">
                <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">A lire avant de commencer</h2>
                <ul className="mt-1.5 flex list-disc flex-col gap-1 pl-4 text-xs text-amber-900 dark:text-amber-200">
                    <li>
                        Le scanner d’Apple n’est accessible par <strong>aucune API web</strong>. Ce pont passe par
                        l’app Raccourcis, et demande une installation manuelle unique.
                    </li>
                    <li>
                        L’action officielle « Numeriser un document » <strong>ne renvoie pas le fichier</strong>. Il
                        faut l’app gratuite <strong>Actions</strong> de Sindre Sorhus, qui exige{' '}
                        <strong>iOS 26 ou plus recent</strong>.
                    </li>
                    <li>
                        Si cela vous parait lourd, le <strong>scanner integre</strong> de Papers fait le meme travail
                        sans rien installer.
                    </li>
                </ul>
            </section>

            <section>
                <h2 className="mb-2 text-sm font-semibold">Construire le raccourci</h2>
                <ol className="flex list-decimal flex-col gap-2.5 pl-4 text-sm text-neutral-700 dark:text-neutral-300">
                    <li>
                        Installez l’app <strong>Actions</strong> depuis l’App Store (gratuite).
                    </li>
                    <li>
                        Ouvrez <strong>Raccourcis</strong>, creez un nouveau raccourci et nommez-le exactement{' '}
                        <code className="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs dark:bg-neutral-900">
                            {SHORTCUT_NAME}
                        </code>
                        . Ce nom est son <strong>seul identifiant</strong> : s’il differe, le lancement echouera sans
                        message.
                    </li>
                    <li>
                        Ajoutez l’action <strong>Scan Documents</strong> (fournie par Actions), avec l’option{' '}
                        <strong>Use PDF</strong> activee.
                    </li>
                    <li>
                        Ajoutez <strong>Attendre le retour</strong>. Sans elle, la suite s’execute avant que vous
                        ayez fini de scanner.
                    </li>
                    <li>
                        Ajoutez <strong>Obtenir le presse-papiers</strong> : c’est par la que l’action Scan Documents
                        transmet le fichier.
                    </li>
                    <li>
                        Ajoutez <strong>Obtenir le contenu de l’URL</strong> et reglez-la ainsi :
                        <ul className="mt-1.5 flex list-disc flex-col gap-1 pl-4 text-xs">
                            <li>
                                URL :{' '}
                                <button
                                    type="button"
                                    onClick={copyEndpoint}
                                    className="break-all rounded bg-neutral-100 px-1.5 py-0.5 text-left font-mono text-[11px] dark:bg-neutral-900"
                                >
                                    {endpoint}
                                </button>
                                {copied && <span className="ml-1 text-emerald-600">copie</span>}
                            </li>
                            <li>Methode : POST</li>
                            <li>
                                En-tete : <span className="font-mono">Authorization</span> = «{' '}
                                <span className="font-mono">Bearer</span> » suivi de la variable{' '}
                                <strong>Entree du raccourci</strong>
                            </li>
                            <li>
                                Corps de la requete : <strong>Form</strong>, un champ nomme{' '}
                                <span className="font-mono">file</span> dont vous basculez le type de{' '}
                                <em>Texte</em> a <em>Fichier</em>, avec le presse-papiers comme valeur
                            </li>
                            <li className="text-amber-700 dark:text-amber-300">
                                Ne definissez <strong>pas</strong> de Content-Type a la main : Raccourcis genere
                                lui-meme la limite multipart, et un en-tete manuel casse l’envoi.
                            </li>
                        </ul>
                    </li>
                    <li>
                        Terminez par l’action <strong>Ouvrir l’app</strong> en choisissant Papers dans la liste.
                        C’est le seul moyen de revenir : un lien <span className="font-mono">https</span> rouvrirait
                        Safari, pas l’application installee.
                    </li>
                </ol>

                <p className="mt-3 rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                    Au premier envoi, Raccourcis demandera l’autorisation de contacter ce domaine. Acceptez, sinon
                    rien ne partira. La question n’est posee qu’une fois.
                </p>
            </section>

            <section className="flex flex-col gap-2">
                <h2 className="text-sm font-semibold">Lancer un scan</h2>

                {!standalone && (
                    <p className="rounded-lg bg-neutral-100 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                        Vous n’etes pas dans l’application installee. Ajoutez Papers a l’ecran d’accueil pour que le
                        retour depuis Raccourcis fonctionne.
                    </p>
                )}

                <button
                    type="button"
                    onClick={() => launch.mutate()}
                    disabled={launch.isPending}
                    className="rounded-xl bg-sky-600 px-4 py-3.5 text-sm font-semibold text-white disabled:opacity-50"
                >
                    {launch.isPending ? 'Preparation…' : 'Scanner avec l’app Apple'}
                </button>

                {status && (
                    <p className="rounded-lg bg-neutral-100 px-3 py-2 text-xs text-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        {status}
                    </p>
                )}

                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    Le jeton d’envoi est a usage unique et expire rapidement. Un nouveau est genere a chaque
                    lancement.
                </p>
            </section>
        </div>
    );
}
