<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nom d'une personne, choisi à la main.
 *
 * Comme pour la photo, la requête porte la personne entière : au moment où
 * l'utilisateur renomme, elle n'existe peut-être pas encore en base. Le nom
 * envoyé ici est FIGÉ — il ne sera plus jamais réécrit depuis un document.
 */
class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:191'],

            // Un nom vide ferait disparaître la personne de sa propre liste.
            'name' => ['required', 'string', 'min:1', 'max:255'],

            'aliases' => ['sometimes', 'array', 'max:25'],
            'aliases.*' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom ne peut pas être vide.',
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
