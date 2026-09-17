<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\CalendarSyncStatus;
use App\Enums\DocumentStatus;
use App\Enums\TodoStatus;
use App\Exceptions\OpenAiException;
use App\Jobs\SyncCalendarEvent;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\Extraction;
use App\Models\Todo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pipeline d'analyse d'un document : payload multi-images, appel OpenAI, validation,
 * persistance de l'Extraction, mise à jour du Document, création des Todo et des
 * CalendarEvent en attente de synchronisation.
 *
 * Points vérifiés (ARCHITECTURE.md §5, §6, §10) :
 *  - une input_image par page, detail:'original' — 'high' écraserait l'image dans 2048×2048
 *    et 2 500 patches, ce qui détruit le petit texte d'une A4 ;
 *  - store:false — documents personnels, aucune rétention applicative côté OpenAI ;
 *  - le contenu du document est encadré par des délimiteurs et traité comme une donnée
 *    hostile ; aucune sortie du modèle ne déclenche d'action privilégiée. Un Todo est une
 *    proposition, la poussée calendrier est une décision applicative prise ici.
 */
class DocumentAnalyzer
{
    /** Repli si `papers.images.max_patches` n'est pas configuré. */
    private const MAX_PATCHES_PER_IMAGE = 30000;

    /**
     * Tarifs par million de tokens, dans l'unité de `extractions.cost_micros`
     * (millionièmes). Repli si `papers.openai.pricing` n'est pas configuré.
     *
     * @var array<string, array{input: float, cached: float, output: float}>
     */
    private const DEFAULT_PRICING = [
        'gpt-6-astra' => ['input' => 10.0, 'cached' => 1.0, 'output' => 50.0],
        'gpt-5.6-sol' => ['input' => 4.0, 'cached' => 0.40, 'output' => 20.0],
        'gpt-5.6-terra' => ['input' => 2.0, 'cached' => 0.20, 'output' => 12.0],
        'gpt-5.6-luna' => ['input' => 0.20, 'cached' => 0.02, 'output' => 1.20],
    ];

    public function __construct(
        private readonly OpenAiClient $client,
        private readonly ExtractionSchema $schema,
    ) {}

    /**
     * Analyse le document et retourne le JSON validé de l'extraction.
     *
     * @return array<string, mixed>
     */
    public function analyze(Document $document): array
    {
        /** @var Collection<int, DocumentPage> $pages */
        $pages = $document->pages()->orderBy('page_number')->get();

        if ($pages->isEmpty()) {
            throw OpenAiException::payload("Document {$document->getKey()} sans page à analyser.");
        }

        $model = (string) config('papers.openai.vision.model', 'gpt-5.6-terra');

        $response = $this->client->responses($this->payload($pages, $model));
        $data = $this->decode($response);

        $this->persist($document, $data, $response, $model);

        return $data;
    }

    /**
     * @param  Collection<int, DocumentPage>  $pages
     * @return array<string, mixed>
     */
    private function payload(Collection $pages, string $model): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => Prompts::open($pages->count()),
        ]];

        foreach ($pages as $page) {
            $content[] = [
                'type' => 'input_image',
                // 'original' : ce que la doc OpenAI recommande pour l'OCR.
                'detail' => (string) config('papers.openai.vision.detail', 'original'),
                'image_url' => $this->dataUri($page),
            ];
        }

        $content[] = [
            'type' => 'input_text',
            'text' => Prompts::close(),
        ];

        $effort = (string) config('papers.openai.vision.reasoning_effort', 'low');

        // gpt-6-astra ne supporte pas 'none' : un code qui le passe génériquement
        // casserait lors d'une escalade vers astra.
        if ($effort === 'none' && str_starts_with($model, 'gpt-6')) {
            $effort = 'low';
        }

        $payload = [
            'model' => $model,
            'store' => false,
            'reasoning' => ['effort' => $effort],
            'max_output_tokens' => (int) config('papers.openai.vision.max_output_tokens', 8000),
            'instructions' => Prompts::system(
                CarbonImmutable::now()->toDateString(),
                $this->schema->categorySlugs(),
            ),
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => $this->schema->format()],
        ];

        // 'flex' = tarif Batch en synchrone ; n'a de sens qu'avec un timeout HTTP élevé.
        if (is_string($tier = config('papers.openai.vision.service_tier')) && $tier !== '') {
            $payload['service_tier'] = $tier;
        }

        return $payload;
    }

    private function dataUri(DocumentPage $page): string
    {
        // Garde-fou avant lecture du fichier : les dimensions sont déjà en base.
        $maxPatches = (int) config('papers.images.max_patches', self::MAX_PATCHES_PER_IMAGE);

        if ($page->visionPatches() > $maxPatches) {
            throw OpenAiException::payload(sprintf(
                'Page %d du document %s trop grande pour detail:original (%d×%d, %d patches, '
                .'maximum %d) : elle doit être redimensionnée avant envoi.',
                $page->page_number, (string) $page->document_id,
                $page->width, $page->height, $page->visionPatches(), $maxPatches,
            ));
        }

        $binary = Storage::disk($this->disk())->get($page->storage_path);

        if ($binary === null || $binary === '') {
            throw OpenAiException::payload(
                "Fichier introuvable pour la page {$page->page_number} ({$page->storage_path})."
            );
        }

        $mime = str_ends_with(strtolower($page->storage_path), '.png') ? 'image/png' : 'image/jpeg';

        return "data:{$mime};base64,".base64_encode($binary);
    }

    private function disk(): string
    {
        return (string) config('papers.storage.disk', 'documents');
    }

    /**
     * Décode la sortie. Refus et status != completed sont déjà traités par
     * OpenAiClient::responses().
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decode(array $response): array
    {
        $text = OpenAiClient::outputText($response);

        if (trim($text) === '') {
            throw OpenAiException::payload('Réponse OpenAI sans texte de sortie.');
        }

        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw OpenAiException::payload("JSON d'extraction illisible : ".$e->getMessage());
        }

        if (! is_array($data)) {
            throw OpenAiException::payload("JSON d'extraction inattendu (racine non objet).");
        }

        return $this->normalize($data);
    }

    /**
     * Le mode strict garantit la forme, mais une sortie de modèle n'alimente jamais la base
     * sans être re-validée et bornée.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $slugs = $this->schema->categorySlugs();
        $slug = is_string($data['category_slug'] ?? null) ? $data['category_slug'] : 'autre';

        $actions = [];

        foreach ((array) ($data['actions'] ?? []) as $action) {
            if (! is_array($action) || ! is_string($action['title'] ?? null) || trim($action['title']) === '') {
                continue;
            }

            $actions[] = [
                'title' => mb_substr(trim($action['title']), 0, 255),
                'details' => $this->text($action['details'] ?? null),
                'due_date' => $this->date($action['due_date'] ?? null),
                'all_day' => (bool) ($action['all_day'] ?? true),
                // Echelle du MODELE : 1 = haute, 2 = moyenne, 3 = basse (cf.
                // ExtractionSchema). Elle est conservee telle quelle ici, et
                // convertie vers l'echelle de stockage par
                // modelPriorityToStored() au moment de creer la tache.
                'priority' => max(1, min(3, (int) ($action['priority'] ?? 2))),
                'confidence' => max(0.0, min(1.0, (float) ($action['confidence'] ?? 0.0))),
            ];
        }

        $keyFacts = [];

        foreach ((array) ($data['key_facts'] ?? []) as $fact) {
            if (is_string($fact) && trim($fact) !== '') {
                $keyFacts[] = trim($fact);
            }
        }

        $amount = $data['total_amount'] ?? null;
        $currency = $data['currency'] ?? null;

        return [
            'doc_type' => is_string($data['doc_type'] ?? null) ? $data['doc_type'] : 'autre',
            'title' => is_string($data['title'] ?? null) ? mb_substr(trim($data['title']), 0, 255) : null,
            'language' => is_string($data['language'] ?? null) ? mb_substr($data['language'], 0, 8) : null,
            'issuer' => $this->text($data['issuer'] ?? null),
            'recipient' => $this->text($data['recipient'] ?? null),
            'doc_date' => $this->date($data['doc_date'] ?? null),
            'due_date' => $this->date($data['due_date'] ?? null),
            'reference' => $this->text($data['reference'] ?? null),
            'total_amount' => is_numeric($amount) ? round((float) $amount, 2) : null,
            'currency' => is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            'summary' => $this->text($data['summary'] ?? null),
            'key_facts' => $keyFacts,
            'category_slug' => in_array($slug, $slugs, true) ? $slug : 'autre',
            'actions' => $actions,
        ];
    }

    /**
     * Convertit la priorite renvoyee par le modele vers l'echelle de stockage.
     *
     * Les deux echelles sont INVERSEES l'une par rapport a l'autre, et c'est une
     * source de bug silencieux :
     *  - modele  : 1 = haute, 2 = moyenne, 3 = basse (ExtractionSchema) ;
     *  - stockage: entier signe centre sur 0, ou le PLUS GRAND est le PLUS
     *    urgent (colonne smallint DEFAULT 0, validee entre -2 et 2 par
     *    UpdateTodoRequest).
     *
     * Sans cette conversion, une action urgente serait enregistree a 1 et une
     * action anodine a 3, donc classee plus haut ; et la valeur 3 sortirait de
     * la plage acceptee par PATCH /api/todos/{todo}, rendant la tache non
     * modifiable.
     */
    public static function modelPriorityToStored(int $modelPriority): int
    {
        return match (max(1, min(3, $modelPriority))) {
            1 => 1,   // haute
            3 => -1,  // basse
            default => 0, // moyenne
        };
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Date AAAA-MM-JJ ou null. Jamais d'exception : une date illisible est traitée comme
     * absente, conformément à la règle « null plutôt que deviner ».
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $response
     */
    private function persist(Document $document, array $data, array $response, string $model): void
    {
        $usage = OpenAiClient::usage($response);
        $categoryId = $this->categoryId($document, $data['category_slug']);
        $account = $document->user?->calendarAccount;
        $accountId = $account !== null && $account->isReady() ? $account->getKey() : null;

        /** @var list<CalendarEvent> $toSync */
        $toSync = [];

        DB::transaction(function () use ($document, $data, $usage, $model, $categoryId, $accountId, &$toSync): void {
            $extraction = new Extraction();
            $extraction->forceFill([
                'document_id' => $document->getKey(),
                'model' => $model,
                'payload' => $data,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cost_micros' => $this->costMicros($model, $usage),
            ])->save();

            $document->forceFill([
                'status' => DocumentStatus::Analyzed,
                // Le titre saisi par l'utilisateur à l'upload prime toujours ;
                // sans titre fourni, la colonne vaut '' et c'est l'extraction qui la remplit.
                'title' => $this->text($document->title) ?? $data['title'] ?? 'Document sans titre',
                'language' => $data['language'],
                'summary' => $data['summary'],
                'doc_date' => $data['doc_date'],
                'issuer' => $data['issuer'],
                'recipient' => $data['recipient'],
                'total_amount' => $data['total_amount'],
                'currency' => $data['currency'],
                'reference' => $data['reference'],
                'category_id' => $categoryId,
                'raw_text' => $this->searchableText($data),
                'analysis_error' => null,
                'analyzed_at' => CarbonImmutable::now(),
            ])->save();

            $toSync = array_merge($toSync, $this->supersedePreviousTodos($document));

            foreach ($data['actions'] as $action) {
                $todo = new Todo();
                $todo->forceFill([
                    'user_id' => $document->user_id,
                    'document_id' => $document->getKey(),
                    'title' => $action['title'],
                    'details' => $action['details'],
                    'due_at' => $action['due_date'],
                    'all_day' => $action['all_day'],
                    'priority' => self::modelPriorityToStored($action['priority']),
                    'status' => TodoStatus::Pending,
                ])->save();

                // Pas d'échéance -> pas d'événement. Pas de compte iCloud prêt non plus :
                // la tâche reste en base et POST /api/calendar/resync la rattrapera.
                if ($action['due_date'] === null || $accountId === null) {
                    continue;
                }

                $event = new CalendarEvent();
                $event->forceFill([
                    'todo_id' => $todo->getKey(),
                    'calendar_account_id' => $accountId,
                    // UID déterministe dérivé de l'UUID du Todo : rend le PUT CalDAV idempotent.
                    'uid' => $todo->calendarUid(),
                    'sync_status' => CalendarSyncStatus::Pending,
                ])->save();

                $toSync[] = $event;
            }
        });

        // Hors transaction : un job ne doit jamais partir avant le commit.
        foreach ($toSync as $event) {
            SyncCalendarEvent::dispatch($event);
        }

        Log::info('Document analysé', [
            'document_id' => $document->getKey(),
            'model' => $model,
            'todos' => count($data['actions']),
            'input_tokens' => $usage['input_tokens'],
            'cached_tokens' => $usage['cached_tokens'],
            'output_tokens' => $usage['output_tokens'],
        ]);
    }

    /**
     * Réanalyse : les propositions précédentes sont retirées avant d'en créer de nouvelles,
     * sinon chaque passage empile des doublons.
     *
     * Les tâches sont passées en `dismissed` plutôt que supprimées : leur CalendarEvent
     * survit donc assez longtemps pour que SyncCalendarEvent aille bien effacer le VEVENT
     * chez iCloud (une suppression en cascade laisserait un événement orphelin dans le
     * calendrier de l'utilisateur). Une tâche déjà faite n'est pas touchée.
     *
     * @return list<CalendarEvent>
     */
    private function supersedePreviousTodos(Document $document): array
    {
        $events = [];

        $previous = $document->todos()
            ->where('status', TodoStatus::Pending->value)
            ->with('calendarEvent')
            ->get();

        foreach ($previous as $todo) {
            $todo->forceFill(['status' => TodoStatus::Dismissed])->save();

            if ($todo->calendarEvent !== null) {
                $events[] = $todo->calendarEvent;
            }
        }

        return $events;
    }

    /**
     * Coût en millionièmes : tokens × prix par million, l'unité de extractions.cost_micros.
     * Les tokens en cache sont un sous-ensemble de input_tokens et sont facturés moins cher.
     *
     * @param  array{input_tokens: int, output_tokens: int, cached_tokens: int, reasoning_tokens: int, total_tokens: int}  $usage
     */
    private function costMicros(string $model, array $usage): int
    {
        $pricing = config("papers.openai.pricing.{$model}", self::DEFAULT_PRICING[$model] ?? null);

        if (! is_array($pricing)) {
            return 0;
        }

        $cached = min($usage['cached_tokens'], $usage['input_tokens']);
        $fresh = $usage['input_tokens'] - $cached;

        return (int) round(
            $fresh * (float) ($pricing['input'] ?? 0)
            + $cached * (float) ($pricing['cached'] ?? 0)
            + $usage['output_tokens'] * (float) ($pricing['output'] ?? 0)
        );
    }

    private function categoryId(Document $document, string $slug): ?int
    {
        $id = Category::query()
            ->where('slug', $slug)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $document->user_id))
            // Une catégorie personnalisée de l'utilisateur prime sur la catégorie système.
            ->orderByRaw('user_id IS NULL')
            ->value('id');

        if ($id === null) {
            Log::warning('Catégorie inconnue du seeder', ['slug' => $slug]);
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * Texte indexable (colonne raw_text, source de la colonne générée search_vector).
     *
     * On n'exige pas du modèle la transcription intégrale des pages : elle coûterait plus
     * cher en tokens de sortie que toute l'extraction et saturerait max_output_tokens.
     * Le texte indexé est reconstruit à partir des champs extraits.
     *
     * @param  array<string, mixed>  $data
     */
    private function searchableText(array $data): string
    {
        $parts = [
            $data['title'],
            $data['issuer'],
            $data['recipient'],
            $data['reference'],
            $data['summary'],
        ];

        foreach ($data['key_facts'] as $fact) {
            $parts[] = $fact;
        }

        foreach ($data['actions'] as $action) {
            $parts[] = $action['title'];
            $parts[] = $action['details'];
        }

        return trim(implode("\n", array_filter(
            $parts,
            static fn ($part): bool => is_string($part) && trim($part) !== '',
        )));
    }
}
