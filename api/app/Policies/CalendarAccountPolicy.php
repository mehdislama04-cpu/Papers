<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CalendarAccount;
use App\Models\User;

/**
 * Un seul compte iCloud par utilisateur (contrainte unique sur user_id).
 *
 * Le mot de passe d'application n'est jamais sérialisé (attribut Hidden sur le
 * modèle) ni journalisé : cette policy ne protège que l'accès à l'objet.
 */
class CalendarAccountPolicy
{
    public function view(User $user, CalendarAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CalendarAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    public function delete(User $user, CalendarAccount $account): bool
    {
        return $this->owns($user, $account);
    }

    private function owns(User $user, CalendarAccount $account): bool
    {
        return (int) $account->getAttribute('user_id') === (int) $user->getKey();
    }
}
