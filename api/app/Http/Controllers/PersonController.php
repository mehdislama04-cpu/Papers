<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UpsertsPeople;
use App\Http\Requests\StorePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Les personnes auxquelles l'utilisateur a attaché quelque chose.
 *
 * La liste n'est PAS l'annuaire des destinataires : elle ne contient que les
 * personnes dont une décision a été enregistrée — un nom choisi, une photo. Le
 * front continue de calculer les groupes à partir des documents et se sert de
 * cette réponse pour y recoller ce qui a été décidé, par la clé de
 * rapprochement.
 *
 * Pas de pagination : on parle du nombre de personnes qu'un utilisateur a pris
 * la peine de renommer ou d'illustrer, pas du nombre de documents.
 */
class PersonController extends Controller
{
    use UpsertsPeople;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Person::class);

        $people = $request->user()
            ->people()
            ->orderBy('display_name')
            ->get();

        return PersonResource::collection($people);
    }

    /**
     * Renomme une personne — et la crée si elle n'existait pas encore.
     *
     * Le nom devient définitivement celui de l'utilisateur : `name_overridden`
     * passe à vrai et aucun document ne le réécrira plus.
     */
    public function store(StorePersonRequest $request): PersonResource
    {
        $this->authorize('create', Person::class);

        $person = $this->upsertPerson(
            $request->user(),
            $request->matchKey(),
            $request->displayName(),
            $request->aliases(),
            chosenByHand: true,
        );

        return new PersonResource($person);
    }
}
