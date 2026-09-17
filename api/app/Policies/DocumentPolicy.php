<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Isolation stricte par utilisateur.
 *
 * Enregistrement : par CONVENTION. Laravel résout App\Models\Document vers
 * App\Policies\DocumentPolicy sans déclaration (Gate::guessPolicyNamesUsing).
 * Aucune ligne n'est donc ajoutée dans AppServiceProvider — ce fichier
 * appartient à un autre lot, et le faire dépendre de celui-ci serait un
 * couplage inutile. Les contrôleurs appellent quand même $this->authorize()
 * explicitement : si la découverte échouait un jour, l'échec serait un 403
 * bruyant, jamais une fuite silencieuse.
 *
 * Deuxième ceinture : toutes les requêtes partent de $user->documents(), donc
 * un document d'un tiers n'entre même pas dans le jeu de résultats.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    private function owns(User $user, Document $document): bool
    {
        // Comparaison lâche volontairement évitée : user_id est un entier,
        // getKey() aussi. Un === sur des types cohérents est le bon test.
        return (int) $document->getAttribute('user_id') === (int) $user->getKey();
    }
}
