<?php

namespace App\Enums;

/**
 * Cycle de vie d'un document.
 *
 * pending    -> pages stockées, analyse pas encore lancée
 * processing -> le job AnalyzeDocument tourne
 * analyzed   -> extraction validée et persistée
 * failed     -> analyse abandonnée, analysis_error renseigné
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Analyzed = 'analyzed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Processing => 'Analyse en cours',
            self::Analyzed => 'Analysé',
            self::Failed => 'Échec',
        };
    }

    /**
     * Un document dans un état terminal ne sera plus modifié par un job.
     */
    public function isTerminal(): bool
    {
        return $this === self::Analyzed || $this === self::Failed;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
