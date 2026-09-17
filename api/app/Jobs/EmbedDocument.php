<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\OpenAiException;
use App\Models\Document;
use App\Services\Ai\OpenAiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcule l'embedding d'un document et le range dans la colonne vector(1536).
 *
 * text-embedding-3-small (1536 dims) : text-embedding-3-large (3072) NE RENTRE PAS dans un
 * index HNSW pgvector, plafonné à 2 000 dimensions (ARCHITECTURE.md §9).
 *
 * L'embedding est un confort de recherche, pas un prérequis : un échec ne fait jamais passer
 * le document en `failed`, la recherche plein texte française continue de fonctionner.
 */
#[Tries(3)]
#[Backoff(30, 120, 300)]
class EmbedDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document) {}

    public function handle(OpenAiClient $client): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return;
        }

        $text = $document->embeddableText();

        if (trim($text) === '') {
            return;
        }

        try {
            $vector = $client->embed($text);
        } catch (OpenAiException $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        // Cast AsVector côté modèle : un tableau de flottants est accepté tel quel.
        $document->forceFill(['embedding' => $vector])->save();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Embedding non calculé', [
            'document_id' => $this->document->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
