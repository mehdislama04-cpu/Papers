<?php

namespace App\Models;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un document papier numérisé : une ou plusieurs pages, plus le résultat de
 * l'analyse par le modèle de vision.
 *
 * `user_id` n'est volontairement PAS fillable : on passe toujours par la
 * relation ($request->user()->documents()->create(...)), qui renseigne la clé
 * étrangère hors du mass assignment. C'est ce qui empêche un `user_id` venu
 * du corps de la requête de rattacher un document au compte d'un tiers.
 */
#[Fillable([
    'category_id',
    'title',
    'status',
    'source',
    'original_filename',
    'page_count',
    'language',
    'summary',
    'doc_date',
    'issuer',
    'recipient',
    'total_amount',
    'currency',
    'reference',
    'raw_text',
    'analysis_error',
    'analyzed_at',
])]
// embedding : 1536 flottants, inutile au client et coûteux à sérialiser.
// search_vector : colonne GÉNÉRÉE, jamais écrite par Eloquent, jamais exposée.
#[Hidden(['embedding', 'search_vector'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'source' => DocumentSource::class,
            'page_count' => 'integer',
            'doc_date' => 'date',
            'total_amount' => 'decimal:2',
            'analyzed_at' => 'datetime',
            'embedding' => AsVector::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<DocumentPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    /** @return HasMany<Extraction, $this> */
    public function extractions(): HasMany
    {
        return $this->hasMany(Extraction::class)->latest('created_at');
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Texte à envoyer au modèle d'embedding. Volontairement limité aux champs
     * synthétiques plus un extrait de l'OCR : text-embedding-3-small plafonne
     * à 8191 tokens, et un contrat de 20 pages les dépasserait.
     */
    public function embeddableText(): string
    {
        return trim(implode("\n", array_filter([
            $this->title,
            $this->issuer,
            $this->summary,
            mb_substr((string) $this->raw_text, 0, 8000),
        ])));
    }

    /**
     * Recherche combinée : plein texte français (colonne générée search_vector)
     * et, si un embedding de la requête est fourni, similarité vectorielle.
     *
     * La clause plein texte est écrite en whereRaw() et NON avec
     * whereFullText($col, $q, ['vector' => true]) : cette dernière construit le
     * côté droit de l'opérateur @@ avec plainto_tsquery('english', ?), et la
     * langue subit une liste blanche (PostgresGrammar::validFullTextLanguages)
     * dans laquelle une configuration personnalisée comme 'fr_unaccent'
     * n'entre pas — elle serait silencieusement remplacée par 'english'. Les
     * lexèmes des deux côtés ne correspondraient alors jamais. Voir la
     * migration 2026_09_17_001000_add_fulltext_search_to_documents_table.
     *
     * @param  Builder<Document>  $query
     * @param  array<int, float>|null  $embedding  embedding de la requête (1536 dimensions)
     * @return Builder<Document>
     */
    public function scopeSearch(
        Builder $query,
        ?string $term,
        ?array $embedding = null,
        float $minSimilarity = 0.72,
    ): Builder {
        $term = trim((string) $term);

        if ($term === '' && $embedding === null) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term, $embedding, $minSimilarity) {
            if ($term !== '') {
                $inner->whereRaw("search_vector @@ plainto_tsquery('fr_unaccent', ?)", [$term]);

                // Repli trigramme : une référence de contrat ou un numéro de
                // facture n'est pas un mot, le stemmer ne l'indexe pas
                // utilement. pg_trgm couvre aussi les fautes de frappe sur
                // l'émetteur (index documents_issuer_trgm).
                $inner->orWhere('reference', 'ilike', '%'.$term.'%')
                    ->orWhere('issuer', 'ilike', '%'.$term.'%');
            }

            if ($embedding !== null) {
                $inner->orWhere(function (Builder $semantic) use ($embedding, $minSimilarity) {
                    // order: false — un ORDER BY posé dans un groupe de where
                    // imbriqué serait perdu à la fusion. Le tri sémantique se
                    // demande explicitement avec scopeOrderBySimilarity().
                    $semantic->whereNotNull('embedding')
                        ->whereVectorSimilarTo('embedding', $embedding, $minSimilarity, false);
                });
            }
        });
    }

    /**
     * Tri par distance cosinus croissante (le plus proche d'abord). Utilise
     * l'opérateur <=>, aligné sur l'index hnsw/vector_cosine_ops créé par
     * ->vectorIndex().
     *
     * @param  Builder<Document>  $query
     * @param  array<int, float>  $embedding
     * @return Builder<Document>
     */
    public function scopeOrderBySimilarity(Builder $query, array $embedding): Builder
    {
        return $query->whereNotNull('embedding')->orderByVectorDistance('embedding', $embedding);
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeOfStatus(Builder $query, DocumentStatus|string|null $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        return $query->where('status', $status instanceof DocumentStatus ? $status->value : $status);
    }

    /**
     * Filtre par catégorie, acceptant l'identifiant ou le slug — le front
     * manipule des slugs.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeInCategory(Builder $query, Category|int|string|null $category): Builder
    {
        if ($category === null || $category === '') {
            return $query;
        }

        if ($category instanceof Category) {
            return $query->where('category_id', $category->getKey());
        }

        if (is_int($category) || ctype_digit((string) $category)) {
            return $query->where('category_id', (int) $category);
        }

        return $query->whereHas('category', fn (Builder $q) => $q->where('slug', $category));
    }

    /**
     * Documents dont l'analyse est encore attendue (relance après un échec de
     * worker, ou reprise au démarrage).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeAwaitingAnalysis(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentStatus::Pending->value,
            DocumentStatus::Processing->value,
        ]);
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeNeedsEmbedding(Builder $query): Builder
    {
        return $query->whereNull('embedding')->where('status', DocumentStatus::Analyzed->value);
    }
}
