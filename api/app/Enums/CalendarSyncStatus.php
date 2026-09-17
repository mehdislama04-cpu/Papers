<?php

namespace App\Enums;

/**
 * État de synchronisation d'un VEVENT vers iCloud.
 */
enum CalendarSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Synced => 'Synchronisé',
            self::Failed => 'Échec',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
