<?php

namespace App\Models;

use App\Enums\CalendarSyncStatus;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Miroir local d'un VEVENT poussé dans le calendrier « Papers — Échéances ».
 */
#[Fillable(['uid', 'etag', 'href', 'synced_at', 'sync_status', 'last_error'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'sync_status' => CalendarSyncStatus::class,
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Todo, $this> */
    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    /** @return BelongsTo<CalendarAccount, $this> */
    public function calendarAccount(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class);
    }

    /**
     * Un etag connu signifie que l'objet existe côté serveur : la mise à jour
     * doit alors partir avec If-Match, et non avec If-None-Match: *.
     */
    public function existsRemotely(): bool
    {
        return filled($this->href) && filled($this->etag);
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeUnsynced(Builder $query): Builder
    {
        return $query->whereIn('sync_status', [
            CalendarSyncStatus::Pending->value,
            CalendarSyncStatus::Failed->value,
        ]);
    }
}
