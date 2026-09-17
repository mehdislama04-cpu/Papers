<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toAtomString(),

            // Chargé uniquement par GET /api/me : le front a besoin de savoir
            // s'il doit proposer l'onboarding calendrier. Le mot de passe
            // d'application n'apparaît jamais (attribut Hidden sur le modèle
            // ET absent de CalendarAccountResource).
            // NB : on teste explicitement la relation plutôt que d'envelopper
            // whenLoaded() dans la ressource. Un hasOne chargé mais VIDE vaut
            // null, et CalendarAccountResource::make(null) déréférencerait un
            // null à la sérialisation.
            'calendar_account' => $this->when(
                $this->relationLoaded('calendarAccount') && $this->calendarAccount !== null,
                fn () => CalendarAccountResource::make($this->calendarAccount),
            ),
        ];
    }
}
