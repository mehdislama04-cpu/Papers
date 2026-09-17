<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TodoStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * PATCH partiel : seuls les champs présents sont modifiés. D'où `sometimes`
 * partout — un champ absent ne doit jamais être interprété comme un null.
 */
class UpdateTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise `due_at` en instant UTC avant que le cast Eloquent n'y touche.
     *
     * Le cast `datetime` de Laravel parse les chaînes avec le format de date du
     * modèle et IGNORE un décalage explicite : "2026-10-15T08:00:00+02:00"
     * devient 08:00 UTC au lieu de 06:00 UTC. L'erreur est silencieuse et vaut
     * deux heures — assez pour qu'un rappel « avant 9 h » arrive après.
     *
     * Une chaîne en Z ou une date seule passent déjà correctement ; on les
     * renormalise quand même pour n'avoir qu'un seul chemin.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('due_at')) {
            return;
        }

        $value = $this->input('due_at');

        if ($value === null || $value === '') {
            $this->merge(['due_at' => null]);

            return;
        }

        if (! is_string($value)) {
            return;
        }

        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (Throwable) {
            // On laisse la règle `date` produire l'erreur de validation.
            return;
        }

        // Une date seule reste une date seule : la convertir en UTC la
        // décalerait d'un jour pour les fuseaux à l'est de Greenwich.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return;
        }

        $this->merge(['due_at' => $parsed->utc()->format('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'details' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // nullable : retirer l'échéance est une action légitime, et c'est
            // elle qui déclenche la SUPPRESSION de l'événement iCloud.
            'due_at' => ['sometimes', 'nullable', 'date'],
            'all_day' => ['sometimes', 'boolean'],

            // smallint en base : on borne pour ne pas prendre un 22003.
            'priority' => ['sometimes', 'integer', 'between:-2,2'],

            'status' => ['sometimes', Rule::enum(TodoStatus::class)],
        ];
    }

    /**
     * Attributs réellement à écrire, dans l'ordre des clés reçues.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->safe()->only(['title', 'details', 'due_at', 'all_day', 'priority', 'status']);
    }
}
