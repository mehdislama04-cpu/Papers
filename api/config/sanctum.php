<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Papers s'authentifie par COOKIE de session (Sanctum SPA), pas par token
    | Bearer. Décision imposée par iOS : ITP purge tout stockage inscriptible
    | par script (localStorage, IndexedDB, Cache API) après 7 jours sans
    | interaction. Un token Bearer stocké côté client déconnecterait donc
    | l'utilisateur au bout d'une semaine. Les cookies posés par le serveur
    | via Set-Cookie échappent à ce plafond.
    |
    | Sanctum::currentRequestHost() ne renvoie pas un host mais le placeholder
    | « ,__SANCTUM_CURRENT_REQUEST_HOST__ », que EnsureFrontendRequestsAreStateful
    | remplace à l'exécution par $request->getHttpHost(). C'est donc compatible
    | avec `php artisan config:cache`, contrairement à un host codé en dur.
    |
    | Sans lui, on prend un 401 en boucle dès qu'on passe par un tunnel : le
    | host de la requête (dev.mondomaine.com) ne correspond pas à APP_URL.
    |
    | Il est volontairement restreint au hors-production : en production le
    | host doit être figé explicitement via SANCTUM_STATEFUL_DOMAINS, sinon
    | n'importe quel en-tête Host entrant deviendrait stateful.
    |
    | Note : on utilise `?:` et non le 2e argument de env(). Une variable
    | présente mais vide dans .env renvoie '' (pas null), et le 2e argument de
    | env() ne serait alors PAS pris en compte : explode(',', '') donnerait
    | [''], donc aucun domaine stateful, donc 401 partout.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS') ?: sprintf(
        '%s%s%s',
        'localhost,localhost:8000,localhost:5173,127.0.0.1,127.0.0.1:8000,127.0.0.1:5173,::1',
        Sanctum::currentApplicationUrlWithPort(),
        env('APP_ENV') !== 'production' ? Sanctum::currentRequestHost() : '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Le guard "web" est celui qui porte la session. C'est lui qui authentifie
    | la PWA ; le fallback token Bearer de Sanctum ne sert qu'aux jetons
    | d'ingestion, qui sont vérifiés par leur propre middleware sur
    | /api/ingest/shortcut (usage unique, hachés, TTL court).
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | null = les personal access tokens n'expirent pas d'eux-mêmes. Sans effet
    | sur la session first-party, dont la durée est SESSION_LIFETIME.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Un préfixe permet au secret scanning de GitHub/GitLab de reconnaître un
    | jeton Papers commité par erreur.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'papers_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | AuthenticateSession invalide la session des autres appareils quand le mot
    | de passe change. ValidateCsrfToken est ce qui rend /api stateful sûr :
    | le front doit URL-décoder le cookie XSRF-TOKEN avant de le placer dans
    | l'en-tête X-XSRF-TOKEN (fetch ne le fait pas, contrairement à axios),
    | et rejouer une fois sur 419 après re-fetch de /sanctum/csrf-cookie.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
