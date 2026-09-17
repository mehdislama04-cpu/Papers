<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeController extends Controller
{
    /**
     * GET /api/me
     *
     * Point d'entrée de la PWA au démarrage : un 200 signifie « session
     * valide », un 401 « il faut se connecter ». C'est aussi ce qui permet de
     * savoir si l'onboarding calendrier doit être proposé, sans une requête
     * de plus.
     *
     * Ne doit JAMAIS être mis en cache par le service worker.
     */
    public function __invoke(Request $request): JsonResource
    {
        return UserResource::make(
            $request->user()->loadMissing('calendarAccount')
        );
    }
}
