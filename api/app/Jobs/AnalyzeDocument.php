<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Exceptions\OpenAiException;
use App\Models\Document;
use App\Services\Ai\DocumentAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse d'un document par le modèle de vision.
 *
 * Attention Windows (ARCHITECTURE.md §7) : les timeouts de job reposent sur pcntl_alarm,
 * absent ici. #[Timeout] et --timeout ne sont PAS appliqués — la seule protection contre
 * un worker bloqué est le timeout HTTP d'OpenAiClient. Et queue.connections.*.retry_after
 * doit rester supérieur à ce timeout, faute de quoi un second worker reprend le job pendant
 * qu'il tourne encore : double appel OpenAI, double facturation. Le verrou ci-dessous est la
 * ceinture qui va avec cette bretelle.
 */
#[Tries(3)]
#[Backoff(30, 120, 300)]
class AnalyzeDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document) {}

    public function handle(DocumentAnalyzer $analyzer): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return; // Document supprimé entre le dispatch et l'exécution.
        }

        $lock = Cache::lock('papers:analyze:'.$document->getKey(), 900);

        if (! $lock->get()) {
            Log::info('Analyse déjà en cours, job ignoré', ['document_id' => $document->getKey()]);

            return;
        }

        try {
            $document->forceFill([
                'status' => DocumentStatus::Processing,
                'analysis_error' => null,
            ])->save();

            $analyzer->analyze($document);
        } catch (OpenAiException $e) {
            // Refus de sécurité, schéma invalide, clé absente, quota épuisé, page illisible :
            // rejouer coûterait sans rien changer.
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }

            throw $e;
        } finally {
            $lock->release();
        }

        EmbedDocument::dispatch($document);
    }

    /**
     * Échec définitif (tentatives épuisées ou fail() explicite).
     */
    public function failed(?Throwable $exception): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return;
        }

        $document->forceFill([
            'status' => DocumentStatus::Failed,
            'analysis_error' => mb_substr($exception?->getMessage() ?? 'Échec inconnu', 0, 1000),
        ])->save();

        Log::error('Analyse de document en échec définitif', [
            'document_id' => $document->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
