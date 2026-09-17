<?php

namespace App\Models;

use App\Enums\TodoStatus;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Échéance extraite d'un document (ou saisie à la main).
 *
 * Les Rappels iCloud (VTODO) étant inexploitables via CalDAV depuis iOS 13,
 * la tâche vit ici ; ce qui part dans iCloud est un VEVENT avec VALARM, porté
 * par CalendarEvent.
 *
 * Une tâche issue du modèle est une PROPOSITION : la poussée calendrier est
 * une décision applicative, jamais la conséquence directe d'une phrase trouvée
 * dans le document.
 *
 * `user_id` n'est pas fillable : passer par $user->todos()->create(...).
 */
#[Fillable(['document_id', 'title', 'details', 'due_at', 'all_day', 'priority', 'status', 'completed_at'])]
class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'all_day' => 'boolean',
            'priority' => 'integer',
            'status' => TodoStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasOne<CalendarEvent, $this> */
    public function calendarEvent(): HasOne
    {
        return $this->hasOne(CalendarEvent::class);
    }

    /**
     * UID déterministe du VEVENT. Déterministe = idempotent : rejouer une
     * synchronisation écrase le même objet iCloud au lieu d'en créer un
     * doublon. Il est dérivé de la clé primaire, qui ne change jamais.
     */
    public function calendarUid(): string
    {
        return 'papers-todo-'.$this->getKey().'@papers.app';
    }

    /**
     * Seule une tâche en attente et datée mérite un événement.
     */
    public function shouldSyncToCalendar(): bool
    {
        return $this->due_at !== null && $this->status->shouldSyncToCalendar();
    }

    /**
     * @param  Builder<Todo>  $query
     * @return Builder<Todo>
     */
    public function scopeOfStatus(Builder $query, TodoStatus|string|null $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', $status instanceof TodoStatus ? $status->value : $status);
    }

    /**
     * @param  Builder<Todo>  $query
     * @return Builder<Todo>
     */
    public function scopeDueBefore(Builder $query, mixed $date): Builder
    {
        if (blank($date)) {
            return $query;
        }

        return $query->whereNotNull('due_at')->where('due_at', '<=', $date);
    }

    /**
     * @param  Builder<Todo>  $query
     * @return Builder<Todo>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TodoStatus::Pending->value);
    }
}
