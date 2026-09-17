<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Enums\CalendarAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCalendarAccountRequest;
use App\Http\Resources\CalendarAccountResource;
use App\Models\CalendarAccount;
use App\Services\CalDav\CalDavClient;
use App\Services\CalDav\CalDavException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connexion du compte iCloud CalDAV.
 *
 * RÈGLE ABSOLUE : le mot de passe d'application ne sort jamais d'ici. Il n'est
 * ni journalisé, ni renvoyé, ni inclus dans un message d'erreur — d'où
 * CalDavException::redact() sur tout ce qui remonte du client, Guzzle recopiant
 * les en-têtes de la requête (dont Authorization) dans ses exceptions.
 */
class CalendarAccountController extends Controller
{
    /**
     * GET /api/calendar/account
     *
     * { "data": null } quand aucun compte n'est connecté : le front n'a pas à
     * traiter un 404 comme un cas nominal.
     */
    public function show(Request $request): JsonResponse
    {
        $account = $request->user()->calendarAccount;

        if ($account === null) {
            return response()->json(['data' => null]);
        }

        $this->authorize('view', $account);

        return CalendarAccountResource::make($account)->response();
    }

    /**
     * POST /api/calendar/account — { apple_id, app_password }
     *
     * La découverte est lancée IMMÉDIATEMENT, dans la requête : c'est le seul
     * moment où l'utilisateur regarde son écran et peut corriger un mot de
     * passe mal recopié. Un 401 iCloud est un état terminal, il faut le dire
     * tout de suite, pas trois minutes plus tard dans une notification.
     *
     * Coût : trois PROPFIND plus, éventuellement, un MKCALENDAR — quelques
     * centaines de millisecondes, bornées par le timeout du client (30 s de
     * lecture, 10 s de connexion).
     */
    public function store(StoreCalendarAccountRequest $request, CalDavClient $client): JsonResponse
    {
        $user = $request->user();

        /** @var CalendarAccount|null $existing */
        $existing = $user->calendarAccount;

        if ($existing !== null) {
            $this->authorize('update', $existing);
        } else {
            $this->authorize('create', CalendarAccount::class);
        }

        $account = $existing ?? new CalendarAccount;
        $account->user()->associate($user);

        // Reconnexion : on repart d'une découverte vierge. Le DSID et la
        // partition pNN sont propres au compte Apple ; les garder après un
        // changement d'identifiant enverrait les requêtes sur la mauvaise
        // partition.
        $account->forceFill([
            'apple_id' => $request->validated('apple_id'),
            // Cast `encrypted` : la valeur est chiffrée avec APP_KEY avant
            // d'atteindre PostgreSQL. Perdre APP_KEY = perdre définitivement
            // ces mots de passe (d'où APP_PREVIOUS_KEYS pour la rotation).
            'app_password' => $request->validated('app_password'),
            'dsid' => null,
            'principal_url' => null,
            'calendar_home_url' => null,
            'papers_calendar_url' => null,
            'status' => CalendarAccountStatus::Pending->value,
            'last_error' => null,
        ])->save();

        try {
            $client->discover($account);
            $calendarUrl = $client->ensurePapersCalendar($account);

            $account->forceFill([
                'papers_calendar_url' => $calendarUrl,
                'status' => CalendarAccountStatus::Connected->value,
                'last_error' => null,
                'last_sync_at' => now(),
            ])->save();
        } catch (CalDavException $e) {
            return $this->failed($account, CalDavException::redact($e->getMessage()), $e->isTerminal());
        } catch (Throwable $e) {
            // Tout le reste (réseau, XML inattendu, écriture en base) : on ne
            // laisse PAS fuiter le message brut, il peut contenir l'en-tête
            // Authorization recopié par Guzzle.
            return $this->failed($account, CalDavException::redact($e->getMessage()), false);
        }

        return CalendarAccountResource::make($account->refresh())->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/calendar/account
     *
     * Déconnecte le compte et efface le mot de passe d'application chiffré.
     * Les CalendarEvent locaux tombent en cascade.
     *
     * Ce qui a DÉJÀ été poussé dans iCloud n'est pas rappelé : supprimer à la
     * volée des dizaines d'événements sur un compte qu'on est en train de
     * déconnecter est le meilleur moyen de se faire limiter par Apple au pire
     * moment. Le calendrier « Papers — Échéances » reste dans l'app
     * Calendrier, l'utilisateur le supprime d'un geste s'il le souhaite.
     */
    public function destroy(Request $request): Response
    {
        $account = $request->user()->calendarAccount;

        abort_if($account === null, 404, "Aucun compte iCloud n'est connecté.");

        $this->authorize('delete', $account);

        $account->delete();

        return response()->noContent();
    }

    /**
     * Échec de connexion : on persiste l'état, on renvoie la ressource (le
     * front affiche `status` + `last_error`) avec un code qui distingue le
     * refus d'identifiants du simple incident réseau.
     */
    private function failed(CalendarAccount $account, string $message, bool $terminal): JsonResponse
    {
        $account->forceFill([
            'status' => $terminal
                ? CalendarAccountStatus::InvalidCredentials->value
                : CalendarAccountStatus::Pending->value,
            'last_error' => $message,
        ])->save();

        // Journalisation SANS le mot de passe : on n'écrit que l'identifiant
        // du compte, jamais le corps de la requête CalDAV.
        Log::warning('Connexion iCloud échouée', [
            'calendar_account_id' => $account->getKey(),
            'terminal' => $terminal,
            'error' => $message,
        ]);

        return CalendarAccountResource::make($account)
            ->additional(['message' => $message])
            ->response()
            // 422 : l'utilisateur doit corriger sa saisie (mot de passe
            // d'application révoqué ou mal recopié).
            // 502 : iCloud n'a pas répondu comme prévu, réessayer plus tard.
            ->setStatusCode($terminal ? 422 : 502);
    }
}
