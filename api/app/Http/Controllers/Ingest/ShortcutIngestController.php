<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ingest;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Concerns\RasterizesPdf;
use App\Http\Controllers\Concerns\StoresDocumentPages;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Jobs\AnalyzeDocument;
use App\Models\Document;
use App\Models\IngestToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pont « scanner natif Apple » : réception du PDF produit par le raccourci iOS.
 *
 * PAS de auth:sanctum ici, et c'est délibéré : la requête vient de
 * l'application Raccourcis, pas du navigateur — elle ne porte ni cookie de
 * session ni jeton CSRF. L'authentification repose entièrement sur le jeton à
 * usage unique émis par POST /api/ingest/token.
 *
 * Tout ce qui arrive ici est hostile par défaut :
 *  - le filename (« Scanned Document.pdf » dans le meilleur des cas, une
 *    traversée de répertoire dans le pire) n'est jamais utilisé ;
 *  - le type est déduit du CONTENU, pas de l'extension ;
 *  - le jeton est consommé atomiquement, une seule fois.
 */
class ShortcutIngestController extends Controller
{
    use RasterizesPdf;
    use StoresDocumentPages;

    public function __invoke(Request $request): JsonResponse
    {
        $plain = $request->bearerToken();

        abort_if(blank($plain), 401, 'Jeton d’ingestion manquant.');

        $token = IngestToken::query()
            // Comparaison sur le HASH : la base ne contient pas le jeton en
            // clair, et l'index unique évite toute recherche linéaire (donc
            // toute attaque temporelle).
            ->where('token_hash', IngestToken::hashFor($plain))
            ->first();

        abort_if($token === null || ! $token->isUsable(), 401, 'Jeton d’ingestion invalide ou expiré.');

        // Validation AVANT consommation : un PDF refusé ne doit pas griller le
        // jeton, sinon l'utilisateur doit repasser par la PWA pour un simple
        // fichier trop lourd. La validation ne révèle rien à qui n'a pas déjà
        // le jeton.
        $validated = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', (array) config('papers.ingest.accepted_mimes', ['application/pdf', 'image/jpeg', 'image/png'])),
                'max:'.(int) config('papers.ingest.max_file_size_kb', 51200),
            ],
        ], [
            'file.mimetypes' => 'Le raccourci doit envoyer un PDF ou une image (JPEG, PNG).',
        ])->validate();

        /** @var UploadedFile $file */
        $file = $validated['file'];

        /*
         | Consommation ATOMIQUE.
         |
         | Un UPDATE ... WHERE used_at IS NULL et non un read-modify-write :
         | le raccourci peut être relancé, et deux requêtes simultanées
         | porteuses du même jeton doivent donner exactement un succès.
         | La ligne affectée fait foi.
         */
        $consumed = IngestToken::query()
            ->whereKey($token->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'ip' => $request->ip()]);

        abort_if($consumed !== 1, 401, 'Jeton d’ingestion déjà utilisé.');

        /** @var User|null $user */
        $user = $token->user;

        abort_if($user === null, 401, 'Compte introuvable pour ce jeton.');

        return $this->ingest($user, $file);
    }

    private function ingest(User $user, UploadedFile $file): JsonResponse
    {
        $isPdf = $this->looksLikePdf($file);
        $maxPages = (int) config('papers.images.max_pages_per_document', 30);

        /** @var Document $document */
        $document = $user->documents()->create([
            'title' => 'Scan du '.now()->format('d/m/Y à H\hi'),
            'status' => DocumentStatus::Pending,
            'source' => DocumentSource::Shortcut,
            // Nom REGÉNÉRÉ côté serveur. Celui du client n'est même pas lu :
            // Raccourcis envoie « Scanned Document.pdf » pour tout le monde.
            'original_filename' => sprintf(
                'scan-%s.%s',
                now()->format('Ymd-His'),
                $isPdf ? 'pdf' : 'jpg',
            ),
            'page_count' => 0,
        ]);

        $workDir = storage_path('app/private/tmp/ingest-'.Str::uuid()->toString());

        try {
            if ($isPdf) {
                File::ensureDirectoryExists($workDir);

                $pages = $this->rasterizePdf((string) $file->getRealPath(), $workDir, $maxPages);

                foreach ($pages as $index => $imagePath) {
                    $this->storeDocumentPage($document, $index + 1, $imagePath, 'image/jpeg');
                }

                $pageCount = count($pages);
            } else {
                $this->storeDocumentPage($document, 1, $file);
                $pageCount = 1;
            }

            $document->forceFill(['page_count' => $pageCount])->save();
        } catch (RuntimeException $e) {
            $this->purgeDocumentFiles($document);
            $document->forceDelete();

            Log::error('Ingestion raccourci : conversion impossible', ['error' => $e->getMessage()]);

            // 503 et non 422 : le fichier de l'utilisateur est valide, c'est
            // le serveur qui n'a pas de moteur de rasterisation PDF.
            abort(503, $e->getMessage());
        } catch (Throwable $e) {
            $this->purgeDocumentFiles($document);
            $document->forceDelete();

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }

        AnalyzeDocument::dispatch($document);

        return DocumentResource::make($document->load('pages'))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Type déduit du CONTENU (finfo), pas de l'extension : le raccourci peut
     * annoncer n'importe quoi, et « Scanned Document.pdf » est parfois une
     * image selon les actions choisies par l'utilisateur.
     */
    private function looksLikePdf(UploadedFile $file): bool
    {
        return $file->getMimeType() === 'application/pdf';
    }
}
