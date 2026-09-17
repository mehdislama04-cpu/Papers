<?php

namespace App\Enums;

/**
 * État d'une tâche extraite d'un document.
 *
 * Une tâche `dismissed` est une proposition du modèle que l'utilisateur a
 * refusée : elle reste en base pour ne pas être re-proposée, mais son
 * événement calendrier doit être supprimé côté iCloud.
 */
enum TodoStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À faire',
            self::Done => 'Fait',
            self::Dismissed => 'Ignoré',
        };
    }

    /**
     * Seules les tâches en attente méritent un événement dans le calendrier.
     */
    public function shouldSyncToCalendar(): bool
    {
        return $this === self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
