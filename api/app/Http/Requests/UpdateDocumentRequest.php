<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Déplacement d'un document.
 *
 * L'analyse classe toute seule, et se trompe parfois : une facture de garage
 * peut atterrir dans « Facture » quand son propriétaire la range dans
 * « Véhicule ». Sans ce geste, la seule correction possible était de relancer
 * l'analyse en espérant un autre résultat.
 *
 * `category` accepte explicitement `null` : « sans catégorie » est un état
 * légitime, pas une absence de réponse.
 */
class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => [
                'present',
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    // Visible, donc : système, ou personnelle à CET utilisateur.
                    // Un slug appartenant au voisin ne doit pas être atteignable.
                    $exists = Category::query()
                        ->visibleTo($this->user())
                        ->where('slug', (string) $value)
                        ->exists();

                    if (! $exists) {
                        $fail('Cette catégorie n’existe pas.');
                    }
                },
            ],
        ];
    }

    public function categorySlug(): ?string
    {
        $slug = trim((string) $this->input('category', ''));

        return $slug === '' ? null : $slug;
    }
}
