<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PersonResource;
use App\Models\Person;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service de la photo d'une personne.
 *
 * Mêmes trois verrous que pour une page de document, et pour une raison au
 * moins aussi bonne : c'est le visage de quelqu'un.
 *  1. la signature de l'URL (`signed:relative`) — elle ne peut pas être forgée
 *     ni gardée indéfiniment ;
 *  2. la session (`auth:sanctum`) — une URL qui fuirait hors de l'appareil ne
 *     servirait à rien sans le cookie ;
 *  3. la policy — la personne doit appartenir à l'utilisateur connecté.
 */
class PersonPhotoFileController extends Controller
{
    private const DISK = 'documents';

    public function __invoke(Person $person): StreamedResponse
    {
        $this->authorize('view', $person);

        abort_unless($person->hasPhoto(), 404);

        $disk = Storage::disk(self::DISK);
        $path = (string) $person->photo_path;

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            // L'URL est signée, expire, et porte l'horodatage de la photo :
            // un cache privé de la durée de la signature est exactement ce
            // qu'il faut pour que la liste des personnes ne rappelle pas le
            // serveur à chaque défilement.
            'Cache-Control' => sprintf('private, max-age=%d, immutable', PersonResource::URL_TTL_MINUTES * 60),
            'X-Content-Type-Options' => 'nosniff',
            // Un visage n'a aucune raison d'être encadré ailleurs.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }
}
