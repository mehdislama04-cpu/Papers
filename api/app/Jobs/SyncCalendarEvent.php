<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use App\Services\CalDav\CalDavClient;
use App\Services\CalDav\CalDavException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projette un CalendarEvent (donc un Todo) dans iCloud.
 *
 * Le job est entièrement IDEMPOTENT : il recalcule l'état voulu à partir du Todo
 * au moment où il s'exécute, et l'UID déterministe + If-None-Match/If-Match
 * garantissent qu'un double passage ne crée pas de doublon et n'écrase rien.
 * C'est indispensable : la resynchronisation planifiée peut le redispatcher, et
 * un passage heure d'été / heure d'hiver peut faire tourner une tâche planifiée
 * deux fois (ou zéro fois).
 */
class SyncCalendarEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Backoff exponentiel : iCloud throttle sans documenter ses quotas. */
    public array $backoff = [10, 60, 300];

    /**
     * Le CalendarEvent (ou son Todo) a pu être supprimé entre le dispatch et
     * l'exécution : inutile de faire échouer la file pour ça.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * ATTENTION : sous Windows ce timeout n'est PAS appliqué (il repose sur
     * pcntl_alarm, absent). La vraie protection est le timeout Guzzle du
     * CalDavClient (30 s de lecture, 10 s de connexion).
     */
    public int $timeout = 120;

    public function __construct(public CalendarEvent $event)
    {
    }

    /**
     * Une seule requête CalDAV en vol par compte : c'est à la fois la protection
     * contre les écritures concurrentes sur la même ressource .ics et le
     * limiteur de débit (Apple ne publie aucun quota ; les implémentations
     * tierces plafonnent vers 5-10 req/s par compte).
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('caldav:'.((string) $this->event->getAttribute('calendar_account_id'))))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(CalDavClient $client): void
    {
        $event = $this->event;
        $account = CalendarAccount::find($event->getAttribute('calendar_account_id'));

        if ($account === null) {
            return; // compte déconnecté : plus rien à synchroniser
        }

        if ($account->getAttribute('status') === CalDavClient::STATUS_INVALID_CREDENTIALS) {
            // 401 déjà constaté : ne pas repartir en boucle vers Apple, attendre
            // que l'utilisateur ressaisisse un mot de passe d'application.
            $this->markEvent($event, CalDavClient::SYNC_FAILED, 'Compte iCloud en attente de nouveaux identifiants.');

            return;
        }

        try {
            if ($this->shouldRemove($event)) {
                $client->deleteEvent($account, $event);
            } else {
                $client->putEvent($account, $event);
            }

            $account->forceFill([
                'status' => CalDavClient::STATUS_OK,
                'last_ok_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (CalDavException $e) {
            $message = CalDavException::redact($e->getMessage());

            if ($e->isTerminal()) {
                // 401 : état terminal. On bascule le compte et on ne retente pas.
                $account->forceFill([
                    'status' => CalDavClient::STATUS_INVALID_CREDENTIALS,
                    'last_error' => $message,
                ])->save();

                $this->markEvent($event, CalDavClient::SYNC_FAILED, $message);
                $this->fail($e);

                return;
            }

            $account->forceFill([
                'status' => CalDavClient::STATUS_ERROR,
                'last_error' => $message,
            ])->save();

            $this->markEvent($event, CalDavClient::SYNC_FAILED, $message);

            throw $e; // transitoire : retry avec backoff
        }
    }

    /**
     * L'événement doit disparaître d'iCloud si le Todo n'existe plus, s'il a été
     * détaché du calendrier, s'il n'a plus d'échéance, ou s'il est dans un état
     * terminal (fait / annulé). On teste de façon tolérante : le statut peut
     * être une chaîne ou un enum PHP adossé à une chaîne.
     */
    private function shouldRemove(CalendarEvent $event): bool
    {
        if (method_exists($event, 'trashed') && $event->trashed()) {
            return true;
        }

        $todo = $this->todoOf($event);

        if (! $todo instanceof Todo) {
            return true;
        }

        if (method_exists($todo, 'trashed') && $todo->trashed()) {
            return true;
        }

        $due = $todo->getAttribute('due_at');

        if ($due === null || $due === '') {
            return true;
        }

        $status = $todo->getAttribute('status');

        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        return in_array(
            strtolower((string) $status),
            ['done', 'completed', 'cancelled', 'canceled', 'archived', 'dismissed'],
            true,
        );
    }

    private function todoOf(CalendarEvent $event): ?Todo
    {
        $todo = $event->getAttribute('todo');

        if ($todo instanceof Todo) {
            return $todo;
        }

        $todoId = $event->getAttribute('todo_id');

        return $todoId !== null ? Todo::find($todoId) : null;
    }

    private function markEvent(CalendarEvent $event, string $status, ?string $error): void
    {
        $event->forceFill([
            'sync_status' => $status,
            'last_error' => $error,
        ])->save();
    }

    /**
     * Ne JAMAIS laisser fuiter l'en-tête Authorization : Guzzle recopie les
     * en-têtes de la requête dans le message de ses exceptions.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('SyncCalendarEvent', [
            'calendar_event_id' => $this->event->getKey(),
            'error' => $e !== null ? CalDavException::redact($e->getMessage()) : null,
        ]);
    }
}
