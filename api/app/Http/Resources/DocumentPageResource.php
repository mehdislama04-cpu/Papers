<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DocumentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin DocumentPage
 */
class DocumentPageResource extends JsonResource
{
    /**
     * Durée de vie d'une URL de page. Assez longue pour lire un document de
     * 30 pages sans que les images expirent en cours de route, assez courte
     * pour qu'une URL copiée dans un historique ou un presse-papiers ne soit
     * pas un accès permanent.
     */
    public const URL_TTL_MINUTES = 60;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'page_number' => $this->page_number,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,

            // Jamais de chemin de stockage ni d'URL directe vers le disque :
            // le fichier est servi par une route signée qui repasse par la
            // policy (cf. DocumentPageFileController).
            'file_url' => $this->signedUrl(),
            'thumb_url' => $this->thumb_path !== null ? $this->signedUrl('thumb') : null,

            'expires_at' => now()->addMinutes(self::URL_TTL_MINUTES)->toAtomString(),
        ];
    }

    /**
     * URL signée RELATIVE.
     *
     * Relative et non absolue : en dev l'app est atteinte par un tunnel dont
     * l'hôte change à chaque session. Une signature absolue calculée sur
     * localhost:8000 serait rejetée quand la requête arrive sur le tunnel
     * (403), alors qu'une signature relative ne couvre que le chemin et la
     * query. Le middleware correspondant est `signed:relative`.
     */
    private function signedUrl(?string $variant = null): string
    {
        $parameters = [
            'document' => $this->document_id,
            'page' => $this->getKey(),
        ];

        if ($variant !== null) {
            $parameters['v'] = $variant;
        }

        return URL::temporarySignedRoute(
            'documents.pages.file',
            now()->addMinutes(self::URL_TTL_MINUTES),
            $parameters,
            absolute: false,
        );
    }
}
