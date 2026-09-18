<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messages de validation
|--------------------------------------------------------------------------
|
| L'application est en français et ne publiait AUCUN fichier de langue : toutes
| les erreurs sortaient en clés brutes — « validation.required » — au login, à
| l'inscription, au scanner et partout ailleurs. Un utilisateur ne peut rien
| faire d'un message pareil.
|
| Ce fichier ne couvre que les règles réellement employées par les FormRequest
| de l'app, pas les cent-vingt règles de Laravel. Le reste retombe sur
| `fallback_locale` (en), désormais publié : une phrase anglaise correcte plutôt
| qu'une clé. Ajouter une règle ici est la bonne réaction le jour où l'une des
| manquantes se met à servir.
|
*/

return [
    'array' => 'Le champ :attribute doit être une liste.',
    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit valoir vrai ou faux.',
    'confirmed' => 'La confirmation ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute n’est pas une date valide.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists' => 'La valeur choisie pour :attribute n’existe pas.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur choisie pour :attribute n’est pas autorisée.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file' => 'Le fichier :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],
    'mimetypes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le fichier :attribute n’a pas pu être envoyé. Il est peut-être trop lourd.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'uuid' => 'Le champ :attribute doit être un identifiant valide.',

    'custom' => [],

    /*
    | Noms affichés. Sans cette table, « Le champ photo est obligatoire »
    | devient « Le champ key est obligatoire » : on renvoie à l'utilisateur le
    | nom d'un champ de formulaire qu'il n'a jamais vu.
    */
    'attributes' => [
        'aliases' => 'graphies',
        'apple_id' => 'identifiant Apple',
        'app_password' => 'mot de passe d’application',
        'category' => 'catégorie',
        'details' => 'détails',
        'due_at' => 'échéance',
        'email' => 'adresse e-mail',
        'key' => 'personne',
        'name' => 'nom',
        'page' => 'page',
        'pages' => 'pages',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'per_page' => 'nombre par page',
        'photo' => 'photo',
        'priority' => 'priorité',
        'search' => 'recherche',
        'source' => 'origine',
        'status' => 'statut',
        'title' => 'titre',
    ],
];
