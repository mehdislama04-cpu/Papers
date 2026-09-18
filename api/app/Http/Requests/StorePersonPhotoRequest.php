<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envoi de la photo d'une personne.
 *
 * La requête porte la personne ELLE-MÊME, pas seulement son image : la clé de
 * rapprochement, le nom affiché et les graphies rencontrées. C'est voulu — au
 * moment où l'utilisateur choisit une photo, la personne n'existe peut-être
 * pas encore en base, puisque jusqu'ici elle n'existait qu'à l'exécution dans
 * le navigateur. Le contrôleur fait donc un upsert.
 */
class StorePersonPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             | La clé vient du client (normalizeRecipient). Le serveur ne la
             | recalcule pas — il ne connaît pas la règle — mais il la borne :
             | c'est une chaîne stockée en index, pas un identifiant de
             | confiance. Elle n'ouvre l'accès à rien : la personne est de
             | toute façon rattachée à l'utilisateur connecté.
             */
            'key' => ['required', 'string', 'max:191'],

            'name' => ['required', 'string', 'max:255'],

            // Les graphies observées. Plafonnées : une personne qui en
            // présente vingt-cinq relève d'un bug de normalisation, pas d'un
            // usage réel.
            'aliases' => ['sometimes', 'array', 'max:25'],
            'aliases.*' => ['required', 'string', 'max:255'],

            /*
             | mimetypes: et non mimes: — l'extension envoyée par le sélecteur
             | de photos iOS ne veut rien dire. Mêmes types acceptés que pour
             | une page de document : ni HEIC ni WebP, pour la raison donnée
             | dans StoreDocumentRequest.
             */
            'photo' => [
                'required',
                'file',
                'mimetypes:'.implode(',', (array) config('papers.images.accepted_mimes', ['image/jpeg', 'image/png'])),
                'max:'.(int) config('papers.images.max_page_size_kb', 12288),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'La photo doit être une image JPEG ou PNG.',
            'photo.max' => 'La photo doit peser moins de :max Ko.',
        ];
    }

    public function matchKey(): string
    {
        return trim((string) $this->input('key'));
    }

    public function displayName(): string
    {
        return trim((string) $this->input('name'));
    }

    /** @return list<string> */
    public function aliases(): array
    {
        $aliases = array_map(
            static fn (mixed $raw): string => trim((string) $raw),
            (array) $this->input('aliases', []),
        );

        return array_values(array_unique(array_filter(
            $aliases,
            static fn (string $raw): bool => $raw !== '',
        )));
    }
}
