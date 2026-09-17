# Papers — Architecture

PWA de numérisation, tri et analyse de documents papier, avec extraction de tâches et poussée automatique des échéances dans le calendrier iCloud.

Cible : iPhone 14 Plus (encoche, viewport CSS 428×926, DPR 3), PWA installée.

Toutes les décisions ci-dessous s'appuient sur `docs/RECHERCHE.md` (dossier de recherche vérifié sur le web le 17/09/2026, avec sources).

## Versions retenues (vérifiées sur la machine)

| Composant | Version | Vérification |
|---|---|---|
| PHP | 8.5.10 | `php -v` |
| Laravel | 13.32.0 | `artisan --version` |
| Sanctum | 4.3 | composer.lock |
| sabre/vobject | 4.6.1 | composer.lock |
| intervention/image | 4.3.2 | composer.lock |
| Guzzle | 8.2.0 | composer.lock |
| PostgreSQL | 18 + pgvector | image `pgvector/pgvector:pg18` |
| Bun | 1.3.11 | `bun --version` |

## Décisions structurantes

### 1. Mono-origine : Laravel sert le PWA et l'API

Le front est construit par Vite dans `api/public/build`, Laravel sert `index.html` et expose l'API sous `/api`. Pas de CORS, et surtout cela permet l'authentification par cookie.

### 2. Authentification : Sanctum SPA (cookies), pas de token Bearer

Décision contre-intuitive mais imposée par iOS. ITP purge tout stockage inscriptible par script (localStorage, IndexedDB, Cache API) après **7 jours sans interaction**. Un token Bearer stocké côté client déconnecte donc l'utilisateur au bout d'une semaine — rédhibitoire pour une app qu'on ouvre sporadiquement. Les cookies posés par le serveur via `Set-Cookie` échappent à ce plafond.

Conséquences :

- `config/sanctum.php` doit utiliser `Sanctum::currentRequestHost()` (l'URL du tunnel de dev change).
- Le service worker ne doit **jamais** mettre en cache `/login`, `/logout`, `/sanctum/*` ni `/api/*`.
- Une 419 doit déclencher un re-fetch de `/sanctum/csrf-cookie` puis un retry — pas une déconnexion.
- Le cookie `XSRF-TOKEN` doit être **URL-décodé** avant d'être placé dans l'en-tête `X-XSRF-TOKEN` (`fetch` ne le fait pas, contrairement à axios).

Pas d'OAuth par redirection : en mode standalone le stockage est isolé de Safari, l'aller-retour reviendrait dans Safari et la session ne serait jamais vue par l'app. Login in-app uniquement.

### 3. Capture : appareil photo natif, pas de flux vidéo live

`<input type="file" accept="image/jpeg,image/png" capture="environment">`.

Motifs, tous vérifiés :

- La permission caméra n'est **pas persistée** pour une PWA installée (WebKit 215884) → re-prompt à chaque lancement. Elle est même révoquée à chaque changement de *hash* d'URL → routage par History API obligatoire.
- Flux vidéo noir en standalone (WebKit 252465), régressions jusqu'à iOS 18.5.
- `ImageCapture.takePhoto()` n'existe pas sur iOS : une capture live passerait par `canvas.drawImage(video)`, donc en qualité de preview, pas de capteur.
- Pas de torche ni de zoom pilotables depuis le web sur iOS.

L'appareil photo natif donne autofocus, flash et pleine résolution capteur.

Ne **jamais** mettre `image/heic` dans `accept` : depuis Safari 17 cela fait renvoyer du HEIC par Safari, soit l'inverse de l'effet recherché.

### 4. Le scan reste un vrai scan

Le traitement s'applique à la photo capturée, dans un Web Worker (OpenCV.js) :

downscale 720px → grayscale → GaussianBlur → Canny → findContours → approxPolyDP → sélection du quadrilatère (aire, convexité, ratio) → ordonnancement des coins par angle autour du centroïde → remise à l'échelle des coins → `getPerspectiveTransform` + `warpPerspective` à la résolution native → correction d'illumination (division par flou) → CLAHE → sortie.

Ajustement manuel des 4 coins proposé systématiquement.

`jscanify` est **écarté** : son pipeline est algorithmiquement faux (Canny sur RGBA brut, puis blur sur la carte d'arêtes, puis Otsu sur ce flou — l'ordre correct est blur → Canny), il ne fait ni `approxPolyDP` ni test de convexité, sa détection de coins casse au-delà de ~20° d'inclinaison, et `extractPaper` fuit dans la heap WASM, qui ne redescend jamais avec `ALLOW_MEMORY_GROWTH=1`.

Contraintes mémoire iOS : limite d'aire canvas 8192×8192 depuis iOS 18, mais limite **mémoire** canvas distincte (~384 Mo) toujours active. Un canvas 4032×3024 RGBA pèse 48,8 Mo : libérer chaque canvas hors écran avec `c.width = 0; c.height = 0`.

`canvas.toBlob('image/webp')` retombe **silencieusement** sur PNG dans Safari : vérifier `blob.type` après coup. Sortie en JPEG.

### 5. Ce qui part au modèle : l'image grise corrigée, jamais la binarisée

Le N&B 1-bit est réservé à l'aperçu écran. L'envoyer au modèle de vision ferait perdre tampons, surlignages, encre colorée et signatures, sans rien économiser — le coût dépend des dimensions, pas du nombre de canaux.

Modèle de vision : `detail: "original"`, recommandé par OpenAI pour l'OCR. `detail: "high"` écrase l'image dans 2048×2048 **et** 2 500 patches, ce qui détruit le petit texte d'une A4. Au-delà de 30 000 patches la requête est **rejetée**, pas redimensionnée : redimensionner côté client avant l'envoi.

Tokenisation par patches 32×32 : `ceil(w/32) × ceil(h/32) × 1.2`.

### 6. OpenAI via le client HTTP natif de Laravel

`openai-php/laravel` v0.21.0 exige Guzzle `^7.9.3` alors que Laravel 13 installe Guzzle 8.2.0 : **incompatible**, constaté à l'installation. Aucune perte : ce client n'est qu'un passe-plat de tableaux vers le JSON, sans retry automatique, sans parsing des Structured Outputs, avec un timeout par défaut de 30 s trop court pour un multipage.

API : `/v1/responses`, recommandée pour tout nouveau projet.

Structured Outputs : `text.format = {type, name, schema, strict}` — `name`, `schema` et `strict` sont **frères** de `type`, pas imbriqués comme en Chat Completions.

Contraintes du schéma : racine obligatoirement un objet et jamais `anyOf` ; `additionalProperties: false` sur **chaque** objet ; **tous** les champs dans `required` (un champ optionnel s'émule avec `type: ["string","null"]`).

Gestion d'erreurs : un refus arrive comme un content part `{"type":"refusal"}` et ne respecte pas le schéma ; vérifier aussi `status == "completed"` et `incomplete_details`. Depuis 2026 une montée en charge trop rapide renvoie **429 + `slow_down`** et non plus 503 : gérer 429 **et** 503, traiter `Retry-After` comme un minimum et ajouter un jitter.

### 7. Piège Windows sur les timeouts de job

Les timeouts de job Laravel reposent sur `pcntl_alarm`, **absent sur Windows**. `#[Timeout]` et `--timeout` ne sont donc pas appliqués par un worker Windows natif : un appel OpenAI qui pend bloque le worker indéfiniment. Seule protection réelle : `Http::timeout(120)->connectTimeout(10)` au niveau du client HTTP.

Et `retry_after` doit rester **supérieur** au timeout, sinon le job est redistribué à un second worker pendant qu'il tourne encore : double appel OpenAI, double facturation.

### 8. Calendrier : CalDAV iCloud, événements avec alarmes

Gratuit : Apple Account avec 2FA + mot de passe d'application, 25 maximum.

Découverte en 3 étapes : `PROPFIND /` (current-user-principal) → `PROPFIND /{dsid}/principal/` (calendar-home-set) → `PROPFIND Depth:1` sur le home. Le calendar-home-set renvoie une **partition** numérotée (`https://pNN-caldav.icloud.com/{dsid}/calendars/`) à persister par compte.

Deux pièges qui cassent toute implémentation naïve :

- Depuis CVE-2022-31090, Guzzle **supprime l'en-tête `Authorization`** sur tout changement d'hôte lors d'une redirection → 401 sur la bascule vers la partition. Et sans `strict`, il transforme un PROPFIND redirigé en GET sans corps. Solution : `allow_redirects => false` + redirection manuelle.
- HTTP/2 provoque des **421 Misdirected Request** aléatoires (coalescing de connexion). Forcer HTTP/1.1.

Les **Rappels iCloud (VTODO) sont inexploitables** via CalDAV depuis iOS 13. Les todos vivent donc dans l'app ; ce qui part dans iCloud, ce sont des VEVENT avec VALARM, dans un calendrier dédié « Papers — Échéances » créé par `MKCALENDAR`.

UID déterministe (`papers-todo-{uuid}@papers.app`) pour l'idempotence, `If-None-Match: *` à la création, `If-Match: <etag>` à la mise à jour. `DTEND` d'un événement all-day est **exclusif** (J+1), sinon l'événement est invisible. Lignes ICS pliées à 75 octets, CRLF obligatoire.

Un 401 est un **état terminal** (mot de passe révoqué, ou mot de passe principal Apple changé — ce qui révoque tous les mots de passe d'application) : marquer le compte `invalid_credentials` et notifier, ne pas boucler en retry.

Jamais de requête CalDAV depuis le navigateur : Apple ne renvoie pas d'en-têtes CORS et cela exposerait le mot de passe d'application au client.

### 9. Recherche : plein texte français + vectorielle

Laravel 13 a la recherche vectorielle **native**, aucun package tiers : `Schema::ensureVectorExtensionExists()`, `$table->vector('embedding', dimensions: 1536)`, cast `AsVector`, `whereVectorSimilarTo()`, `orderByVectorDistance()`. Vérifié dans le code source installé.

Deux pièges vérifiés dans le vendor :

- La doc officielle montre `->index()` sur une colonne vector, ce qui crée un index **btree inutile**. Il faut `->vectorIndex()`, qui produit `hnsw` + `vector_cosine_ops`, cohérent avec l'opérateur `<=>` généré.
- `whereFullText()` remplace **silencieusement** la langue par `english` si elle n'est pas dans `validFullTextLanguages()` (ligne 166-168 de `PostgresGrammar`) — aucune exception. `french` y est, mais pas une config custom `fr_unaccent`. Solution retenue : colonne `tsvector` **générée** alimentée par `to_tsvector('fr_unaccent', ...)` + index GIN, interrogée avec `whereFullText($col, $q, ['vector' => true])` qui utilise la colonne telle quelle.

Embeddings : `text-embedding-3-small`, 1536 dimensions. `text-embedding-3-large` (3072) **ne rentre pas** dans un index HNSW pgvector, la limite étant 2000 dimensions sur le type `vector`.

### 10. Sécurité

- Mot de passe d'application iCloud : cast `encrypted`. Perdre `APP_KEY` = perdre définitivement les données chiffrées → `APP_PREVIOUS_KEYS` pour la rotation.
- Une colonne `encrypted` n'est ni indexable ni interrogeable : ne jamais chiffrer ce qui alimente `search_vector` ou les filtres.
- **Injection de prompt** : le contenu OCR d'un document est une entrée hostile. Il est encadré comme donnée, le modèle est instruit de ne jamais suivre d'instruction qui s'y trouve, et surtout aucune sortie du modèle ne déclenche d'action privilégiée sans validation : les échéances extraites sont des propositions, la poussée calendrier est un effet de bord d'une décision applicative, pas d'une phrase du document.
- Documents servis par URLs signées temporaires, isolation par policies.
- Jetons d'upload du raccourci iOS : usage unique, TTL court, hachés au repos.

## Le pont « scanner natif Apple » (Raccourcis)

Le scanner de Notes (VisionKit) n'est accessible par **aucune** API web. Le Web Share Target n'existe pas sur iOS, donc pas de « partager vers l'app ». La Shape Detection API est cassée sur Safari iOS depuis iOS 18.

Le seul pont praticable est un raccourci iOS :

1. La PWA génère un jeton à usage unique et ouvre `shortcuts://run-shortcut?name=Papers&input=text&text=<jeton>`.
2. Le raccourci scanne, puis POSTe le PDF en multipart vers `/api/ingest/shortcut` avec le jeton en `Authorization`.
3. Il se termine par l'action **« Ouvrir l'app »** ciblant la web app.

Limites assumées, toutes vérifiées :

- `x-success=https://...` **ne rouvre pas** la PWA installée, ça ouvre Safari. iOS ne permet pas de deep-linker une web app par URL https. D'où « Ouvrir l'app », seul moyen officiel depuis iOS 16.4. `x-error` et `x-cancel` sont donc inutilisables : l'état est porté par le serveur.
- L'action Apple native « Numériser un document » (Fichiers) **ne renvoie pas** le fichier comme variable exploitable. Il faut l'app gratuite **Actions** de Sindre Sorhus (« Scan Documents », sortie via presse-papiers, à suivre de « Attendre le retour » puis « Obtenir le presse-papiers »), qui exige iOS 26+.
- Un `.shortcut` ne peut pas être généré programmatiquement : la signature AEA est obligatoire depuis iOS 15, `shortcuts sign` rejette tout plist fait main, et `shortcuts://import-shortcut` n'accepte que des URLs iCloud. Le raccourci est donc **construit par l'utilisateur**, guidé pas à pas dans l'onboarding.
- Le nom du raccourci est son seul identifiant : s'il est renommé, le lancement échoue silencieusement → prévoir un timeout et un repli.
- Ne jamais mettre de clé API dans le raccourci : il est partagé entre tous les utilisateurs. Tout passe par le jeton en input.
- Ne pas poser de `Content-Type` manuel sur « Obtenir le contenu de l'URL » en mode Form : Raccourcis génère le boundary multipart.
- Le PDF produit s'appelle souvent « Scanned Document.pdf » → renommer côté serveur, ne jamais faire confiance au filename reçu.

## Ce qu'iOS ne permettra pas, quoi qu'on fasse

- Écrire directement dans l'app Calendrier depuis le web : EventKit est inaccessible. On passe par le serveur et CalDAV.
- Background Sync / Periodic Background Sync / Background Fetch : inexistants. Aucune reprise d'upload en arrière-plan. File IndexedDB rejouée sur `online`, `visibilitychange` et au démarrage.
- Torche, zoom, focus pilotés depuis le web.
- Apparaître dans la feuille de partage iOS.
- Le stockage web peut être purgé après ~7 jours sans ouverture : ne jamais garder d'état critique uniquement côté client.
