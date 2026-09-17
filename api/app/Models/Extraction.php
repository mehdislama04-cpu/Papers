<?php

namespace App\Models;

use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace immuable d'un appel au modèle : sortie structurée, consommation et
 * coût. Conservée pour l'audit et pour rejouer une analyse après changement
 * de modèle.
 */
#[Fillable(['model', 'payload', 'input_tokens', 'output_tokens', 'cost_micros'])]
class Extraction extends Model
{
    /** @use HasFactory<ExtractionFactory> */
    use HasFactory;

    /**
     * Enregistrement append-only : Eloquent ne renseigne que created_at.
     */
    public const UPDATED_AT = null;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Coût en euros, pour affichage uniquement — les calculs restent en
     * micros entiers.
     */
    public function costInEuros(): float
    {
        return $this->cost_micros / 1_000_000;
    }
}
