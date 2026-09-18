<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Les douze catégories système sont intouchables.
 *
 * Elles forment le socle commun à tous les comptes ET le vocabulaire fermé du
 * modèle d'extraction : en supprimer une casserait le classement automatique
 * pour tout le monde, pas seulement pour celui qui a cliqué. Seules les
 * catégories personnelles, créées à la main, se suppriment — et uniquement par
 * leur auteur.
 *
 * Enregistrement par CONVENTION, comme DocumentPolicy et PersonPolicy.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        // Système : visible par tout le monde. Personnelle : par son auteur.
        return $category->isSystem() || $this->owns($user, $category);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    private function owns(User $user, Category $category): bool
    {
        return $category->user_id !== null
            && (int) $category->user_id === (int) $user->getKey();
    }
}
