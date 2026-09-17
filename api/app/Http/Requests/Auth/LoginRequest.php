<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
            // Sans objet ici : la session Sanctum dure SESSION_LIFETIME
            // (30 jours en .env). On accepte quand même le champ pour ne pas
            // faire échouer un client qui l'enverrait.
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * Authentifie l'utilisateur sur le guard « web » : c'est la SESSION qui
     * porte l'identité, pas un token Bearer (ITP purge le stockage client au
     * bout de 7 jours sur iOS — cf. ARCHITECTURE.md §2).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // Message unique : ne jamais distinguer « email inconnu » de
            // « mot de passe faux », c'est un oracle d'énumération de comptes.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** @throws ValidationException */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Clé par couple (email, IP) : un attaquant qui pilonne un compte depuis
     * une IP est bloqué sans bloquer le vrai propriétaire depuis la sienne.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
