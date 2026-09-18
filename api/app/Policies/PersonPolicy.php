<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

/**
 * Isolation stricte par utilisateur, comme DocumentPolicy.
 *
 * Enregistrement par CONVENTION : Laravel résout App\Models\Person vers
 * App\Policies\PersonPolicy sans déclaration. Les contrôleurs appellent quand
 * même $this->authorize() explicitement — si la découverte échouait un jour,
 * l'échec serait un 403 bruyant, jamais une fuite silencieuse.
 *
 * La photo d'une personne est une donnée personnelle qui n'a aucune raison de
 * sortir du compte : deuxième ceinture, toutes les requêtes partent de
 * $user->people() ou d'un scope équivalent.
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    private function owns(User $user, Person $person): bool
    {
        return (int) $person->getAttribute('user_id') === (int) $user->getKey();
    }
}
