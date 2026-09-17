<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | AUTHENTIFICATION PAR COOKIE DE SESSION POUR /api.
        |
        | statefulApi() place EnsureFrontendRequestsAreStateful en tête du groupe
        | « api » : pour une requête dont le host figure dans sanctum.stateful,
        | Sanctum réinjecte les middlewares de session, de cookies chiffrés et de
        | validation CSRF. C'est ce qui permet à la PWA de s'authentifier sans
        | token Bearer.
        |
        | Pourquoi pas de Bearer : sur iOS, ITP purge tout stockage inscriptible
        | par script (localStorage, IndexedDB, Cache API) après 7 jours sans
        | interaction. Un token stocké côté client déconnecterait l'utilisateur
        | au bout d'une semaine — rédhibitoire pour une app qu'on ouvre
        | sporadiquement. Un cookie posé par le serveur via Set-Cookie échappe à
        | ce plafond.
        |
        | Conséquence côté front : une 419 signifie « CSRF expiré », donc
        | re-fetch de /sanctum/csrf-cookie puis UN retry — jamais une
        | déconnexion.
        */
        $middleware->statefulApi();

        /*
        | PROXY DE CONFIANCE — INDISPENSABLE POUR LE TUNNEL HTTPS.
        |
        | En dev on teste sur un vrai iPhone via un tunnel nommé Cloudflare :
        |   iPhone --HTTPS--> Cloudflare --HTTP--> cloudflared (local) --> :8000
        |
        | cloudflared tourne sur la MÊME machine que `php artisan serve`, donc
        | le REMOTE_ADDR vu par PHP est 127.0.0.1 / ::1. On ne fait confiance
        | qu'à ces deux adresses : surtout pas '*', qui laisserait n'importe
        | quel client forger X-Forwarded-Host et X-Forwarded-Proto.
        |
        | Sans cela, trois choses cassent, toutes silencieusement :
        |
        |  1. Laravel croit la requête en HTTP. Le cookie de session est émis
        |     sans le drapeau Secure — Safari le refuse sur une origine HTTPS,
        |     donc la connexion « marche » puis l'utilisateur est déconnecté au
        |     rechargement.
        |  2. url()/route() génèrent des URLs en http:// dans une page servie en
        |     https:// : contenu mixte bloqué par Safari.
        |  3. Les URLs SIGNÉES (accès aux pages de documents, temporaryUrl du
        |     disque « documents ») sont calculées sur le mauvais schéma/host et
        |     la vérification échoue en 403.
        |
        | X_FORWARDED_HOST est inclus volontairement : c'est lui qui porte le
        | hostname du tunnel, et c'est ce hostname que
        | Sanctum::currentRequestHost() lit via $request->getHttpHost() pour
        | rendre l'origine stateful (cf. config/sanctum.php).
        */
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La PWA ne consomme que du JSON : une exception sous /api ne doit
        // jamais renvoyer la page d'erreur HTML de Laravel, que le client
        // essaierait de parser en JSON et qui masquerait la vraie cause.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
