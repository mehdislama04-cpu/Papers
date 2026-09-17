<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TodoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(TodoStatus::values())],
            'due_before' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 50);
    }
}
