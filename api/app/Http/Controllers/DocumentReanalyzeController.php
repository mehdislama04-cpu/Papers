<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Resources\DocumentResource;
use App\Jobs\AnalyzeDocument;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentReanalyzeController extends Controller
{
    /**
     * POST /api/documents/{document}/reanalyze
     *
     * Relance l'analyse : après un échec (clé OpenAI absente, page illisible,
     * quota), ou après un changement de modèle.
     *
     * Pas de garde « déjà en cours » côté HTTP : AnalyzeDocument pose un
     * verrou de cache par document (papers:analyze:{id}) et se retire tout
     * seul si un autre worker l'a déjà. Un double-clic ne peut donc pas
     * facturer deux analyses.
     */
    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        abort_if(
            $document->pages()->doesntExist(),
            422,
            "Ce document n'a aucune page à analyser.",
        );

        $document->forceFill([
            'status' => DocumentStatus::Pending,
            'analysis_error' => null,
        ])->save();

        AnalyzeDocument::dispatch($document);

        return DocumentResource::make($document->load(['pages', 'category']))
            ->response()
            ->setStatusCode(202);
    }
}
