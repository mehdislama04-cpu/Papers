<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connexion du compte iCloud.
 *
 * Le mot de passe d'application (25 maximum par Apple Account, révocables) ne
 * doit apparaître NULLE PART ailleurs que dans la colonne chiffrée :
 *  - pas dans les logs (cf. bootstrap : `password` et `app_password` doivent
 *    rester hors des exceptions rapportées) ;
 *  - pas dans la réponse ;
 *  - jamais envoyé au navigateur en retour.
 */
class StoreCalendarAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'apple_id' => ['required', 'string', 'email:rfc', 'max:254'],

            /*
             | Format Apple : xxxx-xxxx-xxxx-xxxx (16 lettres minuscules, 3
             | tirets). On valide la FORME sans la durcir à l'excès : Apple a
             | déjà changé de format par le passé, et un compte refusé à tort
             | ici serait un cul-de-sac pour l'utilisateur. La vraie
             | vérification est le PROPFIND qui suit.
             */
            'app_password' => ['required', 'string', 'min:12', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($appleId = $this->input('apple_id'))) {
            $merge['apple_id'] = mb_strtolower(trim($appleId));
        }

        if (is_string($password = $this->input('app_password'))) {
            // Le champ « mot de passe d'application » est copié-collé depuis
            // appleid.apple.com : espaces et espaces insécables inclus.
            $merge['app_password'] = trim(str_replace(["\u{00A0}", ' '], '', $password));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'app_password.min' => "Ce n'est pas un mot de passe d'application Apple (format xxxx-xxxx-xxxx-xxxx).",
        ];
    }
}
