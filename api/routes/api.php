<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Calendar\CalendarAccountController;
use App\Http\Controllers\Calendar\CalendarResyncController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentPageFileController;
use App\Http\Controllers\DocumentReanalyzeController;
use App\Http\Controllers\Ingest\IngestTokenController;
use App\Http\Controllers\Ingest\ShortcutIngestController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonPhotoController;
use App\Http\Controllers\PersonPhotoFileController;
use App\Http\Controllers\TodoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Papers — préfixe /api
|--------------------------------------------------------------------------
|
| AUTHENTIFICATION PAR SESSION (Sanctum SPA), PAS PAR TOKEN BEARER.
| statefulApi() est posé dans bootstrap/app.php : pour un host déclaré
| stateful, Sanctum réinjecte session, cookies chiffrés et validation CSRF sur
| le groupe « api ». Le front appelle GET /sanctum/csrf-cookie avant sa
| première écriture, URL-DÉCODE le cookie XSRF-TOKEN avant de le placer dans
| X-XSRF-TOKEN, et rejoue UNE fois sur 419.
|
| Motif (ARCHITECTURE.md §2) : sur iOS, ITP purge tout stockage inscriptible
| par script au bout de 7 jours sans interaction. Un token stocké côté client
| déconnecterait l'utilisateur au bout d'une semaine.
|
| Toutes les réponses sont enveloppées dans { "data": ... } par les API
| Resources. Les erreurs de validation sortent en 422 standard Laravel.
|
*/

/*
| Invités.
|
| Les limites sont volontairement basses : ces deux routes sont les seules
| surfaces d'attaque en écriture non authentifiées (avec /api/ingest/shortcut).
| Le login a en plus un limiteur par couple (email, IP) dans LoginRequest, qui
| protège UN compte sans bloquer toute une IP partagée.
*/
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('register');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('login');

/*
| Pont « scanner natif Apple » — PAS de auth:sanctum, c'est délibéré.
|
| La requête vient de l'app Raccourcis : ni cookie de session, ni jeton CSRF.
| L'authentification est portée par un jeton à usage unique, haché au repos,
| à TTL court, vérifié dans le contrôleur.
|
| Le limiteur est par IP : un jeton volé ne donne droit qu'à un seul upload de
| toute façon, mais rien n'empêche d'essayer d'en deviner.
*/
Route::post('/ingest/shortcut', ShortcutIngestController::class)
    ->middleware('throttle:30,1')
    ->name('ingest.shortcut');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/me', MeController::class)->name('me');

    /*
    | Documents.
    */
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    // Déplacer un document d'une catégorie à une autre : le classement
    // automatique se trompe parfois, et relancer l'analyse n'est pas une
    // correction, c'est un pari.
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::post('/documents/{document}/reanalyze', DocumentReanalyzeController::class)
        ->name('documents.reanalyze');

    /*
    | Fichier d'une page.
    |
    | `signed:relative` : la signature ne couvre que le chemin et la query, pas
    | l'hôte. En dev, l'app est atteinte par un tunnel dont le nom change à
    | chaque session ; une signature absolue serait rejetée en 403 dès que
    | l'hôte de la requête diffère de celui qui a signé.
    |
    | `scopeBindings()` : {page} est résolu DANS $document->pages(). Une page
    | appartenant à un autre document donne un 404 avant même la policy.
    */
    Route::get('/documents/{document}/pages/{page}/file', DocumentPageFileController::class)
        ->middleware('signed:relative')
        ->scopeBindings()
        ->name('documents.pages.file');

    /*
    | Échéances.
    */
    Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
    Route::patch('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    // Catégorie PERSONNELLE. Les douze catégories système ne se modifient pas :
    // elles sont le socle commun et le vocabulaire ferme du modele d'extraction.
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    // Suppression reservee aux categories personnelles (cf. CategoryPolicy).
    // Les documents ranges dedans redeviennent sans categorie, ils ne
    // disparaissent pas.
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('categories.destroy');

    /*
    | Personnes.
    |
    | La liste ne renvoie que les personnes dont une décision a été
    | enregistrée — aujourd'hui, celles qui ont une photo. Le regroupement des
    | destinataires reste calculé par le front, qui recolle les photos par la
    | clé de rapprochement.
    |
    | L'envoi de photo n'est PAS sous /people/{person} : au moment où
    | l'utilisateur choisit une image, la personne n'existe peut-être pas
    | encore en base. La requête porte donc la clé, et le contrôleur upserte.
    */
    Route::get('/people', [PersonController::class, 'index'])->name('people.index');

    // Renommage. Comme la photo, la requête porte la clé plutôt qu'un
    // identifiant : la personne n'existe peut-être pas encore. Le nom envoyé
    // ici est figé — plus aucun document ne le réécrira.
    Route::post('/people', [PersonController::class, 'store'])->name('people.store');

    Route::post('/people/photo', [PersonPhotoController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('people.photo.store');
    Route::delete('/people/{person}/photo', [PersonPhotoController::class, 'destroy'])
        ->name('people.photo.destroy');

    /*
    | Fichier de la photo. Même dispositif que les pages : signature relative
    | (l'hôte du tunnel de dev change à chaque session) plus session plus policy.
    */
    Route::get('/people/{person}/photo/file', PersonPhotoFileController::class)
        ->middleware('signed:relative')
        ->name('people.photo.file');

    /*
    | Calendrier iCloud (CalDAV).
    |
    | Aucune requête CalDAV ne part jamais du navigateur : Apple n'envoie pas
    | d'en-têtes CORS, et cela exposerait le mot de passe d'application.
    */
    Route::get('/calendar/account', [CalendarAccountController::class, 'show'])->name('calendar.account.show');
    Route::post('/calendar/account', [CalendarAccountController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('calendar.account.store');
    Route::delete('/calendar/account', [CalendarAccountController::class, 'destroy'])->name('calendar.account.destroy');
    Route::post('/calendar/resync', CalendarResyncController::class)
        ->middleware('throttle:10,1')
        ->name('calendar.resync');

    /*
    | Jeton d'ingestion du raccourci iOS.
    */
    Route::post('/ingest/token', IngestTokenController::class)
        ->middleware('throttle:20,1')
        ->name('ingest.token');
});
