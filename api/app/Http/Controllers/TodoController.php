<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CalendarAccountStatus;
use App\Enums\CalendarSyncStatus;
use App\Enums\TodoStatus;
use App\Http\Requests\IndexTodoRequest;
use App\Http\Requests\UpdateTodoRequest;
use App\Http\Resources\TodoResource;
use App\Jobs\SyncCalendarEvent;
use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TodoController extends Controller
{
    /**
     * GET /api/todos?status=&due_before=
     */
    public function index(IndexTodoRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Todo::class);

        $todos = $request->user()->todos()
            ->with(['document:id,title', 'calendarEvent'])
            ->ofStatus($request->input('status'))
            ->dueBefore($request->input('due_before'))
            // Les échéances d'abord, du plus urgent au plus lointain ; les
            // tâches sans date ferment la marche (NULLS LAST est explicite en
            // PostgreSQL, sans quoi les NULL passeraient en tête).
            ->orderByRaw('due_at asc nulls last')
            ->orderByDesc('priority')
            ->latest('created_at')
            ->paginate($request->perPage())
            ->withQueryString();

        return TodoResource::collection($todos);
    }

    /**
     * PATCH /api/todos/{todo} — { status?, due_at?, title?, priority? }
     *
     * C'est ICI que se prend la décision d'écrire dans iCloud. Une tâche
     * extraite par le modèle est une PROPOSITION : la poussée calendrier est
     * un effet de bord d'une décision applicative, jamais la conséquence
     * directe d'une phrase trouvée dans un document (ARCHITECTURE.md §10).
     */
    public function update(UpdateTodoRequest $request, Todo $todo): JsonResource
    {
        $this->authorize('update', $todo);

        $changes = $request->changes();

        $todo->fill($changes);

        if (array_key_exists('status', $changes)) {
            $status = $todo->status;

            // completed_at suit le statut, sans que le client ait à l'envoyer
            // (et sans qu'il puisse le falsifier : le champ n'est pas exposé).
            $todo->completed_at = $status === TodoStatus::Done ? now() : null;
        }

        $todo->save();

        $this->reconcileCalendar($request->user()->calendarAccount, $todo);

        return TodoResource::make($todo->load(['document:id,title', 'calendarEvent']));
    }

    /**
     * DELETE /api/todos/{todo}
     *
     * L'événement iCloud doit partir AVANT la ligne locale : calendar_events
     * est en cascade sur todos, donc supprimer d'abord le Todo effacerait le
     * miroir local et laisserait un événement orphelin dans le calendrier de
     * l'utilisateur, que plus rien ne saurait retrouver (l'UID est dérivé de
     * l'identifiant du Todo).
     *
     * D'où un dispatchSync : quelques centaines de millisecondes de CalDAV
     * dans la requête, bornées par le timeout Guzzle du client (30 s), contre
     * un déchet permanent dans le calendrier. Si iCloud est injoignable, on
     * supprime quand même en local et la commande papers:calendar-resync
     * rattrapera.
     */
    public function destroy(Request $request, Todo $todo): Response
    {
        $this->authorize('delete', $todo);

        $event = $todo->calendarEvent;

        if ($event !== null) {
            // Le job recalcule l'état voulu à partir du Todo : marqué
            // « ignoré », il en déduit une suppression côté iCloud.
            $todo->forceFill(['status' => TodoStatus::Dismissed])->save();

            try {
                SyncCalendarEvent::dispatchSync($event);
            } catch (Throwable $e) {
                Log::warning('Suppression de l’événement iCloud impossible avant suppression de la tâche', [
                    'todo_id' => $todo->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $todo->delete();

        return response()->noContent();
    }

    /**
     * Aligne le miroir CalendarEvent sur l'état courant du Todo, puis laisse
     * le job faire le trajet réseau.
     *
     * On dispatche aussi quand la tâche ne doit PLUS être au calendrier :
     * SyncCalendarEvent::shouldRemove() s'en charge (échéance retirée, tâche
     * faite ou ignorée) — c'est ce qui évite les fantômes.
     */
    private function reconcileCalendar(?CalendarAccount $account, Todo $todo): void
    {
        if ($account === null) {
            return;
        }

        // Un compte en 401 est TERMINAL : le solliciter ne peut que le faire
        // verrouiller côté Apple. On attend de nouveaux identifiants.
        if ($this->accountStatus($account) === CalendarAccountStatus::InvalidCredentials->value) {
            return;
        }

        $event = $todo->calendarEvent()->first();

        if ($event === null) {
            if (! $todo->shouldSyncToCalendar()) {
                return; // rien à créer pour une tâche sans échéance ou déjà close
            }

            $event = new CalendarEvent;
            $event->forceFill([
                'todo_id' => $todo->getKey(),
                'calendar_account_id' => $account->getKey(),
                // UID DÉTERMINISTE : rejouer la synchronisation écrase le même
                // objet .ics au lieu d'en créer un doublon.
                'uid' => $todo->calendarUid(),
            ]);
        }

        $event->forceFill([
            'sync_status' => CalendarSyncStatus::Pending->value,
            'last_error' => null,
        ])->save();

        SyncCalendarEvent::dispatch($event);
    }

    /**
     * Lecture brute du statut : CalDavClient y écrit ses propres constantes
     * ('ok'), hors de l'enum, et un accès casté lèverait un ValueError.
     */
    private function accountStatus(CalendarAccount $account): string
    {
        $raw = $account->getRawOriginal('status') ?? $account->getAttributes()['status'] ?? '';

        return $raw instanceof CalendarAccountStatus ? $raw->value : (string) $raw;
    }
}
