# Papers — API & PWA

PWA iOS de numérisation, tri et analyse de documents papier. Laravel 13 sert
l'API **et** le front (mono-origine, pas de CORS), PostgreSQL 18 + pgvector pour
la recherche, OpenAI pour l'extraction, CalDAV iCloud pour les échéances.

Ce README décrit le démarrage **sur Windows 11**, pas à pas, et la mise en place
du HTTPS nécessaire pour tester sur un vrai iPhone.

> Les décisions d'architecture sont dans `../docs/ARCHITECTURE.md`.
> Le détail technique vérifié (versions, pièges, sources) est dans
> `../docs/RECHERCHE.md`.

---

## Versions de référence

| Composant | Version | Comment vérifier |
|---|---|---|
| PHP | 8.5.10 (ZTS, VC++ 2022, x64) | `php -v` |
| Laravel | 13.32.0 | `php artisan --version` |
| PostgreSQL | 18 + pgvector | `docker exec papers-postgres psql -U papers -d papers -c "select version()"` |
| Redis | 8 | `docker exec papers-redis redis-cli ping` |
| Bun | 1.3.11 | `bun --version` |
| Docker | 29.x / Compose v5 | `docker compose version` |

---

## Étape 1 — Vérifier les extensions PHP (à faire AVANT tout le reste)

C'est la seule étape qui réserve des surprises sur Windows. Trois extensions
comptent, et les builds Windows de PHP n'en fournissent qu'une partie.

```powershell
php -r "printf('pdo_pgsql:%s  redis:%s  gd:%s  imagick:%s  pcntl:%s%s', extension_loaded('pdo_pgsql')?'OK':'MANQUANT', extension_loaded('redis')?'OK':'MANQUANT', extension_loaded('gd')?'OK':'MANQUANT', extension_loaded('imagick')?'OK':'MANQUANT', extension_loaded('pcntl')?'OK':'MANQUANT', PHP_EOL);"
```

État constaté sur la machine de dev le 17/09/2026 :

```
pdo_pgsql:OK  redis:MANQUANT  gd:OK  imagick:MANQUANT  pcntl:MANQUANT
```

### `pdo_pgsql` — obligatoire

S'il manque, décommenter `extension=pdo_pgsql` et `extension=pgsql` dans le
`php.ini` (son chemin exact : `php --ini`), puis rouvrir le terminal.

### `redis` — absente, et c'est déjà réglé

`phpredis` est une **extension native** (`php_redis.dll`), pas un paquet
Composer, et elle n'est pas installée ici. Sans parade, `php artisan queue:work`
s'arrêterait sur :

```
Class "Redis" not found
```

La parade est en place : **`predis/predis`**, un client Redis écrit en PHP pur,
est installé et `.env` porte `REDIS_CLIENT=predis`. Aucune extension à
compiler, le worker fonctionne tel quel. C'est un peu plus lent que l'extension
native sur la prise de job, sans conséquence à cette échelle.

Si vous installez un jour l'extension — build correspondant *exactement* à ce
PHP : `8.5`, **ts** (Thread Safe, ce PHP est ZTS), `x64`, `vs17` — il suffit de
repasser `REDIS_CLIENT=phpredis`. Un build `nts` ou compilé pour 8.4 ne se
chargera pas.

Troisième voie si vous préférez ne pas dépendre de Redis du tout, une ligne
dans `.env` :

```ini
QUEUE_CONNECTION=database
```

Les jobs passent alors par PostgreSQL. `retry_after` est déjà réglé à 300 s sur
cette connexion aussi (`config/queue.php`), donc rien d'autre à toucher.

> `CACHE_STORE` est volontairement laissé sur `database` dans `.env` : le cache
> est sollicité à chaque requête, donc un cache Redis indisponible ferait tomber
> *toute* l'application et pas seulement le worker. Le passer à `redis` une fois
> l'extension en place.

### `imagick` — absente, on utilise `gd`

`intervention/image` v4 fonctionne avec les deux. `IMAGE_DRIVER=gd` est déjà
positionné dans `.env`. Imagick reste préférable si vous l'installez un jour
(meilleur rééchantillonnage) — il suffira de changer cette ligne.

### `pcntl` — absente, et c'est structurant

`pcntl` n'existe **pas** sur Windows, ce n'est pas un oubli d'installation. Or
les timeouts de job Laravel reposent sur `pcntl_alarm()`. Conséquence :
`#[Timeout]` et `queue:work --timeout` **ne sont pas appliqués** par un worker
Windows natif. Un appel OpenAI qui pend bloquerait le worker indéfiniment.

La seule protection réelle est le timeout du client HTTP, déjà configuré
(`papers.openai.timeout` = 120 s, `connect_timeout` = 10 s). Ne la retirez pas.

---

## Étape 2 — Démarrer l'infrastructure

Depuis la **racine du dépôt** (`C:\Papers`), pas depuis `api\` :

```powershell
docker compose up -d
```

Attendre que les deux conteneurs soient `healthy` — le healthcheck est défini
dans `docker-compose.yml`, il suffit de le laisser converger :

```powershell
docker compose ps
```

Sortie attendue :

```
NAME              STATUS
papers-postgres   Up (healthy)
papers-redis      Up (healthy)
```

Pour attendre sans surveiller à l'œil :

```powershell
do { Start-Sleep 2; $s = docker inspect -f '{{.State.Health.Status}}' papers-postgres } while ($s -ne 'healthy'); "postgres healthy"
```

### Vérifier que les extensions PostgreSQL sont bien là

`docker/postgres-init/01-extensions.sql` n'est exécuté qu'au **tout premier**
démarrage d'un volume vide. Vérification :

```powershell
docker exec papers-postgres psql -U papers -d papers -c "\dx"
docker exec papers-postgres psql -U papers -d papers -c "select cfgname from pg_ts_config where cfgname = 'fr_unaccent'"
```

Vous devez voir `vector`, `unaccent`, `pg_trgm`, et la ligne `fr_unaccent`.

Si `fr_unaccent` manque, c'est que le volume existait déjà avant le script.
Le remède (⚠️ **efface la base**) :

```powershell
docker compose down -v
docker compose up -d
```

---

## Étape 3 — Configurer l'application

```powershell
cd C:\Papers\api
```

`.env` est déjà en place et `APP_KEY` déjà générée. Si vous repartez de zéro :

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

> ⚠️ `APP_KEY` chiffre les mots de passe d'application iCloud (colonne en cast
> `encrypted`). **La perdre, c'est perdre définitivement ces données.**
> Sauvegardez-la. Pour en changer sans rien casser, mettez l'ancienne dans
> `APP_PREVIOUS_KEYS` avant de générer la nouvelle.

Renseigner la clé OpenAI dans `.env` :

```ini
OPENAI_API_KEY=sk-...
```

Aucune clé ne doit apparaître ailleurs : ni dans le code, ni dans
`.env.example`, ni dans le raccourci iOS (qui est partagé entre utilisateurs).

Vérifier que la config se résout correctement :

```powershell
php artisan config:clear
php artisan db:show
```

`db:show` doit afficher `PostgreSQL 18.6`, base `papers`, port `55432`.

---

## Étape 4 — Migrer

```powershell
php artisan migrate
```

Puis contrôler que la recherche vectorielle et le plein texte sont en place :

```powershell
docker exec papers-postgres psql -U papers -d papers -c "\d documents"
```

La colonne `embedding` doit être de type `vector(1536)` et porter un index
**hnsw** (pas btree) ; `search_vector` doit être une colonne générée `tsvector`
avec un index **GIN**.

---

## Étape 5 — Front

```powershell
bun install
```

`bun install` crée `bun.lock`, ce qui suffit à faire basculer tout l'outillage
Laravel sur `bun run` / `bunx`. Rien d'autre à configurer.

```powershell
bun run dev
```

---

## Étape 6 — Lancer l'application

Quatre processus, **quatre terminaux** (voir la note sur `artisan dev` plus bas).

```powershell
# 1 — serveur HTTP
php artisan serve

# 2 — assets (HMR)
bun run dev

# 3 — worker de queue
php artisan queue:work --queue=scans,default --timeout=240 --tries=3 --max-time=3600

# 4 — planificateur (synchro CalDAV, purge des jetons)
php artisan schedule:work
```

Application sur <http://localhost:8000>.

### Pourquoi ces valeurs sur le worker

La chaîne de timeouts doit rester ordonnée, du plus large au plus serré :

```
retry_after 300 s   (config/queue.php)
  > --timeout 240 s (ligne ci-dessus)
    > #[Timeout] 180 s (sur le job)
      > Http::timeout 120 s (config/papers.php)
```

Si `retry_after` passait **sous** le timeout du worker, la queue considérerait
le job comme perdu et le donnerait à un second worker **pendant que le premier
tourne encore** : deux appels OpenAI sur le même document, facturés deux fois.
Avec la valeur par défaut de Laravel (90 s) et un appel vision multipage qui
dure deux minutes, le bug est systématique.

`--max-time=3600` fait redémarrer le worker toutes les heures : sous Windows,
sans `pcntl`, c'est le seul moyen simple de récupérer la mémoire et de recharger
le code.

### `php artisan dev` : utilisable, avec une réserve

`php artisan dev` lance `serve`, `queue:listen`, `pail` et `bun run dev` en
parallèle (via `concurrently` sur Windows). Deux limites :

- `pail` a besoin de `pcntl_fork` : il ne démarre pas ici. Les logs ne
  s'affichent donc pas dans le multiplexeur, il faut lire
  `storage\logs\laravel.log`.
- il utilise `queue:listen --timeout=0`, pas les réglages ci-dessus.

Pratique pour du front, insuffisant dès qu'on travaille sur les jobs.

---

## Vérifier que tout répond

```powershell
curl.exe -s -o NUL -w "up: %{http_code}`n" http://localhost:8000/up
curl.exe -s -o NUL -w "csrf: %{http_code}`n" http://localhost:8000/sanctum/csrf-cookie
```

Les deux doivent renvoyer `200` (`204` pour csrf selon la version).

**`csrf` en 500 avant d'avoir migré est normal** : `SESSION_DRIVER=database` et
la table `sessions` n'existe pas encore. Le message exact dans
`storage\logs\laravel.log` est alors :
`SQLSTATE[42P01] ... relation "sessions" does not exist`.

Test des URLs signées du disque privé (le mécanisme qui sert les pages de
documents) :

```powershell
php artisan tinker --execute="use Illuminate\Support\Facades\Storage; Storage::disk('documents')->put('probe.txt','ok'); echo Storage::disk('documents')->temporaryUrl('probe.txt', now()->addMinutes(5));"
```

L'URL renvoyée doit répondre `200`, et la même URL **sans** la query string doit
répondre `403`. Pensez à supprimer `probe.txt` ensuite.

---

## HTTPS — tester sur un vrai iPhone

### Pourquoi c'est obligatoire

`http://192.168.1.x:8000` ne marchera **jamais** : iOS refuse l'accès caméra
hors *secure context*, et une PWA ne s'installe pas depuis une origine non
sécurisée.

### Pourquoi un tunnel à URL aléatoire est un piège

Un *quick tunnel* Cloudflare (`cloudflared tunnel --url http://localhost:8000`,
sans compte) donne une URL `*.trycloudflare.com` en deux secondes — **mais elle
change à chaque redémarrage**. Même problème avec ngrok en gratuit.

Une PWA installée est liée à son **origine** (scope du manifest et du service
worker). Si l'origine change :

- l'icône sur l'écran d'accueil pointe vers une origine morte ;
- le service worker enregistré sur l'ancienne origine est inaccessible ;
- IndexedDB, Cache API et la **session** sont perdus — ce sont des stockages
  partitionnés par origine ;
- il faut désinstaller et réinstaller l'app à chaque session de dev.

Autrement dit, vous ne testeriez jamais le comportement réel de l'app installée,
qui est précisément ce qu'on cherche à valider. Cloudflare qualifie d'ailleurs
ces tunnels de best-effort, non destinés à rester en ligne.

**Il faut donc un tunnel nommé, à hostname stable**, sur un domaine que vous
possédez. Certificat valide automatiquement, aucun port à ouvrir sur la box.

### Mise en place (une seule fois)

```powershell
winget install --id Cloudflare.cloudflared
cloudflared tunnel login
cloudflared tunnel create papers-dev
cloudflared tunnel route dns papers-dev dev.mondomaine.com
```

`cloudflared tunnel create` affiche le chemin du fichier de credentials
(`%USERPROFILE%\.cloudflared\<uuid>.json`). Créer ensuite
`%USERPROFILE%\.cloudflared\config.yml` :

```yaml
tunnel: papers-dev
credentials-file: C:/Users/<vous>/.cloudflared/<uuid>.json
ingress:
  - hostname: dev.mondomaine.com
    service: http://localhost:8000
  - service: http_status:404
```

### À chaque session de dev

```powershell
cloudflared tunnel run papers-dev
```

Et dans `.env`, trois lignes à ajuster :

```ini
APP_URL=https://dev.mondomaine.com
SESSION_SECURE_COOKIE=true
```

Puis `php artisan config:clear`.

- `APP_URL` : sert à construire les URLs signées et l'URL du disque
  `documents`. S'il reste sur `localhost`, l'iPhone reçoit des liens
  d'images pointant vers sa propre machine.
- `SESSION_SECURE_COOKIE=true` : sans le drapeau `Secure`, Safari **refuse** le
  cookie sur une origine HTTPS. Symptôme typique : la connexion semble réussir,
  puis l'utilisateur est déconnecté au premier rechargement.
- `SANCTUM_STATEFUL_DOMAINS` peut rester **vide** en dev : `config/sanctum.php`
  retombe alors sur une liste contenant `Sanctum::currentRequestHost()`, qui
  rend stateful le host de la requête courante à l'exécution. En production,
  cette variable devient obligatoire.

Le passage de HTTPS (Cloudflare) à HTTP (localhost) est absorbé par
`trustProxies` dans `bootstrap/app.php`, limité à `127.0.0.1` et `::1` —
`cloudflared` tournant sur la même machine. Sans cela, Laravel croirait la
requête en HTTP et casserait à la fois le cookie, les URLs générées et la
vérification des signatures.

### Le plus simple pour un test iPhone

Pour éviter d'exposer aussi le port de Vite :

```powershell
bun run build
php artisan serve
```

Assets compilés, pas de HMR, un seul port dans le tunnel.

---

## Mot de passe d'application Apple (calendrier iCloud)

Papers pousse les échéances dans un calendrier iCloud dédié
(« Papers — Échéances ») via CalDAV. Apple **interdit** l'usage du mot de passe
principal : il faut un *mot de passe d'application*.

C'est **gratuit** — aucun compte Apple Developer requis.

1. **Activer l'authentification à deux facteurs** sur l'Apple Account. Sans 2FA,
   la génération de mots de passe d'application n'est pas proposée du tout.
2. Aller sur <https://account.apple.com> et se connecter.
3. **Sign-In and Security** → **App-Specific Passwords**.
4. **Generate an app-specific password**, lui donner un nom parlant
   (« Papers »), puis confirmer avec le mot de passe du compte.
5. Copier la valeur affichée — elle a la forme `abcd-efgh-ijkl-mnop` et
   **n'est plus jamais réaffichée**.
6. La saisir dans Papers : *Réglages → Calendrier*, avec l'identifiant Apple
   (l'adresse e-mail du compte). Elle est stockée chiffrée (cast `encrypted`,
   avec `APP_KEY`) et n'est jamais renvoyée au client.

À savoir :

- **25 mots de passe d'application actifs maximum** par compte Apple.
- Changer ou réinitialiser le mot de passe Apple **principal** révoque
  **automatiquement tous** les mots de passe d'application. L'app détectera un
  401, marquera le compte `invalid_credentials` et demandera une nouvelle
  saisie — c'est un état terminal, il n'y a pas de retry possible.
- Le format `xxxx-xxxx-xxxx-xxxx` est universellement observé mais **n'est pas
  documenté** par Apple : l'application normalise la saisie (espaces, casse)
  sans la rejeter.
- Aucune requête CalDAV ne part du navigateur : Apple ne renvoie pas d'en-têtes
  CORS, et cela exposerait le mot de passe au client. Tout passe par le serveur.

Vérifier que la liaison fonctionne :

```powershell
php artisan tinker --execute="echo json_encode(app(App\Services\CalDav\CalDavClient::class) ? 'client resolu' : 'ko');"
```

---

## Raccourci iOS (scanner natif Apple)

Le scanner de l'app Notes (VisionKit) n'est accessible par aucune API web, et
le Web Share Target n'existe pas sur iOS. Le pont passe donc par un raccourci
**que l'utilisateur construit lui-même** — un fichier `.shortcut` ne peut pas
être généré programmatiquement (signature AEA obligatoire depuis iOS 15).

L'onboarding de l'app guide pas à pas. Points à connaître côté serveur :

- Le raccourci **doit s'appeler exactement `Papers`** (valeur
  `PAPERS_SHORTCUT_NAME`). Le nom est son seul identifiant : renommé, le
  lancement échoue silencieusement.
- Il exige l'app gratuite **Actions** de Sindre Sorhus (action « Scan
  Documents »), qui demande **iOS 26+**. L'action Apple native « Numériser un
  document » ne renvoie pas le fichier comme variable exploitable.
- Aucune clé d'API dans le raccourci : il est partagé entre tous les
  utilisateurs. L'authentification se fait par le jeton à usage unique reçu en
  entrée (`POST /api/ingest/token`, TTL 10 min, haché au repos).
- Sur l'action « Obtenir le contenu de l'URL » en mode *Form*, **ne pas** poser
  de `Content-Type` manuel : Raccourcis génère lui-même le boundary multipart.
- Terminer par l'action **« Ouvrir l'app »**. `x-success=https://...` rouvrirait
  Safari et non la PWA installée.

---

## Dépannage

| Symptôme | Cause | Correctif |
|---|---|---|
| `Class "Redis" not found` | extension `phpredis` absente | étape 1, option (a) ou (b) |
| `relation "sessions" does not exist` | migrations pas jouées | `php artisan migrate` |
| `could not find driver` | `pdo_pgsql` désactivée | décommenter dans `php.ini` |
| `Connection refused` sur 55432 | conteneur arrêté | `docker compose up -d` |
| `fr_unaccent` introuvable | volume créé avant le script d'init | `docker compose down -v` puis `up -d` |
| 401 en boucle via le tunnel | host non stateful | `SANCTUM_STATEFUL_DOMAINS` vide + `config:clear` |
| Déconnexion au rechargement en HTTPS | cookie sans `Secure` | `SESSION_SECURE_COOKIE=true` |
| 419 sur chaque POST | cookie `XSRF-TOKEN` non URL-décodé côté front | voir `resources/js/lib/api.ts` |
| 403 sur une image de document | URL signée expirée ou tronquée | régénérer la `temporaryUrl` |
| `The [documents] disk conflicts with the [local] disk` | deux disques locaux `serve` sans `url` distincte | voir l'encadré de `config/filesystems.php` |
| Jobs exécutés deux fois, facture OpenAI doublée | `retry_after` ≤ `--timeout` | `retry_after` = 300, `--timeout` = 240 |
| Logs absents de `artisan dev` | `pail` exige `pcntl_fork` | lire `storage\logs\laravel.log` |

Remise à zéro complète (⚠️ efface la base) :

```powershell
cd C:\Papers
docker compose down -v
docker compose up -d
cd api
php artisan migrate:fresh
```

---

## Carte des fichiers de configuration

| Fichier | Ce qui s'y joue |
|---|---|
| `config/papers.php` | modèles OpenAI et coûts, budgets d'image, réglages CalDAV, TTL des jetons d'ingestion |
| `config/sanctum.php` | domaines stateful, `currentRequestHost()` pour le tunnel |
| `config/queue.php` | `retry_after` et la règle de timeout (encadré en tête de fichier) |
| `config/database.php` | connexion `pgsql` sur 55432, Redis sur 56379 |
| `config/filesystems.php` | disque privé `documents`, `serve` et URLs signées |
| `bootstrap/app.php` | `statefulApi()`, `trustProxies()` pour le tunnel |
| `.env` | valeurs locales ; **jamais commité** |

## Tests

Deux suites : PHPUnit pour le serveur, un banc dédié pour l'algorithme de scan.

### Suite serveur

Les tests tournent sur **PostgreSQL**, pas sur sqlite en mémoire : le schéma
utilise `vector(1536)`, une colonne `tsvector` générée STORED et des index HNSW
et GIN, qu'un sqlite ne sait pas reproduire. Un test vert sur sqlite ne
prouverait rien de ce qui tourne réellement.

Créer la base de test une fois (le conteneur doit être démarré) :

```bash
docker exec papers-postgres psql -U papers -d papers -c "CREATE DATABASE papers_test OWNER papers;"
```

Puis y installer les extensions et la configuration de recherche française :

```bash
docker exec papers-postgres psql -U papers -d papers_test -f /docker-entrypoint-initdb.d/01-extensions.sql
```

Lancer :

```bash
php artisan test
```

La connexion de test est déclarée dans `phpunit.xml` (base `papers_test` sur le
port 55432). `RefreshDatabase` rejoue les migrations à chaque exécution.

Ce que la suite verrouille, et pourquoi :

| Suite | Défaillance évitée |
|---|---|
| `ExtractionSchemaTest` | un schéma non conforme au mode strict fait échouer **tous** les appels OpenAI en 400, et on ne le verrait qu'au premier document scanné |
| `IcsBuilderTest` | un `DTEND` non exclusif produit un événement accepté par iCloud puis **invisible** — l'échec le plus grave pour une app censée ne rien laisser passer |
| `CalDavClientTest` | la perte de l'en-tête `Authorization` sur la redirection de partition (CVE-2022-31090), le 421 du coalescing HTTP/2, le 401 traité comme terminal |
| `OpenAiClientTest` | un refus de sécurité ou une réponse tronquée passés silencieusement pour une analyse réussie ; le retry sur 429 **et** 503 (sémantique 2026) |
| `TodoPriorityTest` | les deux échelles de priorité sont inversées l'une par rapport à l'autre, et la mauvaise valeur sortait de la plage acceptée par `PATCH /api/todos` |
| `ApiSecurityTest` | fuite de documents entre comptes, rejeu d'un jeton d'ingestion, mot de passe d'application iCloud renvoyé ou stocké en clair |

### Banc de l'algorithme de scan

Le pipeline OpenCV vit dans `resources/js/scanner/pipeline.ts`, volontairement
séparé du worker et du DOM : il prend une instance `cv` et des `Mat`, donc il
est exécutable hors navigateur.

```bash
bun scripts/test-pipeline.ts
```

Le script fabrique une photo de synthèse — une feuille vue de biais sur fond
sombre, avec vignettage et lignes de texte — puis vérifie que les quatre coins
sont retrouvés, que le redressement sort aux bonnes dimensions, que la sortie
destinée au serveur est bien en couleur, et qu'une photo floue obtient un score
de netteté nettement inférieur à une photo nette.

Le chargement d'OpenCV (10,8 Mo) prend une trentaine de secondes en ligne de
commande ; c'est normal, et sans rapport avec le temps de chargement dans le
navigateur où le fichier est mis en cache par le service worker.
