<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Création d'une catégorie personnelle.
 *
 * Le slug n'est pas demandé à l'utilisateur : il est dérivé du nom. C'est un
 * identifiant technique — manipulé par le filtre `?category=` et par le modèle
 * d'extraction — et le faire saisir reviendrait à exposer de la plomberie.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                // 60 : au-delà, le libellé ne tient plus sous une tuile.
                'max:60',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('Ce nom ne peut pas servir de catégorie.');

                        return;
                    }

                    /*
                     | Collision testée sur les catégories VISIBLES, donc les
                     | système comprises : deux « Facture » — l'une commune,
                     | l'autre personnelle — porteraient le même slug, et
                     | l'extraction ne saurait plus laquelle viser.
                     */
                    $taken = Category::query()
                        ->visibleTo($this->user())
                        ->where('slug', $slug)
                        ->exists();

                    if ($taken) {
                        $fail('Cette catégorie existe déjà.');
                    }
                },
            ],

            // Palette libre mais forme stricte : la couleur part telle quelle
            // dans un `background-color`.
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            /*
             | Le nom d'une icône, pas un tracé. La table des tracés vit dans le
             | front (lib/categories.ts) et y retombe sur « folder » quand le
             | nom lui est inconnu : dupliquer la liste ici créerait deux
             | sources de vérité qui divergeraient au premier ajout.
             */
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'color.regex' => 'La couleur doit être un code hexadécimal, par exemple #F97316.',
        ];
    }

    public function categoryName(): string
    {
        return trim((string) $this->input('name'));
    }

    public function slug(): string
    {
        return Str::slug($this->categoryName());
    }
}
