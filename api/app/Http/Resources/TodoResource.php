<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Todo
 */
class TodoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->whenLoaded('calendarEvent');

        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'details' => $this->details,

            // ISO 8601 avec décalage : la colonne est timestamptz, le front
            // affiche en heure locale de l'appareil.
            'due_at' => $this->due_at?->toAtomString(),
            'all_day' => (bool) $this->all_day,
            'priority' => $this->priority,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'completed_at' => $this->completed_at?->toAtomString(),

            'document_id' => $this->document_id,
            'document' => $this->when(
                $this->relationLoaded('document') && $this->document !== null,
                fn () => [
                    'id' => $this->document->getKey(),
                    'title' => $this->document->title,
                ],
            ),

            // État de la projection iCloud. Une tâche est une PROPOSITION :
            // tant qu'elle n'est pas synchronisée, rien n'existe côté Apple.
            'calendar' => $this->when(
                $this->relationLoaded('calendarEvent') && $event !== null,
                fn () => [
                    'sync_status' => (string) ($this->calendarEvent->getRawOriginal('sync_status')
                        ?? $this->calendarEvent->getAttributes()['sync_status'] ?? 'pending'),
                    'synced_at' => $this->calendarEvent->synced_at?->toAtomString(),
                    'last_error' => $this->calendarEvent->getAttribute('last_error'),
                ],
            ),

            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
