<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Extraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    protected $model = Extraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = fake()->numberBetween(1_500, 12_000);
        $output = fake()->numberBetween(200, 1_200);

        return [
            'document_id' => Document::factory(),
            'model' => 'gpt-5.1',
            'payload' => [
                'title' => fake()->sentence(4),
                'category' => 'facture',
                'summary' => fake()->sentence(12),
                'doc_date' => fake()->date(),
                'issuer' => 'EDF',
                'recipient' => fake()->name(),
                'total_amount' => fake()->randomFloat(2, 10, 900),
                'currency' => 'EUR',
                'reference' => mb_strtoupper(fake()->bothify('??-########')),
                'tags' => ['énergie', 'domicile'],
                'todos' => [],
            ],
            'input_tokens' => $input,
            'output_tokens' => $output,
            // Ordre de grandeur en micro-euros, cohérent avec la tarification
            // par million de tokens.
            'cost_micros' => $input * 2 + $output * 16,
            'created_at' => now(),
        ];
    }

    public function forDocument(Document $document): static
    {
        return $this->state(fn () => ['document_id' => $document->getKey()]);
    }
}
