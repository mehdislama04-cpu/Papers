<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name + RÈGLE DE TIMEOUT — À LIRE AVANT DE TOUCHER retry_after
    |--------------------------------------------------------------------------
    |
    | Chaîne à respecter, du plus large au plus serré :
    |
    |   retry_after (300 s, ici)
    |     > queue:work --timeout (240 s, cf. README)
    |       > #[Timeout] du job (180 s)
    |         > Http::timeout() du client OpenAI (120 s, cf. config/papers.php)
    |
    | POURQUOI retry_after DOIT ÊTRE SUPÉRIEUR AU TIMEOUT DU WORKER :
    | retry_after est le délai au bout duquel la queue considère un job réservé
    | comme perdu et le REND DISPONIBLE à un autre worker. Si retry_after est
    | inférieur au timeout, le job est redistribué à un second worker PENDANT
    | qu'il tourne encore : deux appels OpenAI pour le même document, facturés
    | deux fois, et deux écritures concurrentes sur la même extraction.
    | Avec la valeur par défaut de Laravel (90 s) et un appel vision qui peut
    | durer 2 minutes sur un multipage, le bug est systématique, pas théorique.
    |
    | PIÈGE WINDOWS, ET IL EST MAJEUR :
    | les timeouts de job Laravel reposent sur pcntl_alarm(). L'extension pcntl
    | n'existe pas sur Windows (vérifié sur cette machine : absente de `php -m`).
    | Donc #[Timeout] et --timeout ne sont PAS APPLIQUÉS par un worker Windows
    | natif : un appel OpenAI qui pend bloque le worker indéfiniment, et c'est
    | retry_after qui finira par relâcher le job — pendant que le premier worker
    | est toujours dessus. D'où, à nouveau, la double facturation.
    | La seule protection réelle sous Windows est de borner le client HTTP :
    | Http::timeout(120)->connectTimeout(10) dans OpenAiClient.
    | Voir config/papers.php -> papers.openai.timeout.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // 300 > --timeout 240. Repli utilisable tel quel si l'extension
            // phpredis n'est pas installée (cf. README, étape 1).
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 300),
            // Un job dispatché dans une transaction ne doit pas partir avant
            // le COMMIT : sinon le worker lit un Document qui n'existe pas
            // encore et échoue en ModelNotFoundException.
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),

            // 300 s > --timeout 240 s. Voir l'encadré en haut de ce fichier :
            // toute valeur inférieure au timeout du worker provoque un second
            // appel OpenAI facturé sur le même document.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 300),

            // BRPOP bloquant 5 s au lieu d'un polling serré : moins d'aller-retour
            // Redis et latence quasi nulle à la prise de job. Doit rester très
            // inférieur à retry_after.
            'block_for' => 5,

            'after_commit' => true,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
