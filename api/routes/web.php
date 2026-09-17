<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coquille du PWA
|--------------------------------------------------------------------------
|
| Mono-origine : Laravel sert le front ET l'API. Pas de CORS, et surtout
| l'authentification par cookie de session redevient possible.
|
| Le routage front est en HISTORY API, jamais en hash : sur iOS, la permission
| caméra d'une PWA installée est révoquée à CHAQUE changement de hash d'URL
| (ARCHITECTURE.md §3). Conséquence côté serveur : n'importe quelle URL de
| l'app (/documents/xxx, /todos, /reglages...) doit renvoyer la même coquille
| HTML, à charge pour react-router de décider quoi afficher.
|
| D'où cette route attrape-tout — avec une exclusion EXPLICITE de tout ce qui
| n'est pas du front. Les routes de ce fichier sont enregistrées AVANT la route
| de service du disque « documents » (posée au boot par
| FilesystemServiceProvider avec un {path} en '.*') : sans le filtre ci-dessous,
| l'attrape-tout raflerait /files/documents/... et renverrait du HTML à la
| place des images.
|
| Chemins réservés :
|   api       -> l'API JSON
|   sanctum   -> /sanctum/csrf-cookie
|   files     -> disque privé « documents » (URLs signées)
|   storage   -> disque public
|   build     -> assets compilés par Vite
|   up        -> health check (withRouting(health: '/up'))
|   livewire  -> réservé, au cas où un paquet l'enregistre
|
| NB : pas d'ancre ^ dans le motif. Symfony l'injecte au milieu de l'expression
| compilée (#^/(?P<any>...)?$#sDu) : un ^ ajouté ici n'assortirait plus le
| début du groupe mais le début de l'URI COMPLÈTE, qui commence par « / ».
| L'assertion échouerait toujours et la route ne matcherait plus rien. Le
| lookahead négatif, lui, s'évalue bien à la position du groupe.
*/
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '(?!api(?:/|$)|sanctum(?:/|$)|files(?:/|$)|storage(?:/|$)|build(?:/|$)|up(?:/|$)|livewire(?:/|$)).*')
    ->name('app');
