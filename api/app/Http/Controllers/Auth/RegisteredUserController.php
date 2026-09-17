<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    /**
     * POST /api/register — création de compte puis connexion immédiate.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));

        event(new Registered($user));

        // Connexion sur le guard « web » : c'est la SESSION qui porte
        // l'identité (cookie), pas un token Bearer — ITP purge le stockage
        // client au bout de 7 jours sur iOS (ARCHITECTURE.md §2).
        Auth::guard('web')->login($user);

        /*
         | Régénération de l'identifiant de session APRÈS la connexion :
         | parade à la fixation de session. Elle réémet aussi le cookie
         | XSRF-TOKEN, que le front doit URL-décoder avant de le replacer dans
         | l'en-tête X-XSRF-TOKEN.
         |
         | hasSession() n'est pas de la superstition : Sanctum ne rend une
         | requête stateful (session + cookies + CSRF) que si elle porte un
         | en-tête Origin ou Referer correspondant à un domaine déclaré
         | (EnsureFrontendRequestsAreStateful::fromFrontend). Une requête sans
         | ces en-têtes — un curl, une sonde — n'a PAS de session, et
         | $request->session() lèverait « Session store not set on request »,
         | soit un 500 au lieu d'une réponse propre.
         */
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return UserResource::make($user)
            ->response()
            ->setStatusCode(201);
    }
}
