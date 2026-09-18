<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PersonResource;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Les personnes auxquelles l'utilisateur a attaché quelque chose.
 *
 * La liste n'est PAS l'annuaire des destinataires : elle ne contient que les
 * personnes dont une décision a été enregistrée (aujourd'hui, une photo). Le
 * front continue de calculer les groupes à partir des documents et se sert de
 * cette réponse pour y recoller les photos, par la clé de rapprochement.
 *
 * Pas de pagination : on parle du nombre de visages qu'un utilisateur a pris
 * la peine de choisir, pas du nombre de documents.
 */
class PersonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Person::class);

        $people = $request->user()
            ->people()
            ->orderBy('display_name')
            ->get();

        return PersonResource::collection($people);
    }
}
