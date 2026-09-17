<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:96'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            // `confirmed` attend password_confirmation. Password::defaults()
            // est configurable par environnement sans toucher au contrôleur.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            // Les claviers iOS mettent une majuscule en début de champ.
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
