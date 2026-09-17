<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Models\IngestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IngestTokenController extends Controller
{
    /**
     * POST /api/ingest/token -> { token, expires_at, shortcut_url }
     *
     * Le raccourci iOS est PARTAGÉ entre tous les utilisateurs : il ne peut
     * contenir aucun secret, aucune clé d'API. Tout passe par ce jeton, donné
     * en input du raccourci, qu'il renvoie en `Authorization: Bearer` sur
     * /api/ingest/shortcut.
     *
     * Trois propriétés, toutes nécessaires :
     *  - usage unique (used_at) ;
     *  - TTL court — le temps d'ouvrir Raccourcis, scanner, revenir ;
     *  - HACHÉ au repos : la base ne contient que le SHA-256, une fuite ne
     *    donne aucun jeton utilisable. La valeur en clair n'existe que dans
     *    cette réponse.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Un seul jeton vivant à la fois : demander un nouveau jeton révoque
        // le précédent. Sinon un raccourci lancé puis abandonné laisserait une
        // fenêtre d'upload ouverte dans le dos de l'utilisateur.
        $user->ingestTokens()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        // 48 caractères d'alphabet alphanumérique : ~285 bits. Le jeton
        // transite dans une URL shortcuts:// puis dans un en-tête.
        $plain = Str::random(48);

        $token = new IngestToken;
        $token->user()->associate($user);

        // token_hash et ip ne sont pas `fillable` : c'est voulu, ces valeurs
        // ne viennent jamais d'une requête.
        $token->forceFill([
            'token_hash' => IngestToken::hashFor($plain),
            'expires_at' => now()->addMinutes(IngestToken::TTL_MINUTES),
            'ip' => $request->ip(),
        ])->save();

        return response()->json([
            'data' => [
                // Renvoyé UNE SEULE FOIS. Il n'est plus lisible ensuite.
                'token' => $plain,
                'expires_at' => $token->expires_at?->toAtomString(),
                'expires_in' => IngestToken::TTL_MINUTES * 60,

                /*
                 | URL prête à l'emploi.
                 |
                 | Le NOM du raccourci est son seul identifiant : renommé par
                 | l'utilisateur, le lancement échoue SILENCIEUSEMENT. Le front
                 | doit donc prévoir un délai d'attente et un repli sur
                 | l'upload classique — il n'y a aucun retour d'erreur possible
                 | (x-error et x-cancel ne peuvent pas rouvrir une PWA
                 | installée, cf. ARCHITECTURE.md).
                 */
                'shortcut_url' => sprintf(
                    (string) config('papers.ingest.shortcut_url_template', 'shortcuts://run-shortcut?name=%s&input=text&text=%s'),
                    rawurlencode((string) config('papers.ingest.shortcut_name', 'Papers')),
                    rawurlencode($plain),
                ),
                'shortcut_name' => config('papers.ingest.shortcut_name', 'Papers'),
            ],
        ], 201);
    }
}
