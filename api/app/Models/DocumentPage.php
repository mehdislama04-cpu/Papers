<?php

namespace App\Models;

use Database\Factories\DocumentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une page image d'un document, telle qu'elle est stockée sur le disque privé.
 */
#[Fillable([
    'page_number',
    'storage_path',
    'thumb_path',
    'width',
    'height',
    'bytes',
    'checksum',
])]
class DocumentPage extends Model
{
    /** @use HasFactory<DocumentPageFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Nombre de patches facturés par le modèle de vision, en detail "original".
     * Tokenisation par patches 32x32, majorée de 20 %.
     *
     * Sert au garde-fou avant l'envoi : au-delà de 30 000 patches la requête
     * est REJETÉE par l'API, et non redimensionnée.
     */
    public function visionPatches(): int
    {
        return (int) ceil(ceil($this->width / 32) * ceil($this->height / 32) * 1.2);
    }
}
