<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Concerns\StoresDocumentPages;
use App\Http\Requests\IndexDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\AnalyzeDocument;
use App\Models\Document;
use App\Services\Ai\OpenAiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DocumentController extends Controller
{
    use StoresDocumentPages;

    /**
     * Mémoire de l'embedding d'une requête de recherche. Les gens retapent la
     * même recherche ; inutile de repayer un appel /v1/embeddings.
     */
    private const QUERY_EMBEDDING_TTL = 86_400;

    /**
     * Disjoncteur. Si /v1/embeddings tombe (ou si la clé est révoquée), on
     * cesse d'essayer pendant 5 minutes : sinon CHAQUE recherche part dans un
     * timeout de 120 s avec 3 tentatives, et l'app entière paraît morte.
     */
    private const EMBEDDING_CIRCUIT_KEY = 'papers:search:embedding-down';

    private const EMBEDDING_CIRCUIT_TTL = 300;

    /**
     * GET /api/documents?search=&category=&status=&page=
     */
    public function index(IndexDocumentRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $term = $request->searchTerm();

        $documents = $request->user()->documents()
            ->with([
                'category',
                // Couverture seulement : la liste n'affiche que la première
                // page. Limite native sur eager load (Laravel 11+), traduite
                // en fonction de fenêtrage par PostgreSQL — pas de N+1.
                'pages' => fn ($query) => $query->limit(1),
            ])
            ->withCount('todos')
            ->search($term, $this->queryEmbedding($term, $request->wantsSemantic()))
            ->ofStatus($request->input('status'))
            ->inCategory($request->input('category'))
            /*
             | Tri chronologique, y compris en recherche sémantique.
             |
             | orderByVectorDistance() (scopeOrderBySimilarity) impose un
             | whereNotNull('embedding') : trier par similarité ferait
             | DISPARAÎTRE de la liste tout document pas encore embeddé —
             | c'est-à-dire tout ce qui vient d'être scanné. Le rappel
             | vectoriel sert donc à ÉLARGIR le jeu de résultats, pas à
             | l'ordonner.
             */
            ->latest('created_at')
            ->paginate($request->perPage())
            ->withQueryString();

        return DocumentResource::collection($documents);
    }

    /**
     * POST /api/documents — multipart : pages[], title?, source=scanner|import
     *
     * Répond 202 : les pages sont bien rangées, mais l'analyse est asynchrone.
     * Le front doit suivre `status` (pending -> processing -> analyzed|failed).
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        /** @var array<int, \Illuminate\Http\UploadedFile> $files */
        $files = array_values($request->file('pages'));

        /** @var Document $document */
        $document = $request->user()->documents()->create([
            'title' => $request->title() ?? $this->defaultTitle(),
            'status' => DocumentStatus::Pending,
            'source' => $request->source(),
            'original_filename' => $this->safeOriginalFilename($files[0]->getClientOriginalName()),
            'page_count' => count($files),
        ]);

        try {
            foreach ($files as $index => $file) {
                $this->storeDocumentPage($document, $index + 1, $file);
            }
        } catch (Throwable $e) {
            // Pas de document fantôme sans fichiers, ni de fichiers orphelins
            // sans document : on annule les deux.
            $this->purgeDocumentFiles($document);
            $document->forceDelete();

            throw $e;
        }

        AnalyzeDocument::dispatch($document);

        return DocumentResource::make($document->load(['pages', 'category']))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * GET /api/documents/{document}
     */
    public function show(Request $request, Document $document): JsonResource
    {
        $this->authorize('view', $document);

        return DocumentResource::make($document->load([
            'pages',
            'category',
            'tags',
            'todos' => fn ($query) => $query->with('calendarEvent')->orderByRaw('due_at asc nulls last'),
        ]));
    }

    /**
     * DELETE /api/documents/{document}
     *
     * Suppression LOGIQUE (le modèle porte SoftDeletes) : la fiche disparaît
     * de l'app et de la recherche, la ligne reste récupérable. Les fichiers
     * du disque privé ne sont donc PAS effacés ici — les effacer rendrait la
     * « restauration » mensongère. Leur purge définitive relève d'une tâche de
     * rétention (à brancher côté lot Jobs : documents supprimés depuis N jours
     * -> purgeDocumentFiles + forceDelete).
     */
    public function destroy(Request $request, Document $document): Response
    {
        $this->authorize('delete', $document);

        $document->delete();

        return response()->noContent();
    }

    /**
     * Embedding de la requête de recherche, pour le rappel vectoriel.
     *
     * Best effort, jamais bloquant : la recherche plein texte française
     * (search_vector / fr_unaccent) reste la garantie de base. Un terme trop
     * court n'est pas embeddé — « EDF » ne dit rien à un modèle sémantique et
     * sera de toute façon trouvé par le repli trigramme sur issuer/reference.
     *
     * @return array<int, float>|null
     */
    private function queryEmbedding(string $term, bool $wanted): ?array
    {
        if (! $wanted || mb_strlen($term) < 4) {
            return null;
        }

        if (blank(config('papers.openai.api_key')) || Cache::get(self::EMBEDDING_CIRCUIT_KEY) === true) {
            return null;
        }

        $key = 'papers:search:embedding:'.sha1(mb_strtolower($term));

        try {
            $vector = Cache::remember(
                $key,
                self::QUERY_EMBEDDING_TTL,
                fn () => app(OpenAiClient::class)->embed($term),
            );

            return is_array($vector) && $vector !== [] ? $vector : null;
        } catch (Throwable $e) {
            Cache::put(self::EMBEDDING_CIRCUIT_KEY, true, self::EMBEDDING_CIRCUIT_TTL);

            Log::warning('Recherche sémantique indisponible, repli plein texte', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function defaultTitle(): string
    {
        // Le modèle réécrira ce titre à l'analyse ; celui-ci ne sert qu'à
        // l'affichage immédiat dans la liste, pendant que le job tourne.
        return 'Scan du '.now()->format('d/m/Y à H\hi');
    }

    /**
     * Le nom de fichier client est une donnée NON FIABLE : il ne sert qu'à
     * l'affichage et n'entre jamais dans un chemin de stockage. On le réduit à
     * un basename nettoyé, borné à la taille de la colonne.
     */
    private function safeOriginalFilename(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $name);

        return Str::limit(trim($name), 250, '') ?: null;
    }
}
