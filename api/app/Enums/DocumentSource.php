<?php

namespace App\Enums;

/**
 * Provenance des pages d'un document.
 *
 * scanner  -> capture + redressement dans la PWA
 * shortcut -> PDF poussé par le raccourci iOS sur /api/ingest/shortcut
 * import   -> fichier choisi depuis la photothèque ou Fichiers
 */
enum DocumentSource: string
{
    case Scanner = 'scanner';
    case Shortcut = 'shortcut';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Scanner => 'Scanner',
            self::Shortcut => 'Raccourci iOS',
            self::Import => 'Import',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
