<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCalendarEvent;
use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use App\Services\CalDav\CalDavClient;
use App\Services\CalDav\CalDavException;
use App\Services\CalDav\IcsBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Réconciliation Papers -> iCloud.
 *
 * Ne pousse rien elle-même : elle constate ce qui est en retard et redispatche
 * SyncCalendarEvent, qui est idempotent. Rejouer cette commande n'a donc aucun
 * effet de bord, ce qui est exactement ce qu'il faut pour une tâche planifiée
 * (un changement d'heure peut la faire tourner deux fois, ou zéro fois).
 */
class CalendarResync extends Command
{
    protected $signature = 'papers:calendar-resync
        {--account= : Ne traiter qu\'un compte calendrier (id)}
        {--user= : Ne traiter que les comptes d\'un utilisateur (id)}
        {--force : Redispatcher aussi les événements déjà synchronisés ou en conflit}';

    protected $description = 'Resynchronise les échéances Papers vers les calendriers iCloud.';

    /** Statuts de Todo qui ne doivent plus figurer au calendrier. */
    private const CLOSED_TODO_STATUSES = ['done', 'completed', 'cancelled', 'canceled', 'archived', 'dismissed'];

    public function handle(CalDavClient $client): int
    {
        $accounts = CalendarAccount::query()
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            // Un compte en 401 est terminal : tant que l'utilisateur n'a pas
            // ressaisi un mot de passe d'application, le solliciter ne peut que
            // faire blacklister le compte côté Apple.
            ->where('status', '!=', CalDavClient::STATUS_INVALID_CREDENTIALS)
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('Aucun compte calendrier à resynchroniser.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($accounts as $account) {
            try {
                // Découverte + MKCALENDAR une seule fois par compte, en amont des
                // jobs : DSID et partition pNN sont persistés, les jobs n'ont
                // plus qu'à écrire.
                $client->ensurePapersCalendar($account);
            } catch (CalDavException $e) {
                $message = CalDavException::redact($e->getMessage());

                $account->forceFill([
                    'status' => $e->isTerminal()
                        ? CalDavClient::STATUS_INVALID_CREDENTIALS
                        : CalDavClient::STATUS_ERROR,
                    'last_error' => $message,
                ])->save();

                Log::warning('papers:calendar-resync', [
                    'calendar_account_id' => $account->getKey(),
                    'error' => $message,
                ]);

                $this->warn(sprintf('Compte #%s ignoré : %s', (string) $account->getKey(), $message));

                continue;
            }

            $created = $this->createMissingEvents($account);
            $count = $this->dispatchStaleEvents($account);

            $dispatched += $count;

            $this->line(sprintf(
                'Compte #%s : %d événement(s) créé(s), %d job(s) dispatché(s).',
                (string) $account->getKey(),
                $created,
                $count,
            ));
        }

        $this->info(sprintf('%d job(s) de synchronisation dispatché(s).', $dispatched));

        return self::SUCCESS;
    }

    /**
     * Rattrape les échéances jamais projetées (todo créé pendant une panne
     * iCloud, compte connecté après coup, document ré-analysé...).
     */
    private function createMissingEvents(CalendarAccount $account): int
    {
        $userId = $account->getAttribute('user_id');

        if ($userId === null) {
            return 0;
        }

        $alreadyProjected = CalendarEvent::query()
            ->when($this->usesSoftDeletes(CalendarEvent::class), fn ($q) => $q->withTrashed())
            ->where('calendar_account_id', $account->getKey())
            ->pluck('todo_id')
            ->filter()
            ->all();

        $created = 0;

        Todo::query()
            ->where('user_id', $userId)
            ->whereNotNull('due_at')
            ->whereNotIn('status', self::CLOSED_TODO_STATUSES)
            ->when($alreadyProjected !== [], fn ($q) => $q->whereNotIn('id', $alreadyProjected))
            ->chunkById(200, function ($todos) use ($account, &$created): void {
                foreach ($todos as $todo) {
                    // forceCreate : indépendant du $fillable du modèle.
                    CalendarEvent::query()->forceCreate([
                        'todo_id' => $todo->getKey(),
                        'calendar_account_id' => $account->getKey(),
                        // UID déterministe : même si cette ligne est recréée, elle
                        // retombera sur la même ressource .ics côté iCloud.
                        'uid' => IcsBuilder::uidFor($todo),
                        'sequence' => 0,
                        'sync_status' => CalDavClient::SYNC_PENDING,
                    ]);

                    $created++;
                }
            });

        return $created;
    }

    /** Redispatche tout ce qui n'est pas à jour côté iCloud. */
    private function dispatchStaleEvents(CalendarAccount $account): int
    {
        $count = 0;

        CalendarEvent::query()
            // Les lignes soft-deleted sont des suppressions à pousser : le job
            // les traduit en DELETE CalDAV.
            ->when($this->usesSoftDeletes(CalendarEvent::class), fn ($q) => $q->withTrashed())
            ->where('calendar_account_id', $account->getKey())
            ->when(! $this->option('force'), function ($q) {
                $q->where(function ($q) {
                    $q->whereIn('sync_status', [CalDavClient::SYNC_PENDING, CalDavClient::SYNC_FAILED])
                        ->orWhereNull('last_synced_at')
                        // Le Todo a bougé depuis le dernier push (l'écriture du
                        // Todo doit toucher son CalendarEvent).
                        ->orWhereColumn('updated_at', '>', 'last_synced_at');
                });
                // Un conflit signifie « l'appareil a gagné » : on ne le rejoue
                // pas tant que l'utilisateur n'a pas tranché (--force).
                $q->where('sync_status', '!=', CalDavClient::SYNC_CONFLICT);
            })
            ->orderBy('id')
            ->chunkById(200, function ($events) use (&$count): void {
                foreach ($events as $event) {
                    SyncCalendarEvent::dispatch($event);
                    $count++;
                }
            });

        return $count;
    }

    /** @param  class-string  $model */
    private function usesSoftDeletes(string $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
