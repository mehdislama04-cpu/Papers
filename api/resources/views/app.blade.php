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
    {{-- black-translucent : le contenu passe SOUS la barre d'état, d'où les safe-area. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">

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
        :root { color-scheme: light dark; }
        html, body { margin: 0; height: 100%; background-color: #f8fafc; }
        @media (prefers-color-scheme: dark) {
            html, body { background-color: #0b1120; }
        }
        #app { min-height: 100%; }
    </style>

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
