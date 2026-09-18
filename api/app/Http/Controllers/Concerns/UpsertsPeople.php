<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Création ou mise à jour d'une personne à partir de sa clé de rapprochement.
 *
 * Partagé par le renommage et par l'envoi de photo : les deux gestes peuvent
 * porter sur une personne qui n'existe pas encore en base, puisque jusqu'ici
 * elle ne vivait que dans le navigateur, déduite des destinataires. C'est le
 * geste de l'utilisateur qui la fait exister, jamais l'analyse d'un document.
 */
trait UpsertsPeople
{
    /**
     * @param  list<string>  $aliases  graphies rencontrées, telles qu'imprimées
     * @param  bool  $chosenByHand  le nom vient-il de l'utilisateur, ou des documents ?
     */
    protected function upsertPerson(
        User $user,
        string $matchKey,
        string $name,
        array $aliases,
        bool $chosenByHand,
    ): Person {
        /*
         | Transaction : la personne et ses graphies doivent atterrir ensemble.
         | Une personne sans ses alias perdrait le filet qui permet de rejouer
         | un changement de normalisation.
         */
        return DB::transaction(function () use ($user, $matchKey, $name, $aliases, $chosenByHand): Person {
            /** @var Person $person */
            $person = $user->people()->firstOrNew(['match_key' => $matchKey]);

            if ($chosenByHand) {
                $person->display_name = $name;
                $person->name_overridden = true;
            } elseif ($person->followsDocuments()) {
                // Le nom n'a pas été choisi : il suit la graphie la plus
                // fréquente, qui a pu changer depuis la dernière fois.
                $person->display_name = $name;
            }

            $person->save();

            foreach ($aliases as $raw) {
                $person->aliases()->firstOrCreate(['raw' => $raw]);
            }

            return $person;
        });
    }
}
