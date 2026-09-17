<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Enums\CalendarAccountStatus;
use App\Enums\CalendarSyncStatus;
use App\Enums\TodoStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarEvent;
use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarResyncController extends Controller
{
    /**
     * POST /api/calendar/resync
     *
     * Rattrapage à la demande : crée les miroirs manquants (tâche datée créée
     * pendant une panne iCloud, compte connecté après coup, document
     * ré-analysé) et redispatche tout ce qui n'est pas « synced ».
     *
     * Ne fait AUCUNE requête CalDAV dans le cycle HTTP : tout part en file.
     * SyncCalendarEvent est idempotent (UID déterministe + If-Match /
     * If-None-Match), donc marteler ce bouton n'a aucun effet de bord.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = $user->calendarAccount;

        abort_if($account === null, 404, "Aucun compte iCloud n'est connecté.");

        $this->authorize('update', $account);

        abort_if(
            $this->statusValue($account) === CalendarAccountStatus::InvalidCredentials->value,
            409,
            "Le mot de passe d'application a été refusé par iCloud : reconnectez le compte.",
        );

        $created = $this->createMissingEvents($account);
        $dispatched = $this->dispatchPending($account);

        return response()->json([
            'data' => [
                'created' => $created,
                'dispatched' => $dispatched,
                'status' => $this->statusValue($account),
            ],
        ], 202);
    }

    /**
     * Tâches à pousser qui n'ont pas encore de miroir local.
     */
    private function createMissingEvents(CalendarAccount $account): int
    {
        $userId = (int) $account->getAttribute('user_id');

        $alreadyProjected = CalendarEvent::query()
            ->where('calendar_account_id', $account->getKey())
            ->pluck('todo_id')
            ->all();

        $created = 0;

        Todo::query()
            ->where('user_id', $userId)
            ->whereNotNull('due_at')
            ->where('status', TodoStatus::Pending->value)
            ->when($alreadyProjected !== [], fn ($query) => $query->whereNotIn('id', $alreadyProjected))
            ->chunkById(200, function ($todos) use ($account, &$created): void {
                foreach ($todos as $todo) {
                    $event = new CalendarEvent;
                    $event->forceFill([
                        'todo_id' => $todo->getKey(),
                        'calendar_account_id' => $account->getKey(),
                        'uid' => $todo->calendarUid(),
                        'sync_status' => CalendarSyncStatus::Pending->value,
                    ])->save();

                    $created++;
                }
            });

        return $created;
    }

    /**
     * Tout ce qui n'est pas « synced » repart : en attente, en échec, ou en
     * conflit. Le job décide lui-même s'il faut écrire ou supprimer.
     */
    private function dispatchPending(CalendarAccount $account): int
    {
        $dispatched = 0;

        CalendarEvent::query()
            ->where('calendar_account_id', $account->getKey())
            ->where('sync_status', '!=', CalendarSyncStatus::Synced->value)
            ->chunkById(200, function ($events) use (&$dispatched): void {
                foreach ($events as $event) {
                    SyncCalendarEvent::dispatch($event);
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    private function statusValue(CalendarAccount $account): string
    {
        $raw = $account->getRawOriginal('status') ?? $account->getAttributes()['status'] ?? '';

        return $raw instanceof CalendarAccountStatus ? $raw->value : (string) $raw;
    }
}
