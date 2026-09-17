<?php

namespace Database\Factories;

use App\Enums\CalendarSyncStatus;
use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'todo_id' => Todo::factory(),
            'calendar_account_id' => CalendarAccount::factory()->connected(),
            // UID déterministe : il doit être dérivé de la clé de la tâche.
            // ->forTodo() le recalcule correctement ; ici on ne fait qu'en
            // respecter la forme.
            'uid' => 'papers-todo-'.Str::uuid7()->toString().'@papers.app',
            'etag' => null,
            'href' => null,
            'synced_at' => null,
            'sync_status' => CalendarSyncStatus::Pending,
            'last_error' => null,
        ];
    }

    public function forTodo(Todo $todo): static
    {
        return $this->state(fn () => [
            'todo_id' => $todo->getKey(),
            'uid' => $todo->calendarUid(),
        ]);
    }

    /**
     * Événement déjà poussé : href et etag connus, donc la prochaine écriture
     * part avec If-Match et non If-None-Match: *.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'href' => '/'.fake()->numberBetween(100_000_000, 999_999_999).'/calendars/papers/'.$attributes['uid'].'.ics',
            'etag' => '"'.fake()->sha1().'"',
            'synced_at' => now(),
            'sync_status' => CalendarSyncStatus::Synced,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'sync_status' => CalendarSyncStatus::Failed,
            'last_error' => 'HTTP 421 Misdirected Request (coalescing HTTP/2 : forcer HTTP/1.1).',
        ]);
    }
}
