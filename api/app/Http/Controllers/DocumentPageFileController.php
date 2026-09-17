<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\DocumentPageResource;
use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service des images de pages.
 *
 * TROIS verrous, pas un seul :
 *  1. la signature de l'URL (middleware `signed:relative`) — une URL de page
 *     ne peut pas être forgée ni gardée indéfiniment ;
 *  2. la session (middleware `auth:sanctum`) — une URL signée qui fuirait
 *     hors de l'appareil ne servirait à rien sans le cookie ;
 *  3. la policy — le document doit appartenir à l'utilisateur connecté.
 *
 * Le disque « documents » n'est JAMAIS exposé en direct : c'est un disque
 * privé qui contient des factures, des bulletins de salaire et des courriers
 * médicaux.
 */
class DocumentPageFileController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentPage $page): StreamedResponse
    {
        $this->authorize('view', $document);

        // Ceinture et bretelles : la liaison de modèle est déjà « scopée »
        // sur la relation (scopeBindings), donc une page d'un autre document
        // est déjà un 404. On revérifie, c'est une comparaison d'entiers.
        abort_unless((string) $page->getAttribute('document_id') === (string) $document->getKey(), 404);

        $disk = Storage::disk('documents');

        $wantsThumb = $request->query('v') === 'thumb';
        $path = $wantsThumb ? (string) ($page->thumb_path ?? '') : (string) $page->storage_path;

        // La miniature est optionnelle (génération best effort) : on retombe
        // sur l'image pleine plutôt que de renvoyer un 404 à une balise <img>.
        if ($path === '' || ! $disk->exists($path)) {
            $path = (string) $page->storage_path;
        }

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            // L'URL est signée et expire : un cache privé de la durée de la
            // signature est exactement ce qu'il faut pour que le défilement
            // d'un document de 30 pages ne rappelle pas le serveur.
            'Cache-Control' => sprintf('private, max-age=%d, immutable', DocumentPageResource::URL_TTL_MINUTES * 60),
            'X-Content-Type-Options' => 'nosniff',
            // Une page de document n'a aucune raison d'être encadrée ailleurs.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }
}
