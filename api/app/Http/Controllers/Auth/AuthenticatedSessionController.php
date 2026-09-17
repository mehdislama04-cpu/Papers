<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * POST /api/login
     *
     * Le client DOIT avoir appelé GET /sanctum/csrf-cookie avant : c'est ce
     * qui pose le cookie XSRF-TOKEN, dont la valeur URL-DÉCODÉE doit repartir
     * dans l'en-tête X-XSRF-TOKEN (fetch ne le fait pas, contrairement à
     * axios). Sans cela : 419, pas 401.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        // Cf. RegisteredUserController : une requête que Sanctum n'a pas
        // rendue stateful (ni Origin ni Referer connus) n'a pas de session ;
        // y toucher lèverait un 500.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return UserResource::make(
            $request->user()->loadMissing('calendarAccount')
        )->response();
    }

    /**
     * POST /api/logout
     *
     * Invalidation complète : la session est détruite ET le jeton CSRF
     * régénéré. Une 419 ultérieure côté front doit déclencher un re-fetch de
     * /sanctum/csrf-cookie puis UN retry — jamais une déconnexion en boucle.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
