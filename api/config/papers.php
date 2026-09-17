<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    |
    | Consommé par App\Services\Ai\OpenAiClient. On passe par le client HTTP
    | natif de Laravel (Http::...), PAS par openai-php/laravel : cette lib
    | v0.21 exige guzzlehttp/guzzle ^7.9.3 alors que Laravel 13 installe
    | Guzzle 8.2.0 — incompatible, constaté à l'installation. Aucune perte :
    | ce client n'est qu'un passe-plat de tableaux vers du JSON, sans retry,
    | sans parsing des Structured Outputs, avec un timeout par défaut de 30 s
    | bien trop court pour un document multipage.
    |
    */

    'openai' => [

        'api_key' => env('OPENAI_API_KEY'),

        // Sans slash final. Les endpoints sont concaténés : {base_url}/responses
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),

        /*
        | TIMEOUTS — LA SEULE PROTECTION RÉELLE SOUS WINDOWS.
        |
        | Les timeouts de job Laravel reposent sur pcntl_alarm(). L'extension
        | pcntl n'existe pas sur Windows (vérifié : `php -m` ne la liste pas),
        | donc #[Timeout] et `queue:work --timeout` ne sont PAS appliqués par
        | un worker Windows natif. Un appel OpenAI qui pend bloquerait le
        | worker indéfiniment. Borner le client HTTP est la seule garde-fou.
        |
        | Chaîne de timeouts à respecter (cf. config/queue.php) :
        |   retry_after (300) > worker --timeout (240) > #[Timeout] (180) > HTTP (120)
        */
        'timeout' => (int) env('PAPERS_OPENAI_TIMEOUT', 120),
        'connect_timeout' => (int) env('PAPERS_OPENAI_CONNECT_TIMEOUT', 10),

        /*
        | Retries applicatifs.
        |
        | Sémantique 2026 (les handlers écrits avant sont faux) :
        |   - 429 + rate_limit_error + code `slow_down`        -> montée en charge trop rapide
        |   - 503 + service_unavailable_error + `server_is_overloaded` -> modèle saturé
        | Gérer les DEUX. `Retry-After` est un MINIMUM : y ajouter un jitter,
        | sinon tous les workers repartent en même temps.
        */
        'max_attempts' => (int) env('PAPERS_OPENAI_MAX_ATTEMPTS', 3),
        'retry_base_ms' => 2_000,
        'retry_max_ms' => 30_000,
        'retry_jitter_ms' => 1_000,
        'retry_on_status' => [429, 500, 502, 503, 504],

        /*
        |----------------------------------------------------------------------
        | Modèle de vision / extraction
        |----------------------------------------------------------------------
        |
        | CHOIX : gpt-5.6-terra (tier « équilibre », ex-« mini » du lineup 2026).
        |
        | Justification chiffrée (docs/RECHERCHE.md, section research:openai) :
        |
        |   Modèle          Input $/1M   Cached $/1M   Output $/1M
        |   gpt-6-astra          10          1             50
        |   gpt-5.6-sol           4          0.40          20
        |   gpt-5.6-terra         2          0.20          12   <- retenu
        |   gpt-5.6-luna          0.20       0.02           1.20
        |
        | Une A4 scannée à 1654x2339 px en detail:"original" = 52x74 = 3 848
        | patches x 1.2 = ~4 618 tokens image. Avec le JSON d'extraction en
        | sortie (~800 tokens) : ~0,02 $/page sur terra, ~0,002 $ sur luna.
        |
        | luna est 10x moins cher mais nettement plus faible sur les scans
        | dégradés ; il est prévu comme second passage une fois le pipeline
        | stabilisé (tri/classification), pas comme extracteur principal.
        | astra n'est justifié qu'en escalade (manuscrit, tableaux denses).
        | La doc OpenAI note que le tier « mini » 2026 est bien plus solide
        | que gpt-5-mini sur l'OCR de scans dégradés : c'est le meilleur
        | rapport qualité/prix pour de l'extraction documentaire.
        */
        'vision' => [
            'model' => env('PAPERS_VISION_MODEL', 'gpt-5.6-terra'),

            // terra et luna acceptent : none | low | medium | high | xhigh | max.
            // (gpt-6-astra ne supporte PAS "none".)
            // low = un minimum de raisonnement pour la mise en relation
            // date/montant/émetteur, sans le coût de "medium".
            'reasoning_effort' => env('PAPERS_VISION_REASONING_EFFORT', 'low'),

            // "auto" = tarif plein, synchrone, c'est ce qu'il faut quand
            // l'utilisateur attend devant son écran (scan -> résultat).
            // "flex" = tarif Batch (-50 %) en synchrone, mais plus lent et
            // occasionnellement indisponible : à réserver au ré-traitement
            // de fond, avec un timeout HTTP porté à 900 s.
            'service_tier' => env('PAPERS_VISION_SERVICE_TIER', 'auto'),

            'max_output_tokens' => (int) env('PAPERS_VISION_MAX_OUTPUT_TOKENS', 8_000),

            // La doc OpenAI recommande explicitement "original" pour l'OCR.
            // "high" écraserait l'image dans 2048x2048 ET 2 500 patches, ce qui
            // détruit le petit texte d'une A4 (mentions légales, IBAN, n° de
            // facture). Ne pas changer sans relire research:openai §3.
            'detail' => 'original',
        ],

        // Modèle d'escalade, utilisé seulement si la validation métier échoue.
        'escalation' => [
            'model' => env('PAPERS_ESCALATION_MODEL', 'gpt-5.6-sol'),
            'reasoning_effort' => env('PAPERS_ESCALATION_REASONING_EFFORT', 'medium'),
        ],

        /*
        | Embeddings.
        |
        | text-embedding-3-large (3072 dimensions) NE RENTRE PAS dans un index
        | HNSW pgvector : la limite du type `vector` est 2 000 dimensions.
        | On reste donc sur -3-small / 1536, qui est aussi la valeur passée à
        | $table->vector('embedding', dimensions: 1536) dans la migration.
        | Changer l'un sans l'autre casse les insertions.
        */
        'embedding' => [
            'model' => env('PAPERS_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dimensions' => (int) env('PAPERS_EMBEDDING_DIMENSIONS', 1536),

            // Au-delà, le texte est tronqué avant l'appel (un document de
            // 40 pages dépasse la fenêtre du modèle d'embedding).
            'max_input_chars' => 24_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets d'image
    |--------------------------------------------------------------------------
    |
    | Le coût d'un appel vision dépend des DIMENSIONS, pas du nombre de canaux :
    | on envoie donc l'image grise corrigée, jamais la binarisée 1-bit (qui
    | ferait perdre tampons, surlignages, encre colorée et signatures sans rien
    | économiser). Le N&B reste réservé à l'aperçu écran.
    |
    */

    'images' => [

        // Cible après redressement de perspective, côté PWA comme côté serveur.
        // ~1600x2200 -> 50x69 = 3 450 patches -> ~4 140 tokens facturés.
        // Monter à 300 dpi pleine résolution coûte linéairement en tokens
        // sans gain OCR au-delà de ~200 dpi.
        'target_width' => 1_600,
        'target_height' => 2_200,
        'jpeg_quality' => 85,

        /*
        | GARDE-FOU DUR : au-delà de 30 000 patches, la requête est REJETÉE
        | par l'API, pas redimensionnée. Il faut donc redimensionner AVANT
        | l'envoi. Formule officielle (patches de 32x32) :
        |
        |   patch_count = ceil(w/32) * ceil(h/32)
        |   si patch_count > budget :
        |       shrink = sqrt((32^2 * budget) / (w*h))   puis recalcul
        |   billable_tokens = ceil(patch_count_final * multiplier)
        |
        | multiplier = 1.2 pour gpt-6-astra, gpt-5.6-* et gpt-5.x.
        */
        'patch_size' => 32,
        'max_patches' => 30_000,
        'token_multiplier' => 1.2,

        // Limites d'upload. 1 500 images max par requête OpenAI et 512 Mo de
        // payload : ce n'est pas la contrainte ici, c'est la mémoire PHP.
        'max_pages_per_document' => (int) env('PAPERS_MAX_PAGES_PER_DOCUMENT', 30),
        'max_page_size_kb' => (int) env('PAPERS_MAX_PAGE_SIZE_KB', 12_288),
        'accepted_mimes' => ['image/jpeg', 'image/png'],

        // Miniatures. WebP ici est sûr : c'est GD/Imagick côté serveur.
        // Côté navigateur en revanche, canvas.toBlob('image/webp') retombe
        // SILENCIEUSEMENT sur PNG dans Safari -> le front sort du JPEG.
        'thumbnail_width' => 400,
        'thumbnail_quality' => 70,
    ],

    /*
    |--------------------------------------------------------------------------
    | CalDAV iCloud
    |--------------------------------------------------------------------------
    |
    | Consommé par App\Services\CalDav\CalDavClient et IcsBuilder.
    | Aucune requête CalDAV ne part jamais du navigateur : Apple ne renvoie
    | pas d'en-têtes CORS, et cela exposerait le mot de passe d'application.
    |
    */

    'caldav' => [

        // Point d'entrée de la découverte. Le calendar-home-set renvoie ensuite
        // une PARTITION numérotée (https://pNN-caldav.icloud.com/{dsid}/calendars/)
        // qu'il faut persister par compte : elle diffère d'un utilisateur à l'autre.
        'base_url' => env('PAPERS_CALDAV_BASE_URL', 'https://caldav.icloud.com'),

        'timeout' => (int) env('PAPERS_CALDAV_TIMEOUT', 30),
        'connect_timeout' => (int) env('PAPERS_CALDAV_CONNECT_TIMEOUT', 10),

        /*
        | DEUX PIÈGES QUI CASSENT TOUTE IMPLÉMENTATION NAÏVE.
        |
        | 1. Depuis CVE-2022-31090, Guzzle SUPPRIME l'en-tête Authorization dès
        |    qu'une redirection change d'hôte -> 401 systématique au moment de
        |    basculer sur la partition pNN. Et sans l'option `strict`, Guzzle
        |    transforme un PROPFIND redirigé en GET sans corps.
        |    => allow_redirects = false, et on suit les 301/302 à la main en
        |       réémettant l'Authorization (d'où 'max_redirects' ci-dessous).
        |
        | 2. HTTP/2 provoque des 421 Misdirected Request aléatoires chez Apple
        |    (coalescing de connexion TLS sur les partitions).
        |    => forcer HTTP/1.1 (CURL_HTTP_VERSION_1_1).
        */
        'allow_redirects' => false,
        'max_redirects' => 5,
        'http_version' => '1.1',

        // Calendrier dédié, créé par MKCALENDAR s'il n'existe pas.
        // ensurePapersCalendar() renvoie son URL et doit être idempotent.
        'calendar_name' => env('PAPERS_CALDAV_CALENDAR_NAME', 'Papers — Échéances'),
        'calendar_color' => '#0B84FF',

        // Les Rappels iCloud (VTODO) sont inexploitables via CalDAV depuis
        // iOS 13 : les todos restent dans l'app, on ne pousse que des VEVENT
        // porteurs de VALARM.
        'event_uid_format' => 'papers-todo-%s@papers.app',

        // Minutes AVANT l'échéance. Valeurs négatives = TRIGGER;RELATED=START
        // négatif en ICS (-P1D et -PT1H).
        'default_alarms' => [-1_440, -60],

        // DTEND d'un événement all-day est EXCLUSIF : il faut J+1, sinon
        // l'événement est purement invisible dans Calendrier iOS.
        'all_day_dtend_is_exclusive' => true,

        'timezone' => env('PAPERS_CALDAV_TIMEZONE', 'Europe/Paris'),

        // Un 401 est un état TERMINAL (mot de passe d'application révoqué, ou
        // mot de passe Apple principal changé — ce qui révoque les 25 d'un
        // coup). On marque le compte invalid_credentials et on notifie.
        // Surtout pas de boucle de retry : Apple finit par verrouiller.
        'retry_on_status' => [500, 502, 503, 504],
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jetons d'ingestion (pont « scanner natif Apple » via Raccourcis)
    |--------------------------------------------------------------------------
    |
    | Le scanner de Notes (VisionKit) n'est accessible par aucune API web, et
    | le Web Share Target n'existe pas sur iOS. Le seul pont praticable :
    |   1. la PWA appelle POST /api/ingest/token
    |   2. elle ouvre shortcuts://run-shortcut?name=Papers&input=text&text=<jeton>
    |   3. le raccourci POSTe le PDF sur /api/ingest/shortcut avec le jeton en
    |      Authorization: Bearer <token>
    |   4. il se termine par l'action « Ouvrir l'app ».
    |
    | Le raccourci est PARTAGÉ entre tous les utilisateurs : il ne doit jamais
    | contenir de clé d'API, tout passe par ce jeton à usage unique.
    |
    */

    'ingest' => [

        // TTL court : le jeton ne sert qu'à couvrir l'aller-retour vers
        // Raccourcis. 10 min laisse le temps de scanner plusieurs pages sans
        // ouvrir une fenêtre d'attaque.
        'token_ttl' => (int) env('PAPERS_INGEST_TOKEN_TTL', 600),

        // Usage unique, haché au repos (hash::make) : la valeur en clair n'est
        // renvoyée qu'une fois, à la création.
        'single_use' => true,

        // Le nom du raccourci est son SEUL identifiant. S'il est renommé par
        // l'utilisateur, le lancement échoue silencieusement -> le front doit
        // prévoir un timeout et un repli sur l'upload classique.
        'shortcut_name' => env('PAPERS_SHORTCUT_NAME', 'Papers'),
        'shortcut_url_template' => 'shortcuts://run-shortcut?name=%s&input=text&text=%s',

        'max_file_size_kb' => (int) env('PAPERS_INGEST_MAX_FILE_SIZE_KB', 51_200),
        'accepted_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],

        // Le PDF produit par Raccourcis s'appelle presque toujours
        // « Scanned Document.pdf » : on renomme côté serveur et on ne fait
        // jamais confiance au filename reçu.
        'fallback_filename' => 'scan.pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recherche
    |--------------------------------------------------------------------------
    |
    | whereFullText() remplace SILENCIEUSEMENT la langue par 'english' si elle
    | n'est pas dans validFullTextLanguages() (PostgresGrammar, l. 166-168) —
    | aucune exception levée. 'french' y est, mais pas notre config custom
    | 'fr_unaccent'. D'où une colonne tsvector GÉNÉRÉE, alimentée par
    | to_tsvector('fr_unaccent', ...), interrogée avec
    | whereFullText($col, $q, ['vector' => true]) qui utilise la colonne telle
    | quelle sans réinjecter de langue.
    |
    */

    'search' => [
        'ts_config' => 'fr_unaccent',
        'vector_column' => 'search_vector',
        'per_page' => 20,

        // Distance cosinus (<=>) : 0 = identique, 2 = opposé.
        // Au-delà de ce seuil, le résultat vectoriel n'est pas remonté.
        'vector_max_distance' => 0.55,
        'vector_limit' => 50,
    ],

];
