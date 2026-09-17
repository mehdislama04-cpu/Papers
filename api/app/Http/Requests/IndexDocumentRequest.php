<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Identifiant numérique OU slug : scopeInCategory() gère les deux,
            // le front manipule des slugs.
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', Rule::in(DocumentStatus::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // Permet au front de couper la recherche sémantique (qui coûte un
            // appel embeddings) quand il ne veut qu'un filtre rapide.
            'semantic' => ['sometimes', 'boolean'],
        ];
    }

    public function searchTerm(): string
    {
        return trim((string) $this->input('search', ''));
    }

    public function perPage(): int
    {
        $default = (int) config('papers.search.per_page', 20);

        return (int) $this->integer('per_page', $default > 0 ? $default : 20);
    }

    public function wantsSemantic(): bool
    {
        return $this->boolean('semantic', true);
    }
}
