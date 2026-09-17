<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use App\Models\DocumentPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Écriture des pages d'un document sur le disque privé « documents ».
 *
 * Partagé par l'upload depuis la PWA (DocumentController::store) et par
 * l'ingestion du raccourci iOS (ShortcutIngestController) : les deux doivent
 * produire exactement la même arborescence, sinon les URLs signées et la
 * suppression divergent.
 *
 * Arborescence : user/{user_id}/{document_uuid}/page-01.jpg
 *                user/{user_id}/{document_uuid}/thumb-01.webp
 *
 * Le nom de fichier est TOUJOURS calculé ici. Le filename fourni par le
 * client n'est jamais utilisé comme chemin (Raccourcis envoie « Scanned
 * Document.pdf », le sélecteur iOS envoie « image.jpg », et un client
 * malveillant enverrait « ../../.env »).
 */
trait StoresDocumentPages
{
    /** Disque privé, jamais exposé en direct. */
    protected const DISK = 'documents';

    /**
     * Garde-fou mémoire pour la miniature : GD décompresse en RGBA, soit
     * environ 4 octets par pixel. 40 Mpx ≈ 160 Mo, déjà proche du
     * memory_limit d'un PHP par défaut. Au-delà, on préfère un document sans
     * miniature à un 500.
     */
    protected const THUMBNAIL_MAX_PIXELS = 40_000_000;

    protected function documentDirectory(Document $document): string
    {
        return sprintf('user/%d/%s', (int) $document->getAttribute('user_id'), (string) $document->getKey());
    }

    /**
     * Range une page et crée la ligne document_pages correspondante.
     *
     * @param  UploadedFile|string  $source  fichier uploadé, ou chemin local absolu (page rasterisée d'un PDF)
     *
     * @throws ValidationException si l'image est illisible
     */
    protected function storeDocumentPage(
        Document $document,
        int $pageNumber,
        UploadedFile|string $source,
        ?string $mime = null,
    ): DocumentPage {
        $path = $source instanceof UploadedFile ? (string) $source->getRealPath() : $source;

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'pages' => "La page {$pageNumber} n'a pas pu être lue.",
            ]);
        }

        $mime ??= $source instanceof UploadedFile
            ? (string) $source->getMimeType()
            : (string) (@mime_content_type($path) ?: 'image/jpeg');

        [$width, $height] = $this->imageDimensions($path, $pageNumber);

        $directory = $this->documentDirectory($document);
        $filename = sprintf('page-%02d.%s', $pageNumber, $mime === 'image/png' ? 'png' : 'jpg');
        $storagePath = $directory.'/'.$filename;

        // writeStream : un scan de 12 Mo ne passe pas par une string PHP.
        // Le disque est configuré 'throw' => true, une écriture ratée lève.
        $stream = fopen($path, 'rb');

        try {
            Storage::disk(self::DISK)->writeStream($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        /** @var DocumentPage $page */
        $page = $document->pages()->create([
            'page_number' => $pageNumber,
            'storage_path' => $storagePath,
            'thumb_path' => null,
            'width' => $width,
            'height' => $height,
            'bytes' => (int) (filesize($path) ?: 0),
            // sha256 du fichier tel que reçu : sert à repérer un doublon de
            // scan et à tracer une page sans la relire.
            'checksum' => (string) hash_file('sha256', $path),
        ]);

        if (($thumbPath = $this->makeThumbnail($directory, $pageNumber, $page)) !== null) {
            $page->forceFill(['thumb_path' => $thumbPath])->save();
        }

        return $page;
    }

    /**
     * Miniature WebP, générée dans la requête.
     *
     * La doc conseille de faire ça dans un job ; on la génère ici quand même
     * parce que la PWA affiche la vignette IMMÉDIATEMENT après l'upload, avant
     * que le worker n'ait analysé quoi que ce soit. C'est un redimensionnement
     * de quelques dizaines de millisecondes, pas un appel réseau — et un échec
     * n'est jamais bloquant : thumb_path reste null, le front retombe sur
     * l'image pleine.
     */
    protected function makeThumbnail(string $directory, int $pageNumber, DocumentPage $page): ?string
    {
        if (((int) $page->width * (int) $page->height) > self::THUMBNAIL_MAX_PIXELS) {
            Log::warning('Miniature ignorée : image trop grande pour la mémoire disponible', [
                'document_page_id' => $page->getKey(),
                'width' => $page->width,
                'height' => $page->height,
            ]);

            return null;
        }

        $this->warnIfExifMissing();

        try {
            $stored = Image::fromStorage((string) $page->storage_path, self::DISK)
                /*
                 | ->orient() : INDISPENSABLE.
                 |
                 | Une photo d'iPhone est presque toujours stockée dans
                 | l'orientation du capteur, la rotation réelle n'étant portée
                 | que par le tag EXIF Orientation. Sans cet appel, toutes les
                 | miniatures sortent couchées (ARCHITECTURE.md §4).
                 */
                ->orient()
                ->scale(width: (int) config('papers.images.thumbnail_width', 400))
                ->toWebp()
                ->quality((int) config('papers.images.thumbnail_quality', 70))
                ->storeAs($directory, sprintf('thumb-%02d.webp', $pageNumber), self::DISK);

            return is_string($stored) ? $stored : null;
        } catch (Throwable $e) {
            Log::warning('Miniature non générée', [
                'document_page_id' => $page->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ->orient() lit le tag EXIF via exif_read_data(). Sans l'extension exif,
     * Intervention renvoie une collection EXIF VIDE et orient() devient un
     * no-op SILENCIEUX : les miniatures iPhone sortent couchées sans la
     * moindre erreur. On le dit une fois par processus.
     */
    private function warnIfExifMissing(): void
    {
        static $warned = false;

        if ($warned || extension_loaded('exif')) {
            return;
        }

        $warned = true;

        Log::warning(
            "L'extension PHP « exif » est absente : ->orient() ne fait rien et les miniatures "
            .'iPhone seront couchées. Activer extension=exif dans php.ini.'
        );
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws ValidationException
     */
    protected function imageDimensions(string $path, int $pageNumber): array
    {
        $size = @getimagesize($path);

        if ($size === false || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            throw ValidationException::withMessages([
                'pages' => "La page {$pageNumber} n'est pas une image exploitable.",
            ]);
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Supprime l'arborescence d'un document. Appelé en rattrapage quand
     * l'enregistrement échoue à mi-chemin : on ne laisse pas de fichiers
     * orphelins sur un disque qui contient des bulletins de salaire.
     */
    protected function purgeDocumentFiles(Document $document): void
    {
        try {
            Storage::disk(self::DISK)->deleteDirectory($this->documentDirectory($document));
        } catch (Throwable $e) {
            Log::warning('Nettoyage des fichiers du document impossible', [
                'document_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
