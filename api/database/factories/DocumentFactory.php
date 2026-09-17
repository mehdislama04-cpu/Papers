<?php

namespace Database\Factories;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuer = fake()->randomElement([
            'EDF', 'Orange', 'CPAM', 'Direction générale des Finances publiques',
            'MAIF', 'Crédit Mutuel', 'Free', 'Urssaf',
        ]);

        return [
            'user_id' => User::factory(),
            'category_id' => null,
            'title' => fake()->randomElement(['Facture', 'Relevé', 'Avis', 'Attestation', 'Contrat']).' '.$issuer,
            'status' => DocumentStatus::Pending,
            'source' => DocumentSource::Scanner,
            'original_filename' => null,
            'page_count' => 1,
            'language' => 'fr',
            'summary' => null,
            'doc_date' => null,
            'issuer' => null,
            'recipient' => null,
            'total_amount' => null,
            'currency' => null,
            'reference' => null,
            'raw_text' => null,
            'analysis_error' => null,
            'analyzed_at' => null,
            // embedding laissé nul : 1536 flottants par enregistrement
            // alourdiraient inutilement chaque test. Voir ->withEmbedding().
            'embedding' => null,
        ];
    }

    /**
     * Document analysé, avec les champs que remplit l'extraction.
     */
    public function analyzed(): static
    {
        return $this->state(function (array $attributes) {
            $issuer = fake()->randomElement(['EDF', 'Orange', 'CPAM', 'MAIF', 'Urssaf']);

            return [
                'status' => DocumentStatus::Analyzed,
                'summary' => fake()->sentence(14),
                'doc_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
                'issuer' => $issuer,
                'recipient' => fake()->name(),
                'total_amount' => fake()->randomFloat(2, 5, 2500),
                'currency' => 'EUR',
                'reference' => mb_strtoupper(fake()->bothify('??-########')),
                'raw_text' => fake()->paragraphs(4, true),
                'analyzed_at' => now(),
            ];
        });
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Processing]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Failed,
            'analysis_error' => 'Le modèle a refusé de répondre (content part de type refusal).',
        ]);
    }

    /**
     * Provenance raccourci iOS. Le PDF reçu s'appelle presque toujours
     * « Scanned Document.pdf » : on ne fait jamais confiance à ce nom.
     */
    public function fromShortcut(): static
    {
        return $this->state(fn () => [
            'source' => DocumentSource::Shortcut,
            'original_filename' => 'Scanned Document.pdf',
        ]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->getKey()]);
    }

    /**
     * Embedding factice de 1536 dimensions, normalisé — la distance cosinus
     * n'a de sens que sur des vecteurs de norme comparable.
     */
    public function withEmbedding(): static
    {
        return $this->state(function () {
            $vector = [];
            for ($i = 0; $i < 1536; $i++) {
                $vector[] = mt_rand(-1000, 1000) / 1000;
            }

            $norm = sqrt(array_sum(array_map(fn (float $v): float => $v ** 2, $vector))) ?: 1.0;

            return ['embedding' => array_map(fn (float $v): float => $v / $norm, $vector)];
        });
    }

    public function multiPage(int $pages = 3): static
    {
        return $this->state(fn () => ['page_count' => $pages]);
    }
}
