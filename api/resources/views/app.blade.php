<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">

    {{--
        viewport-fit=cover : l'iPhone 14 Plus a une encoche. Sans cette valeur,
        les variables env(safe-area-inset-*) valent 0 et l'app ne peut pas
        déborder proprement sous la barre d'état.

        Volontairement PAS de user-scalable=no : iOS l'ignore depuis iOS 10, et
        cela ne ferait que dégrader l'accessibilité là où il est respecté.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- Les numéros d'un document scanné ne doivent pas devenir des liens « appeler ». --}}
    <meta name="format-detection" content="telephone=no">

    <title>{{ config('app.name', 'Papers') }}</title>

    {{--
        Jeton CSRF de secours.

        Le chemin nominal est le cookie XSRF-TOKEN posé par Sanctum : le client
        doit l'URL-DÉCODER avant de le placer dans X-XSRF-TOKEN (fetch ne le
        fait pas, contrairement à axios). Cette balise sert au tout premier
        appel, avant que /sanctum/csrf-cookie n'ait répondu.
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- PWA installée : plein écran, sans chrome Safari. --}}
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Papers') }}">
    {{--
        `default`, et surtout PAS `black-translucent`.

        black-translucent fait passer le contenu sous la barre d'état et y
        dessine des glyphes blancs : depuis que l'app est en clair, l'heure et
        la batterie devenaient invisibles sur un fond presque blanc. Il forçait
        aussi 47 pt de safe-area haute, qui se lisaient comme une bande vide
        au-dessus du grand titre.

        Avec `default`, iOS insère la vue sous une barre d'état opaque et
        lisible, et env(safe-area-inset-top) retombe à 0 — la bande disparaît
        d'elle-même. Le bas ne bouge pas : viewport-fit=cover continue de
        donner les 34 pt de l'indicateur d'accueil.
    --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    {{-- Une seule valeur, sans media : l'app est en clair quel que soit le
         reglage de l'appareil (cf. resources/css/app.css). --}}
    <meta name="theme-color" content="#f3f4f7">

    <meta name="description" content="Numérisez, classez et suivez vos documents papier.">
    {{-- Des documents privés n'ont rien à faire dans un index. --}}
    <meta name="robots" content="noindex, nofollow">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" href="/favicon.ico" sizes="any">

    {{--
        Anti-flash : la coquille est servie avant que le bundle n'ait monté
        quoi que ce soit. Sans ces quelques lignes, l'écran est blanc au
        lancement depuis l'icône, y compris en thème sombre.
    --}}
    <style>
        /* L'app est en clair, toujours. `light` seul empeche un appareil en
           sombre de repeindre le fond et les controles natifs avant que la
           feuille de styles n'arrive. */
        :root { color-scheme: light; }
        /* Equivalent sRGB de --color-bg (resources/css/app.css) :
           oklch(0.967 0.004 271). A garder aligne. */
        html, body { margin: 0; height: 100%; background-color: #f3f4f7; }
        #app { min-height: 100%; }
    </style>

    {{--
        Préambule React Refresh. Obligatoire AVANT @vite dès qu'on sert du JSX
        via @vitejs/plugin-react : sans lui le plugin lève « can't detect
        preamble » et l'app ne monte pas du tout en dev. La directive n'émet
        rien en production.
    --}}
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="h-full antialiased">
    {{--
        Point de montage unique du front (React 19 + react-router v7 en mode
        History API). Le routage par hash est PROSCRIT : sur iOS, la permission
        caméra d'une PWA installée est révoquée à chaque changement de hash
        (ARCHITECTURE.md §3), ce qui reprompterait à chaque navigation.
    --}}
    <div id="app"></div>

    <noscript>
        <p style="font-family: system-ui, sans-serif; padding: 1.5rem;">
            Papers a besoin de JavaScript pour fonctionner.
        </p>
    </noscript>
</body>
</html>
