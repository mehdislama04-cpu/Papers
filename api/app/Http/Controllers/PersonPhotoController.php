<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UpsertsPeople;
use App\Http\Requests\StorePersonPhotoRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Photo d'une personne.
 *
 * L'envoi crée la personne au passage si elle n'existait pas : jusqu'ici elle
 * ne vivait que dans le navigateur, déduite des destinataires. C'est le geste
 * de l'utilisateur qui la fait exister en base, pas l'analyse d'un document.
 */
class PersonPhotoController extends Controller
{
    use UpsertsPeople;

    /** Disque privé, jamais exposé en direct — le même que les pages. */
    private const DISK = 'documents';

    public function store(StorePersonPhotoRequest $request): PersonResource
    {
        $user = $request->user();

        $this->authorize('create', Person::class);

        /*
         | Le nom part en `chosenByHand: false` : il vient des documents, donc
         | il ne doit PAS écraser un nom que l'utilisateur aurait choisi. Sans
         | cette distinction, ajouter une photo ferait revenir « M. JEAN
         | DUPONT » sur une personne renommée « Papa ».
         */
        $person = $this->upsertPerson(
            $user,
            $request->matchKey(),
            $request->displayName(),
            $request->aliases(),
            chosenByHand: false,
        );

        $this->authorize('update', $person);

        $person->forceFill([
            'photo_path' => $this->writePhoto($person, $request),
            'photo_updated_at' => now(),
        ])->save();

        return new PersonResource($person);
    }

    public function destroy(Person $person): JsonResponse
    {
        $this->authorize('delete', $person);

        if ($person->hasPhoto()) {
            Storage::disk(self::DISK)->delete((string) $person->photo_path);
        }

        /*
         | On supprime la photo, pas la personne : ses graphies restent, et
         | avec elles la possibilité de rejouer un changement de normalisation.
         | Une ligne sans photo ne coûte rien et ne s'affiche nulle part — le
         | front retombe sur le monogramme.
         */
        $person->forceFill([
            'photo_path' => null,
            'photo_updated_at' => null,
        ])->save();

        return response()->json(status: 204);
    }

    /**
     * Écrit la photo et renvoie son chemin sur le disque privé.
     *
     * Nom de fichier déterministe : remplacer une photo écrase la précédente,
     * il n'y a donc jamais de fichier orphelin à ramasser. C'est l'horodatage
     * `photo_updated_at`, repris en query de l'URL signée, qui casse le cache
     * du navigateur.
     *
     * @throws ValidationException si l'image est illisible
     */
    private function writePhoto(Person $person, StorePersonPhotoRequest $request): string
    {
        $directory = sprintf('user/%d/people', (int) $person->getAttribute('user_id'));
        $size = (int) config('papers.images.avatar_size', 320);

        try {
            $stored = Image::fromUpload($request->file('photo'))
                /*
                 | ->orient() : indispensable, exactement comme pour les pages.
                 | Une photo d'iPhone est stockée dans l'orientation du capteur,
                 | la rotation réelle n'étant portée que par le tag EXIF. Sans
                 | cet appel, un portrait pris à la verticale sort couché.
                 */
                ->orient()
                /*
                 | cover() et non scale() : une pastille est ronde et carrée
                 | par construction. Redimensionner sans recadrer donnerait un
                 | rectangle que le CSS rognerait de toute façon — autant ne
                 | pas stocker les pixels qu'on ne montrera jamais.
                 */
                ->cover($size, $size)
                ->toJpeg()
                ->quality((int) config('papers.images.avatar_quality', 82))
                ->storeAs($directory, $person->getKey().'.jpg', self::DISK);
        } catch (Throwable $e) {
            Log::warning('Photo de personne illisible', [
                'person_id' => $person->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'photo' => "Cette image n'a pas pu être lue.",
            ]);
        }

        if (! is_string($stored) || $stored === '') {
            throw ValidationException::withMessages([
                'photo' => "La photo n'a pas pu être enregistrée.",
            ]);
        }

        return $stored;
    }
}
