<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin Person
 */
class PersonResource extends JsonResource
{
    /**
     * Même durée que pour une page de document : assez pour parcourir la liste
     * des personnes sans que les vignettes expirent, assez court pour qu'une
     * URL qui fuirait ne soit pas un accès permanent à un visage.
     */
    public const URL_TTL_MINUTES = 60;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),

            // La clé de rapprochement : c'est par elle que le front recolle
            // cette ligne au groupe qu'il a calculé de son côté.
            'key' => $this->match_key,
            'name' => $this->display_name,

            // Jamais de chemin de stockage : le fichier passe par une route
            // signée qui repasse par la policy (cf. PersonPhotoFileController).
            'photo_url' => $this->hasPhoto() ? $this->signedPhotoUrl() : null,
            'photo_updated_at' => $this->photo_updated_at?->toAtomString(),

            'expires_at' => $this->hasPhoto()
                ? now()->addMinutes(self::URL_TTL_MINUTES)->toAtomString()
                : null,
        ];
    }

    /**
     * URL signée RELATIVE, pour la même raison que les pages : en dev l'app
     * est atteinte par un tunnel dont l'hôte change à chaque session, et une
     * signature absolue serait rejetée dès que l'hôte diffère.
     */
    private function signedPhotoUrl(): string
    {
        return URL::temporarySignedRoute(
            'people.photo.file',
            now()->addMinutes(self::URL_TTL_MINUTES),
            [
                'person' => $this->getKey(),
                // La date de la photo casse le cache du navigateur quand
                // l'utilisateur la remplace : sans elle, l'URL signée change
                // mais l'image affichée resterait l'ancienne jusqu'à
                // expiration du cache privé.
                'v' => $this->photo_updated_at?->getTimestamp() ?? 0,
            ],
            absolute: false,
        );
    }
}
