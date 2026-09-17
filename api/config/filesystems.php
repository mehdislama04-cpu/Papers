<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    | PIÈGE « serve » — À LIRE AVANT D'AJOUTER UN DISQUE LOCAL.
    |
    | 'serve' => true fait enregistrer par FilesystemServiceProvider une route
    | GET {uri}/{path} (où {path} est capturé en '.*'). L'URI vaut le chemin de
    | la clé 'url' du disque, et « /storage » quand 'url' est absente.
    | Deux disques locaux servis SANS 'url' distincte lèvent au boot :
    |   « The [documents] disk conflicts with the [local] disk at [/storage]. »
    | Et même sans exception, la route « /storage/{path} » du premier disque
    | capturerait « /storage/documents/... » à cause du where('path', '.*').
    |
    | D'où : le disque « documents » a sa propre 'url' (/files/documents) et le
    | disque « local » ne sert plus rien.
    |
    */

    'disks' => [

        // Disque de travail interne (fichiers temporaires, exports en cours).
        // 'serve' repassé à false : rien ici n'a vocation à être exposé, et le
        // laisser à true entrerait en collision avec le disque « documents »
        // sur l'URI /storage (voir l'encadré ci-dessus).
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | documents — disque PRIVÉ des scans et de leurs miniatures
        |----------------------------------------------------------------------
        |
        | Jamais public : un document papier, c'est une facture, un bulletin de
        | salaire, un courrier médical. L'accès passe par une URL SIGNÉE à durée
        | limitée, plus une policy côté contrôleur.
        |
        | 'serve' => true est REQUIS pour que Storage::temporaryUrl() fonctionne
        | sur le driver local (sans quoi : « This driver does not support
        | creating temporary URLs »). La route générée valide la signature via
        | $request->hasValidRelativeSignature() — signature RELATIVE, donc elle
        | survit au changement d'hôte quand on passe du localhost au tunnel.
        |
        | 'throw' => true : une écriture qui échoue doit lever, pas retourner
        | false silencieusement et laisser un Document sans fichier.
        |
        | En production, le même code marche à l'identique en remplaçant ce bloc
        | par un disque s3 'visibility' => 'private' (+ SSE-KMS côté bucket) :
        | temporaryUrl() y est natif.
        |
        */
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/files/documents',
            'serve' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
