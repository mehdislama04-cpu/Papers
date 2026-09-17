import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * Papers — build front.
 *
 * Particularites Laravel (mono-origine, cf. ARCHITECTURE.md §1) :
 *  - les assets hashes sortent dans public/build (base = /build/),
 *  - mais le service worker DOIT etre servi depuis la racine (public/sw.js)
 *    pour avoir un scope "/" : un SW sous /build/ ne peut pas controler "/"
 *    sans en-tete Service-Worker-Allowed, que le serveur statique ne pose pas.
 *    D'ou `outDir: 'public'` cote VitePWA, et un glob de precache qui pointe
 *    sur public/build/**.
 *  - `injectRegister: false` : on enregistre nous-memes dans lib/pwa.ts
 *    (prompt de mise a jour maison, cf. exigences).
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.ts',
            registerType: 'prompt',
            injectRegister: false,
            // Le manifeste est un fichier statique versionne (public/manifest.webmanifest).
            manifest: false,
            // Le SW atterrit dans public/, pas dans public/build/.
            outDir: 'public',
            injectManifest: {
                // Bundle IIFE + enregistrement en `type: 'classic'` : les
                // service workers de type module ne sont supportes qu'a partir
                // de Safari 16.4, et on n'y gagnerait rien ici.
                rollupFormat: 'iife',
                globDirectory: 'public',
                globPatterns: ['build/assets/**/*.{js,css,woff2,svg}'],
                globIgnores: [
                    // OpenCV WASM (~8 Mo) : jamais precache, cache runtime dedie (cf. sw.ts).
                    '**/opencv*.{js,wasm}',
                    '**/*.wasm',
                    'build/manifest.json',
                ],
                // Les entrees de public/build sont deja hashees : pas de revision.
                dontCacheBustURLsMatching: /-[a-zA-Z0-9_-]{8,}\.(js|css|woff2|svg)$/,
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
            devOptions: {
                // Pas de SW en dev : il masque les 419/erreurs reseau qu'on veut voir.
                enabled: false,
                type: 'module',
            },
        }),
    ],
    build: {
        // Cible iOS 16+ (iPhone 14 Plus). Pas de transpilation inutile en dessous.
        target: ['safari16', 'es2022'],
        sourcemap: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
