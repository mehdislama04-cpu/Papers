<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

/**
 * Isolation stricte par utilisateur (découverte par convention, cf.
 * DocumentPolicy).
 */
class TodoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Todo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Todo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    public function delete(User $user, Todo $todo): bool
    {
        return $this->owns($user, $todo);
    }

    private function owns(User $user, Todo $todo): bool
    {
        return (int) $todo->getAttribute('user_id') === (int) $user->getKey();
    }
}
