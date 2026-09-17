<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentPage>
 */
class DocumentPageFactory extends Factory
{
    protected $model = DocumentPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Résolution capteur d'un iPhone 14 Plus en 4:3, ce que renvoie
        // l'appareil photo natif après redressement.
        $width = 3024;
        $height = 4032;

        $id = Str::uuid7()->toString();

        return [
            'document_id' => Document::factory(),
            'page_number' => 1,
            'storage_path' => "documents/{$id}/page-1.jpg",
            'thumb_path' => "documents/{$id}/page-1-thumb.jpg",
            'width' => $width,
            'height' => $height,
            'bytes' => fake()->numberBetween(400_000, 2_500_000),
            'checksum' => hash('sha256', $id),
        ];
    }

    public function page(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'page_number' => $number,
            'storage_path' => str_replace('page-1.jpg', "page-{$number}.jpg", $attributes['storage_path']),
            'thumb_path' => str_replace('page-1-thumb.jpg', "page-{$number}-thumb.jpg", (string) $attributes['thumb_path']),
        ]);
    }

    public function withoutThumb(): static
    {
        return $this->state(fn () => ['thumb_path' => null]);
    }
}
