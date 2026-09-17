# Dossier de recherche technique — Papers

Généré le 17/09/2026 à partir de 7 agents de recherche avec accès web.



---

# research:ios-pwa

## Synthèse

## Contexte temporel (vérifié le 17/09/2026)

- **iOS 27 / Safari 27 sont sortis le 14 septembre 2026** (build 27.0, 20625.1.29). iOS 26 est sorti le 15/09/2025. Donc le parc réel en sept. 2026 = iOS 26.x majoritaire + iOS 27 en début de déploiement, avec une longue traîne iOS 18.x.
- **L'iPhone 14 Plus est compatible iOS 27** (iOS 27 supporte iPhone 11 et plus récents + SE 2/3). Pas d'Apple Intelligence dessus (réservé 15 Pro / 16+), ce qui est sans impact ici puisque l'analyse passe par l'API OpenAI côté serveur.
- ⚠️ **Correction importante du brief : l'iPhone 14 Plus n'a PAS de Dynamic Island.** Il a une **encoche (notch)**. Le Dynamic Island est réservé aux 14 Pro / 14 Pro Max et à toute la gamme 15+. Toute la conception UI "Dynamic Island" est à abandonner ; il faut raisonner encoche + home indicator.
- Safari 27 (WWDC26) : 58 nouveautés, 525 correctifs, 4 dépréciations — **rien** sur share_target, background sync, torch/ImageCapture. Le gros apport côté calcul est **WebAssembly JSPI**. Safari 26.0 avait apporté 75 features (anchor positioning, WebGPU, `<model>`, Digital Credentials, scroll-driven animations) + l'interpréteur Wasm "in-place" (démarrage plus rapide, moins de mémoire).

---

## 1. getUserMedia en PWA standalone : ça marche, mais c'est le point fragile n°1

**Ça marche** (Camera/Micro supporté depuis iOS 13.0, y compris en `display: standalone`), MAIS :

- **Bug historique WebKit #252465** — `<video>` ne joue pas le flux `getUserMedia()` dans une web app Home Screen alors que le même code marche en Safari. Marqué **RESOLVED FIXED** (PR mergée le 12/07/2023 par youenn fablet) mais **des régressions sont rapportées jusqu'en iOS 18.5** (juin 2025) : écran noir, événement `suspend` qui ne progresse jamais, icône caméra qui clignote sans point vert. Statut "resolved" ≠ réalité terrain.
- **Bug WebKit #215884** — **la permission caméra n'est PAS persistée pour les PWA** : re-prompt à chaque lancement, voire à chaque navigation interne. C'est le bug qui tue l'UX d'un scanner. Aucun correctif confirmé en 2026.
- **Bug WebKit #179363** — rappeler `getUserMedia()` une 2e fois tue l'affichage du 1er flux. Conséquence : ne jamais ré-appeler `getUserMedia` sans `track.stop()` explicite sur l'ancien flux.
- **Perte du flux à la navigation SPA** : changer de route casse le flux. → garder le composant caméra monté, ne jamais démonter le `<video>`.

**Conséquence d'architecture : prévoir DEUX chemins de capture** (getUserMedia live + `<input capture>`) et basculer automatiquement si le flux ne démarre pas en < 2-3 s.

### Contraintes utiles et limites
- `facingMode: { exact: "environment" }` fonctionne. En revanche **iOS n'expose pas de `deviceId` / `label` utilisables** (chaînes vides pour des raisons de vie privée) → impossible de sélectionner explicitement l'objectif grand-angle.
- **iOS bascule tout seul entre wide / ultra-wide / télé** quand on approche le document (comportement "macro" auto d'AVFoundation). Il n'existe **aucun moyen via getUserMedia/WebRTC de forcer `.builtInWideAngleCamera`** (possible seulement en natif). Sur un scan de document rapproché, ça produit des changements de champ / flous intermittents. Mitigation : demander à l'utilisateur de rester à ~25-30 cm, et faire de la détection de netteté (variance de Laplacien) avant déclenchement.
- **Résolution** : pas de plafond fixe ; iOS négocie silencieusement vers le mode le plus proche. Le cap "720p" que l'on lit partout vient de iOS 11 et est obsolète. En pratique sur iPhone récents on obtient 1920×1080 facilement, et 3840×2160 est atteignable sur la caméra arrière selon le device. **Règle absolue : toujours relire `video.videoWidth/videoHeight` après démarrage**, jamais faire confiance aux contraintes demandées.
- **Autofocus/exposition** : non pilotables depuis le web sur iOS. Le tap-to-focus n'existe pas.

---

## 2. getCapabilities / applyConstraints / torch : NON exploitables sur iOS

- `getCapabilities()` **existe** mais **ne retourne ni `torch`, ni `zoom`, ni `focusMode`, ni `exposureMode`** sur iOS. Il renvoie essentiellement width/height/frameRate/facingMode/aspectRatio.
- **`torch` (flash) est impossible sur iOS**, tous navigateurs confondus (tous forcés sur WebKit). Confirmé encore en 2026, aucun changement dans Safari 26 ni 27.
- **`ImageCapture` (takePhoto / grabFrame / getPhotoCapabilities) n'est pas implémenté** dans Safari stable (macOS/iOS), y compris Safari 26/27. Du scaffolding partiel existe en STP mais rien n'est shippé.

→ **La seule façon de capturer une image fixe depuis le flux live sur iOS est `canvas.drawImage(video, ...)`**. Pas de capture "photo haute résolution supérieure à la preview".
→ **Pas de bouton flash dans l'UI.** Prévoir plutôt un guidage lumière + un rehaussement logiciel (CLAHE / adaptive threshold).

---

## 3. `<input type="file" capture accept="image/*">` : le chemin de repli fiable

Comportement exact sur iOS Safari / web app :
- `accept="image/*"` sans `capture` → **feuille d'action à 3 entrées** : *Photothèque* / *Prendre une photo ou une vidéo* / *Choisir un fichier*.
- `capture="environment"` → ouvre **directement l'appareil photo** (caméra arrière), sans feuille d'action. `capture="user"` → caméra frontale. C'est l'UI Camera native d'Apple, donc **vraie AF, vrai flash, vraie stabilisation, pleine résolution du capteur** — bien meilleure qualité d'image que getUserMedia.
- ⚠️ **`capture` supprime la possibilité de choisir un fichier existant.** Prévoir deux inputs distincts.
- ⚠️ **`capture` ne permet PAS le multi-page** : une seule photo par déclenchement. `multiple` est ignoré avec `capture`. → boucler côté JS (l'utilisateur relance l'input pour chaque page).

### HEIC / JPEG — règle exacte
- Safari **convertit automatiquement** le HEIC natif vers un des types listés dans `accept`. Si `accept` contient `image/jpeg` (et **pas** `image/heic`), le `File` arrive en **JPEG**.
- **Piège Safari 17+ (toujours valable)** : si vous mettez `image/heic` **dans** `accept`, Safari fait l'inverse et vous renvoie du **HEIC** (y compris en convertissant des PNG/JPEG vers l'extension `.heic`). **Ne jamais mettre `image/heic` dans `accept`.**
- Recommandation : `accept="image/jpeg,image/png"`. Et côté Laravel, garder quand même un décodeur HEIC de secours (Imagick + libheif) pour les fichiers arrivant par "Choisir un fichier" / iCloud Drive.

---

## 4. "Scanner un document" depuis un `<input type=file>` : NON — réponse factuelle

**Non vérifiable positivement, et la réponse pratique est non.**
- iOS possède bien un scanner système (Fichiers → bouton "..." → **Numériser des documents** ; en iOS 26 il ajoute flash + filtres d'image), basé sur VisionKit `VNDocumentCameraViewController`.
- Mais **le `UIDocumentPickerViewController` présenté par Safari pour un `<input type="file">` est en mode *picker*, pas en mode *browser* complet**. La feuille d'action de Safari n'expose que *Photothèque / Prendre une photo ou une vidéo / Choisir un fichier*. **Aucune source ne documente une entrée "Numériser des documents" dans ce contexte**, et aucune API web ne permet de déclencher VisionKit.
- Le seul moyen pour l'utilisateur d'utiliser le scanner Apple est : scanner dans l'app Fichiers/Notes → enregistrer le PDF → revenir dans la PWA → `<input type="file" accept="application/pdf">` → Choisir un fichier. C'est un parcours en 6 tapes, inacceptable comme chemin principal, mais **c'est le meilleur chemin "qualité maximale"** à proposer en option ("Importer un PDF déjà scanné").

→ **Il faut donc implémenter le vrai scan (bords + perspective + binarisation) soi-même, dans la PWA.**

---

## 5. Web Share Target : NON supporté sur iOS, et aucun signe de changement

- `share_target` dans le manifest est **ignoré par iOS**. La PWA **n'apparaît jamais dans la feuille de partage iOS**. Rien dans Safari 26 ni 27.
- `navigator.share()` (Web Share **API**, sens sortant) fonctionne depuis iOS 12.1 — c'est l'inverse dont vous avez besoin.

### Alternatives réelles pour recevoir un PDF depuis la feuille de partage
1. **Raccourci Apple Shortcuts distribué via lien iCloud** (la meilleure option no-native). Le raccourci apparaît dans la feuille de partage, prend le PDF/URL en entrée, et fait un `POST multipart` vers votre API Laravel avec un token. Import en 1 tap pour l'utilisateur, token demandé à l'import du raccourci. C'est l'approche documentée par plusieurs projets.
2. **`<input type="file" accept="application/pdf">` + "Choisir un fichier"** → l'utilisateur va chercher le PDF dans Fichiers/iCloud Drive. Fonctionne toujours, mais pull au lieu de push.
3. **Wrapper natif (Capacitor + Share Extension + App Group)** si vous acceptez de passer par l'App Store — hors périmètre PWA.

---

## 6. Web Push / Notification API — état 2026

**Ça marche, et c'est la brique clé pour vos rappels.** Règles exactes :

- Depuis **iOS 16.4**, Web Push fonctionne **uniquement dans une web app ajoutée à l'écran d'accueil**. **Pas de push dans l'onglet Safari sur iPhone.**
- Le manifest doit avoir `display: standalone` (ou `fullscreen`). `id` recommandé (sync des réglages Focus entre appareils, multi-installations).
- `Notification.requestPermission()` **doit être déclenché par une interaction utilisateur directe** (tap sur un bouton). Un appel au chargement échoue silencieusement.
- **VAPID standard** (aucune adhésion à l'Apple Developer Program requise, aucun certificat APNs). Votre serveur doit pouvoir joindre `*.push.apple.com`.
- **Badging API** (`navigator.setAppBadge/clearAppBadge`) supportée depuis 16.4 → parfait pour "3 documents à traiter".
- **UE : plus aucun problème.** Apple a fait machine arrière le 01/03/2024 ; les Home Screen web apps et le push **sont restés disponibles dans l'UE** dans iOS 17.4 final. Les articles disant "pas de push dans l'UE" sont périmés (c'est un point critique vu que vous êtes en France).
- **Declarative Web Push** : dispo **iOS/iPadOS 18.4+** (mars 2025) pour les web apps Home Screen, macOS Safari 18.5+. Push affiché **sans service worker**, donc immunisé aux bugs de SW iOS. Coexiste avec un SW : si un SW est installé il reçoit quand même un `PushEvent` et peut remplacer la notif ; s'il échoue, la notif déclarative s'affiche quand même → **plus de "silent push penalty"**.
- **iOS 26 change la donne côté install** : *tout* site ajouté à l'écran d'accueil s'ouvre comme web app par défaut (toggle "Ouvrir comme app web" activé par défaut). Plus aucune exigence d'"installabilité". Donc le push devient accessible plus facilement — mais il faut toujours l'ajout manuel à l'écran d'accueil.

---

## 7. Background Sync / Periodic Sync / Background Fetch : NON, aucun, aucun horizon

- **Background Sync : non supporté** sur iOS/iPadOS/macOS, toutes versions, y compris Safari 26/27. WebKit n'a même pas de position publique sur la spec, et aucun flag "Experimental Features".
- **Periodic Background Sync : non** (bug WebKit #204117 ouvert depuis 2019).
- **Background Fetch : non.**

→ **Toute la logique de synchronisation, OCR, appel OpenAI, création d'événements CalDAV doit être côté serveur (queues Laravel / Horizon).** La PWA ne fait qu'uploader puis recevoir un push. Les retries d'upload se font au prochain foreground (`visibilitychange` + file d'attente IndexedDB).

---

## 8. Stockage : quotas réels et éviction (la doc "50 Mo / 7 jours" circulant partout est FAUSSE)

Source faisant autorité = WebKit "Updates to Storage Policy" :
- **Quota par origine : jusqu'à 60 % de l'espace disque total** pour les apps navigateur.
- **Quota global tous origines : jusqu'à 80 % du disque.**
- (Pour les apps non-navigateur affichant du web : 15 % par origine / 20 % global.)
- Iframes cross-origin : 10 % du quota de l'origine du main frame.
- **Une web app standalone (Home Screen) a EXACTEMENT le même quota qu'en navigateur** — donc ~60 % du disque, pas 50 Mo.
- Types couverts : **localStorage, Cache API, IndexedDB, Service Worker, File System (OPFS)**. Cookies et cache HTTP exclus.
- **Éviction** : LRU, déclenchée par dépassement du quota global, pression disque système, ou absence d'interaction (via ITP).

**Règle des 7 jours (ITP)** : les données créées par script sont supprimées après **7 jours d'utilisation du navigateur sans interaction** avec l'origine. **Les web apps ajoutées à l'écran d'accueil en sont exemptées** : elles ne font pas partie de Safari et ont leur propre compteur de jours d'utilisation, remis à zéro à chaque usage réel de la web app. Donc pour votre cas (usage régulier, installé) le risque est faible — mais **non nul** en cas d'abandon prolongé.

- `navigator.storage.persist()` : WebKit accorde le mode persistant **par heuristique, notamment si le site est ouvert comme Home Screen Web App**. À appeler quand même.
- `navigator.storage.estimate()` : reporting de quota disponible depuis **iOS 17**.

→ **Ne jamais considérer l'appareil comme source de vérité.** PostgreSQL est la source de vérité ; IndexedDB/OPFS = cache + file d'upload seulement.

---

## 9. File System Access API / OPFS

- **OPFS : supporté depuis iOS/Safari 15.2** (`navigator.storage.getDirectory()`), y compris `FileSystemSyncAccessHandle` (en Web Worker uniquement).
- **`showOpenFilePicker` / `showSaveFilePicker` / `showDirectoryPicker` : NON supportés sur iOS.** Aucun accès au disque de l'utilisateur.
- Safari 26 ajoute **File System WritableStream** (écriture en flux dans OPFS) — utile pour écrire des scans multipages volumineux sans tout tenir en mémoire.
- Safari 27 corrige `FileSystemDirectoryHandle.resolve()` et `.removeEntry()`.
- **OPFS indisponible en navigation privée.**

→ Stocker les pages scannées en OPFS (Blob JPEG/WebP) plutôt qu'en IndexedDB : meilleures perfs, écriture en flux, pas de sérialisation structurée.

---

## 10. WASM : SIMD oui, threads oui mais coûteux — impact OpenCV.js

- **Wasm SIMD fixe 128 bits : supporté depuis iOS/Safari 16.4** (mars 2023). Gain typique 2× à 4× sur les filtres image. Sur iOS ≤ 16.3 : fallback scalaire silencieux (~2× plus lent).
- **Wasm threads : supportés, MAIS uniquement en contexte cross-origin isolé**, donc `Cross-Origin-Opener-Policy: same-origin` + `Cross-Origin-Embedder-Policy: require-corp` (COOP/COEP shippés dans Safari 15.2). `SharedArrayBuffer` n'est exposé qu'à cette condition. Safari mobile est **plus strict que Chrome sur les variantes de COEP acceptées** → privilégier `require-corp` plutôt que `credentialless`.
- ⚠️ Activer COOP/COEP casse **toutes** les ressources cross-origin sans CORP/CORS (CDN, polices Google, images tierces, iframes). Sur une PWA de scan c'est acceptable si vous self-hostez tout.
- **Safari 27 ajoute JSPI** (WebAssembly JavaScript Promise Integration) : du code Wasm synchrone peut suspendre et attendre une Promise JS. Utile pour un pipeline de traitement sans bloquer.
- **Safari 26 : nouvel interpréteur Wasm "in-place"** → démarrage nettement plus rapide et moins de mémoire pour les gros modules — exactement le profil OpenCV.js.
- **Taille OpenCV.js : c'est le vrai problème.** Build complet ≈ **8-12 Mo** (le `.wasm` seul ≈ 5,3 Mo contre 10,4 Mo en asm.js). Sur mobile c'est rédhibitoire en démarrage à froid.
  - Faire un **build custom** avec `--disable_single_file` (wasm séparé du JS, chargé en parallèle) et en ne gardant que les modules `imgproc` + `core` (désactiver dnn, photo, objdetect, video, calib3d si non utilisé) → on descend typiquement sous 2 Mo.
  - Charger OpenCV **paresseusement** dans un Web Worker, après le premier paint, et le mettre en Cache API (donc un seul téléchargement).
  - Les perfs mobiles sont **très irrégulières** entre appareils/navigateurs : ne pas se fier à un benchmark unique, mesurer sur l'appareil cible.
  - Alternative légère : écrire soi-même le pipeline (Sobel/Canny + Hough ou contour finding + homographie 3×3 + Sauvola/adaptive threshold) en WASM Rust/C compilé sur mesure (~200-400 Ko), ou en pur JS/WebGL pour la prévisualisation et réserver OpenCV au traitement final côté serveur (PHP + Imagick/OpenCV, où vous n'avez aucune contrainte de taille).

---

## 11. Viewport iPhone 14 Plus — chiffres exacts

| Élément | Valeur |
|---|---|
| Résolution physique | **1284 × 2778 px** |
| Viewport CSS (portrait) | **428 × 926 px** |
| DPR (`devicePixelRatio`) | **3** |
| Écran | 6,7" OLED, **encoche** (pas de Dynamic Island) |
| Hauteur status bar | **47 pt** |
| Safe area portrait (natif) | top **47**, bottom **34**, left/right **0** |

- En **Safari (onglet)** avec `viewport-fit=cover`, `env(safe-area-inset-top)` ≈ **47px** en portrait, bottom **34px** (home indicator).
- En **standalone** : si le status bar est opaque (`default` / `black`), **le système réserve lui-même la bande et `safe-area-inset-top` remonte 0**. Avec `black-translucent`, le contenu passe sous le status bar et l'inset remonte ~47px. **Ne codez jamais 47px en dur** : mesurez à l'exécution et prévoyez un fallback.
- ⚠️ **`black-translucent` est marqué déprécié par WebKit** ("support for this value will be removed in a future release", message visible dans l'inspecteur). À utiliser avec un plan B.
- ⚠️ Régression signalée sur **iOS 26** : bande de 47px parasite en bas dans certaines web apps installées. Tester explicitement.
- `orientation` dans le manifest est **ignoré** sur iOS → pas de verrouillage portrait déclaratif ; gérer en CSS.
- `display: fullscreen` et `minimal-ui` **non supportés** (fallback respectivement standalone et browser). **Pas de vrai plein écran, le status bar est toujours là.**

---

## 12. Installation, manifest, icônes, splash — ce qui est réellement lu par iOS

**Membres du manifest supportés :** `name`, `short_name`, `scope`, `start_url`, `display`, `theme_color` (15.0+), `icons` (15.4+, **PNG uniquement**), `id` (16.4+).

**Membres IGNORÉS :** `dir`, `lang`, `orientation`, `background_color`, `shortcuts`, `display_override`, `share_target`, `protocol_handlers`, `related_applications`, `note_taking`. Icônes **maskable / monochrome / SVG : non supportées**.

- ⚠️ **`<link rel="apple-touch-icon">` écrase les icônes du manifest** s'il est présent. Choisissez : soit apple-touch-icon 180×180 (compat iOS < 15.4), soit manifest icons — pas les deux si vous voulez du prévisible.
- **Pas de `beforeinstallprompt`, pas de bannière d'installation, pas d'installation automatique.** Il faut un tutoriel in-app (Partager → Ajouter à l'écran d'accueil).
- **iOS 26+** : "Ouvrir comme app web" est un toggle activé par défaut pour *tous* les sites. Un manifest reste utile (nom, scope, start_url, push) mais n'est plus une condition.
- **Splash screens** : uniquement via `<link rel="apple-touch-startup-image" media="...">` avec une media query par device. Pour l'iPhone 14 Plus : `media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"` → image **1284×2778**. `background_color` du manifest est ignoré.
- `navigator.standalone` existe mais est déprécié depuis 11.3 → utiliser `matchMedia('(display-mode: standalone)')`.
- Depuis 16.4, l'installation depuis un navigateur tiers (Chrome iOS…) est possible si opt-in.

---

## 13. Clavier, scroll, 100vh, rubber-banding

- **`dvh` / `svh` / `lvh` supportés depuis iOS 15.4.** Utiliser `100dvh`, jamais `100vh` (qui inclut la barre d'outils rétractée).
- ⚠️ **`dvh` ne réagit PAS à l'ouverture du clavier sur iOS** : la spec traite le clavier virtuel comme un overlay, le layout viewport ne change pas. `100dvh` == `100vh` clavier ouvert. **Seul `window.visualViewport` reflète le clavier.**
- ⚠️ Bug récent : **`visualViewport.offsetTop` ne revient pas à 0** après fermeture du clavier → headers/footers `fixed` désalignés. Forcer un `window.scrollTo(0,0)` + recalcul sur `resize`/`scroll` du visualViewport.
- `interactiveWidget: 'resizes-content'` dans le meta viewport : effet sur Chromium, **pas sur iOS**.
- **`100lvh` n'est pas atteignable au premier paint** : iOS ne rétracte la barre du bas qu'après un scroll vers le bas.
- **Rubber-banding** : `overscroll-behavior: none` sur `html, body` limite le chaînage mais **n'empêche pas le bounce du document racine sur iOS**. Le pattern fiable en standalone : `html,body { height:100%; overflow:hidden; overscroll-behavior:none; }` + un conteneur interne `overflow-y:auto; -webkit-overflow-scrolling:touch;`.
- Double-tap-to-zoom / pinch : `touch-action: manipulation` sur les boutons ; `user-scalable=no` est ignoré par iOS depuis longtemps (préférer `maximum-scale=1` uniquement sur l'écran caméra, jamais globalement — accessibilité).
- Inputs : **`font-size: 16px` minimum** sinon iOS zoome automatiquement au focus.

---

## 14. Wake Lock

- **Screen Wake Lock supporté depuis iOS 16.4.**
- ⚠️ **Bug WebKit #254545 : l'API ne fonctionnait pas dans les web apps installées** — corrigé seulement en **iOS 18.4**. Sur iOS < 18.4 en standalone, prévoir un fallback (vidéo muette en boucle invisible).
- Le wake lock est perdu à chaque passage en arrière-plan → le réacquérir sur `visibilitychange`.

---

## 15. Autres non-supportés à connaître (impact archi)

Non supportés sur iOS : Web Bluetooth, WebUSB, WebSerial, WebNFC, WebHID, Battery Status, Vibration API, Network Information, Screen Capture, Contact Picker, WebOTP (SMS), Pointer Lock, Payment Handler, Virtual Keyboard API, `shortcuts` du manifest, widgets, link capturing.
Supportés et utiles ici : Service Worker (11.3+), Cache Storage, Navigation Preload (15.4+), Web Workers, SharedWorker (16.0+), WebGL2 (15.0+), WebGPU (Safari 26), MediaRecorder (14.5+), Clipboard, WebAuthn (14.5+, **passkeys = bonne option d'auth multi-utilisateurs sans mot de passe**), Screen Orientation partiel (16.4+), Apple Pay / Payment Request.

---

## 16. Recommandation d'architecture qui découle de ces faits

1. **Capture** : `<input type="file" capture="environment" accept="image/jpeg,image/png">` comme chemin **par défaut** (qualité maximale, zéro bug de permission, flash natif, AF natif), et getUserMedia en mode "cadrage assisté" **optionnel** avec détection de bords temps réel — pas l'inverse. C'est contre-intuitif mais c'est ce que disent les bugs WebKit.
2. **Traitement** : détection de bords + homographie **côté client** pour le feedback immédiat et le recadrage manuel (build OpenCV.js custom léger en Worker) ; **binarisation + deskew + OCR + génération PDF/A côté Laravel** (Imagick, ou un service Python/Tesseract). Pas de COOP/COEP si vous vous passez des threads Wasm — ce qui simplifie beaucoup.
3. **Upload** : file d'attente en IndexedDB/OPFS, upload repris au foreground (`visibilitychange`), jamais de Background Sync.
4. **Traitement asynchrone** : Laravel Queue/Horizon → OpenAI → extraction → tâches + échéances → CalDAV iCloud (Apple exige un **mot de passe d'application** par utilisateur, pas le mot de passe iCloud) → **Web Push (Declarative Web Push sur 18.4+, SW push en fallback)** pour prévenir l'utilisateur.
5. **Onboarding obligatoire** expliquant "Ajouter à l'écran d'accueil" puis "Activer les notifications" (bouton, geste utilisateur requis).

## Faits clés vérifiés

- iOS 27 / Safari 27 sont sortis le 14 septembre 2026 (build 27.0, 20625.1.29) ; iOS 26 le 15 septembre 2025. iOS 27 supporte l'iPhone 11 et plus récents, donc l'iPhone 14 Plus est compatible.
- L'iPhone 14 Plus a une ENCOCHE, pas de Dynamic Island (celui-ci est réservé aux 14 Pro / 14 Pro Max et à la gamme 15+).
- iPhone 14 Plus : 1284x2778 px physiques, viewport CSS 428x926, devicePixelRatio = 3, status bar 47 pt, home indicator 34 pt, safe area portrait top 47 / bottom 34.
- getUserMedia fonctionne en PWA standalone (caméra supportée depuis iOS 13.0) mais reste instable : bug WebKit 252465 (flux vidéo noir en web app Home Screen) marqué RESOLVED FIXED (PR mergée 12/07/2023) avec régressions rapportées jusqu'à iOS 18.4.1/18.5 en juin 2025.
- Bug WebKit 215884 : la permission caméra n'est PAS persistée pour les PWA installées sur iOS — re-prompt à chaque lancement/navigation. Toujours ouvert en 2026.
- Bug WebKit 179363 : un second appel à getUserMedia() tue l'affichage du premier flux sur iOS.
- MediaStreamTrack.getCapabilities() existe sur iOS mais ne retourne ni torch, ni zoom, ni focusMode, ni exposureMode. Le torch/flash est impossible depuis le web sur iOS, tous navigateurs confondus (tous sur WebKit).
- ImageCapture API (takePhoto, grabFrame, getPhotoCapabilities) n'est PAS supportée dans Safari stable macOS/iOS, y compris Safari 26/27. Seule solution pour une image fixe : canvas.getContext('2d').drawImage(video, ...).
- iOS bascule automatiquement entre objectifs wide / ultra-wide / télé en getUserMedia (comportement macro auto) et il n'existe aucun moyen web de forcer .builtInWideAngleCamera ; iOS n'expose pas non plus de deviceId/label utilisables.
- getUserMedia sur iOS ne garantit aucune résolution : les contraintes non supportées tombent silencieusement sur le mode le plus proche. Le plafond '720p' souvent cité date d'iOS 11 et est obsolète ; 1920x1080 et jusqu'à 3840x2160 sont atteignables sur caméra arrière selon l'appareil. Toujours relire video.videoWidth/videoHeight.
- Feuille d'action iOS pour <input type=file accept="image/*"> sans capture : 3 entrées — Photothèque / Prendre une photo ou une vidéo / Choisir un fichier. Avec capture="environment", l'appareil photo natif s'ouvre directement sans feuille d'action.
- Safari convertit automatiquement le HEIC vers un type listé dans accept. Piège Safari 17+ toujours valable : si image/heic figure DANS accept, Safari renvoie du HEIC (et convertit même JPEG/PNG vers .heic). Ne jamais mettre image/heic dans accept ; utiliser accept="image/jpeg,image/png".
- Aucune source ne documente une option 'Numériser des documents' dans le document picker déclenché par un <input type=file> depuis Safari iOS. Le scanner système (VisionKit) n'est accessible que depuis l'app Fichiers/Notes (bouton ... > Numériser des documents ; iOS 26 y ajoute flash et filtres). Aucune API web ne peut le déclencher.
- Web Share Target API / share_target dans le manifest : NON supporté sur iOS, la PWA n'apparaît jamais dans la feuille de partage. Rien de nouveau dans Safari 26 ni 27. navigator.share() (sens sortant) fonctionne depuis iOS 12.1.
- Alternative principale pour recevoir un PDF depuis la feuille de partage iOS : un raccourci Apple Shortcuts distribué par lien iCloud, qui POST le fichier vers l'API Laravel avec un token.
- Web Push : depuis iOS 16.4, uniquement dans une web app ajoutée à l'écran d'accueil (pas dans l'onglet Safari). Manifest avec display: standalone ou fullscreen requis, id recommandé. requestPermission() doit venir d'une interaction utilisateur directe. VAPID standard, pas d'adhésion Apple Developer Program, autoriser *.push.apple.com.
- Badging API (setAppBadge / clearAppBadge) supportée depuis iOS 16.4.
- UE : Apple a annulé le 01/03/2024 la suppression des Home Screen web apps ; elles et le push sont restés disponibles dans l'UE dès iOS 17.4 final. Les articles affirmant 'pas de push dans l'UE' sont périmés.
- Declarative Web Push : iOS/iPadOS 18.4+ (mars 2025), macOS Safari 18.5+, uniquement pour les web apps Home Screen. Payload JSON avec { "web_push": 8030, "notification": { title (obligatoire), navigate (URL obligatoire), body, lang, dir, silent, app_badge } }. Fonctionne sans service worker ; si un SW existe il reçoit quand même un PushEvent et peut remplacer la notif, sinon la notif déclarative s'affiche — supprime la pénalité de push silencieux.
- iOS 26 : zéro exigence d'installabilité. Tout site ajouté à l'écran d'accueil s'ouvre comme web app par défaut, via un toggle 'Ouvrir comme app web' activé par défaut dans Partager > Ajouter à l'écran d'accueil. Le manifest n'est plus obligatoire mais reste pris en compte.
- Background Sync, Periodic Background Sync et Background Fetch : aucun supporté sur iOS/iPadOS/macOS, dans aucune version y compris Safari 26/27. WebKit n'a pas de position publique, pas de flag expérimental. Bug 204117 ouvert depuis 2019.
- Quotas de stockage WebKit (source officielle) : par origine jusqu'à 60 % du disque pour les apps navigateur, quota global tous origines jusqu'à 80 % ; 15 %/20 % pour les apps non-navigateur. Iframes cross-origin : 10 % du quota du main frame. Couvre localStorage, Cache API, IndexedDB, Service Worker et File System ; cookies et cache HTTP exclus. La limite '50 Mo' souvent citée est fausse.
- Une web app standalone (Home Screen) a exactement le même quota par origine et global qu'en navigateur.
- Règle ITP des 7 jours : les données créées par script sont supprimées après 7 jours d'utilisation du navigateur sans interaction avec l'origine. Les web apps ajoutées à l'écran d'accueil en sont EXEMPTÉES : elles ont leur propre compteur de jours d'utilisation, remis à zéro par l'usage réel de la web app.
- navigator.storage.persist() : WebKit accorde le mode persistant par heuristique, notamment si le site est ouvert comme Home Screen Web App. navigator.storage.estimate() (reporting de quota) disponible depuis iOS 17.
- OPFS supporté depuis Safari/iOS 15.2 via navigator.storage.getDirectory(), avec FileSystemSyncAccessHandle en Web Worker. Les pickers (showOpenFilePicker / showSaveFilePicker / showDirectoryPicker) ne sont PAS supportés sur iOS. OPFS indisponible en navigation privée.
- Safari 26 ajoute File System WritableStream (écriture en flux dans OPFS). Safari 27 corrige FileSystemDirectoryHandle.resolve() et removeEntry().
- WebAssembly SIMD fixe 128 bits : supporté depuis iOS/Safari 16.4 (27 mars 2023) ; gain typique 2x-4x sur filtres image. iOS <= 16.3 retombe silencieusement en scalaire.
- Wasm threads / SharedArrayBuffer sur iOS : nécessitent l'isolation cross-origin via COOP: same-origin + COEP: require-corp (headers shippés dans Safari 15.2). Safari mobile est plus strict que Chrome sur les variantes de COEP acceptées.
- Safari 26 introduit un nouvel interpréteur Wasm 'in-place' : démarrage plus rapide et moins de mémoire pour les gros modules. Safari 27 ajoute WebAssembly JSPI (JavaScript Promise Integration).
- OpenCV.js : bundle complet ~8-12 Mo ; le .wasm seul ~5,3 Mo (contre 10,4 Mo en asm.js). --disable_single_file sépare le .wasm du .js. Les perfs mobiles sont très irrégulières entre appareils/navigateurs.
- Manifest iOS — membres supportés : name, short_name, scope, start_url, display, theme_color (15.0+), icons (15.4+, PNG uniquement), id (16.4+). IGNORÉS : dir, lang, orientation, background_color, shortcuts, display_override, share_target, protocol_handlers, related_applications. Icônes maskable/monochrome/SVG non supportées.
- display: standalone et browser supportés ; minimal-ui (fallback browser) et fullscreen (fallback standalone) NON supportés. Pas de vrai plein écran, le status bar reste toujours visible.
- <link rel="apple-touch-icon"> (180x180) écrase les icônes du manifest s'il est présent. Splash screens uniquement via <link rel="apple-touch-startup-image" media="..."> avec une media query par device ; pour iPhone 14 Plus : device-width 428px, device-height 926px, -webkit-device-pixel-ratio 3, image 1284x2778.
- Pas de beforeinstallprompt, pas de bannière ni d'installation automatique sur iOS. navigator.standalone existe mais est déprécié depuis 11.3 : utiliser matchMedia('(display-mode: standalone)').
- apple-mobile-web-app-status-bar-style: la valeur black-translucent est signalée comme DÉPRÉCIÉE par WebKit ('support for this value will be removed in a future release'). En standalone avec status bar opaque, safe-area-inset-top remonte 0 car le système réserve lui-même la bande.
- Unités dvh/svh/lvh supportées depuis iOS 15.4. Mais dvh ne réagit PAS à l'ouverture du clavier sur iOS (clavier traité comme overlay, le layout viewport ne change pas) : seul window.visualViewport le reflète. interactiveWidget: 'resizes-content' n'a d'effet que sur Chromium.
- Bug iOS récent : visualViewport.offsetTop ne revient pas à 0 après fermeture du clavier, désalignant les éléments fixed. 100lvh n'est pas atteignable au premier paint car iOS ne rétracte la barre du bas qu'après un scroll.
- Screen Wake Lock supporté depuis iOS 16.4, mais bug WebKit 254545 : cassé dans les web apps installées jusqu'au correctif d'iOS 18.4. Le lock est perdu au passage en arrière-plan, à réacquérir sur visibilitychange.
- Non supportés sur iOS : Web Bluetooth, WebUSB, WebSerial, WebNFC, WebHID, Battery Status, Vibration, Network Information, Screen Capture, Contact Picker, WebOTP, Pointer Lock, Payment Handler, Virtual Keyboard API, shortcuts du manifest. Supportés : Service Worker (11.3+), Navigation Preload (15.4+), SharedWorker (16.0+), WebGL2 (15.0+), WebGPU (Safari 26), MediaRecorder (14.5+), WebAuthn/passkeys (14.5+), Screen Orientation partiel (16.4+), Apple Pay.
- Safari 27 (WWDC26) : 58 nouveautés, 525 correctifs, 4 dépréciations — customizable <select>, scroll anchoring, anchor positioning transform-aware, :heading, revert-rule, stretch, CSS random(), <model> sur iOS, subpixel inline layout, Cookie Store maxAge. Rien sur share_target, background sync, torch ou ImageCapture.

## Pièges / ce qui ne marche PAS

- NE PAS concevoir l'UI autour du Dynamic Island : l'iPhone 14 Plus a une encoche classique.
- NE PAS faire de getUserMedia le chemin de capture principal : bugs de permission non persistée (WebKit 215884) et de flux noir (WebKit 252465) encore actifs en 2026. L'input file avec capture est plus fiable ET donne une meilleure qualité d'image (AF, flash, pleine résolution capteur).
- NE PAS prévoir de bouton torche/flash : impossible sur iOS (torch absent de getCapabilities, ImageCapture non implémenté).
- NE PAS compter sur ImageCapture.takePhoto() pour une capture haute résolution supérieure à la preview : seul canvas.drawImage(video) fonctionne sur iOS.
- NE PAS mettre image/heic dans l'attribut accept : depuis Safari 17, cela fait renvoyer du HEIC par Safari (et convertit même JPEG/PNG en .heic), soit l'inverse de l'effet recherché.
- NE PAS utiliser l'attribut capture pour du multipage : une seule photo par déclenchement et multiple est ignoré. Il faut boucler côté JS.
- NE PAS espérer déclencher le scanner VisionKit d'Apple depuis le web : aucune API, et le document picker de Safari n'expose pas 'Numériser des documents'.
- NE PAS compter sur share_target : la PWA n'apparaîtra jamais dans la feuille de partage iOS. Passer par un raccourci Shortcuts ou un input file.
- NE PAS appeler Notification.requestPermission() au chargement : l'appel doit venir d'un geste utilisateur direct, sinon échec silencieux.
- NE PAS croire la doc tierce disant que le push est indisponible dans l'UE : c'est faux depuis mars 2024.
- NE PAS croire la limite de stockage de 50 Mo ni l'éviction à 7 jours pour une PWA installée : le quota réel est ~60 % du disque par origine et les Home Screen web apps sont exemptées de la règle ITP des 7 jours.
- NE PAS architecturer de synchronisation en arrière-plan : Background Sync, Periodic Sync et Background Fetch sont tous absents sur iOS sans horizon. Tout le travail asynchrone doit être serveur (Laravel queues) + Web Push.
- NE PAS considérer IndexedDB/OPFS comme source de vérité : éviction LRU possible sous pression disque. PostgreSQL reste la référence.
- NE PAS embarquer OpenCV.js complet (8-12 Mo) : temps de démarrage rédhibitoire sur mobile. Build custom (imgproc+core seulement), --disable_single_file, chargement lazy en Worker, mise en Cache API.
- NE PAS activer COOP/COEP à la légère pour les threads Wasm : cela casse toutes les ressources cross-origin sans CORP/CORS (CDN, polices, images tierces) et Safari mobile est plus strict que Chrome sur les variantes de COEP.
- NE PAS utiliser 100vh : utiliser 100dvh. Mais ne pas croire que dvh gère le clavier sur iOS — seul window.visualViewport le fait.
- NE PAS coder 47px en dur pour la safe area : en standalone avec status bar opaque, safe-area-inset-top vaut 0 (le système réserve la bande). Mesurer au runtime.
- NE PAS s'appuyer durablement sur black-translucent : valeur dépréciée par WebKit, suppression annoncée.
- NE PAS déclarer à la fois <link rel=apple-touch-icon> et des icons dans le manifest en attendant un comportement prévisible : apple-touch-icon gagne.
- NE PAS espérer verrouiller l'orientation via le manifest : le membre orientation est ignoré sur iOS.
- NE PAS ré-appeler getUserMedia sans stopper les tracks précédents (bug WebKit 179363), et ne pas démonter le composant vidéo lors des changements de route SPA (le flux est perdu).
- NE PAS utiliser fullscreen ou minimal-ui comme display : non supportés, et le status bar reste toujours visible.
- NE PAS mettre des inputs à moins de 16px de font-size : iOS zoome automatiquement au focus.
- NE PAS oublier que iOS peut changer d'objectif tout seul en approche rapprochée (macro auto) : prévoir une détection de netteté avant déclenchement plutôt que de compter sur une mise au point stable.

## Extraits de code de référence

### Extrait 1

<!-- head : viewport + safe area + statut iOS + icônes + splash iPhone 14 Plus -->
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Papers">
<!-- black-translucent est DEPRECIE par WebKit : garder un plan B -->
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0b0b0f">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
<link rel="manifest" href="/manifest.webmanifest">
<!-- splash iPhone 14 Plus : 428x926 pt @3x = 1284x2778 px -->
<link rel="apple-touch-startup-image"
      media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"
      href="/splash/iphone14plus-1284x2778.png">

### Extrait 2

// manifest.webmanifest — uniquement les membres réellement lus par iOS
{
  "id": "/?source=pwa",
  "name": "Papers — Scanner & Analyse",
  "short_name": "Papers",
  "start_url": "/?source=pwa",
  "scope": "/",
  "display": "standalone",
  "theme_color": "#0b0b0f",
  "icons": [
    { "src": "/icons/192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/icons/512.png", "sizes": "512x512", "type": "image/png" }
  ]
}
// IGNORES sur iOS : orientation, background_color, shortcuts, display_override,
// share_target, protocol_handlers, icons purpose=maskable/monochrome, SVG.

### Extrait 3

/* Safe area : ne jamais coder 47px en dur. En standalone avec status bar opaque,
   safe-area-inset-top vaut 0 car le systeme reserve deja la bande. */
:root {
  --sat: env(safe-area-inset-top, 0px);
  --sab: env(safe-area-inset-bottom, 0px);
  --sal: env(safe-area-inset-left, 0px);
  --sar: env(safe-area-inset-right, 0px);
}
html, body {
  height: 100%;
  overflow: hidden;              /* tue le rubber-banding du document racine */
  overscroll-behavior: none;
  background: #0b0b0f;
}
#app {
  height: 100dvh;                /* jamais 100vh */
  padding-top: var(--sat);
  padding-bottom: max(var(--sab), 12px);
  display: flex; flex-direction: column;
}
#scroller { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
button, .tappable { touch-action: manipulation; }
input, select, textarea { font-size: 16px; } /* sinon iOS zoome au focus */

### Extrait 4

// Detection fiable du mode standalone (navigator.standalone est deprecie depuis 11.3)
const isStandalone = window.matchMedia('(display-mode: standalone)').matches
  || window.navigator.standalone === true; // fallback iOS < 11.3

// Diagnostic runtime des insets reels (a logguer une fois pour calibrer)
const probe = document.createElement('div');
probe.style.cssText = 'position:fixed;top:0;left:0;height:env(safe-area-inset-top);width:1px;';
document.body.appendChild(probe);
console.log('inset-top reel =', probe.getBoundingClientRect().height,
            'dpr =', devicePixelRatio, 'viewport =', innerWidth + 'x' + innerHeight);

### Extrait 5

<!-- CHEMIN PRINCIPAL recommande sur iOS : camera native, qualite max, zero bug de permission.
     Ne JAMAIS mettre image/heic dans accept (Safari 17+ renverrait du HEIC). -->
<input id="shot" type="file" accept="image/jpeg,image/png" capture="environment" hidden>

<!-- CHEMIN IMPORT : PDF deja scanne via l'app Fichiers (seul acces au scanner VisionKit d'Apple) -->
<input id="import" type="file" accept="application/pdf,image/jpeg,image/png" multiple hidden>

<script>
// capture="environment" => une seule photo par declenchement, `multiple` est ignore.
// Multipage : on reboucle explicitement.
const pages = [];
const shot = document.getElementById('shot');
shot.addEventListener('change', async () => {
  const f = shot.files[0];
  if (!f) return;
  console.log(f.type, f.name, f.size); // image/jpeg attendu (HEIC converti par Safari)
  pages.push(await normalize(f));
  shot.value = '';                      // indispensable pour pouvoir reprendre la meme page
  // puis : afficher la miniature + bouton "Page suivante" qui refait shot.click()
});
</script>

### Extrait 6

// getUserMedia sur iOS — chemin "cadrage assiste" optionnel, defensif.
let currentStream = null;

async function startCamera(videoEl) {
  stopCamera();                       // bug WebKit 179363 : un 2e gUM tue le 1er flux
  const constraints = {
    audio: false,
    video: {
      facingMode: { exact: 'environment' },
      width:  { ideal: 3840 },        // iOS negocie silencieusement vers le mode le plus proche
      height: { ideal: 2160 },
      frameRate: { ideal: 30 }
    }
  };
  try {
    currentStream = await navigator.mediaDevices.getUserMedia(constraints);
  } catch (e) {
    // exact:environment peut echouer sur certains devices -> retenter en ideal
    currentStream = await navigator.mediaDevices.getUserMedia({
      audio: false, video: { facingMode: 'environment' }
    });
  }
  videoEl.srcObject = currentStream;
  videoEl.setAttribute('playsinline', '');   // OBLIGATOIRE sur iOS, sinon plein ecran natif
  videoEl.muted = true;
  await videoEl.play();

  // Garde-fou bug WebKit 252465 : flux "live" mais video noire en web app Home Screen
  const ok = await new Promise(res => {
    const t = setTimeout(() => res(false), 2500);
    videoEl.addEventListener('loadeddata', () => {
      clearTimeout(t);
      res(videoEl.videoWidth > 0 && videoEl.videoHeight > 0);
    }, { once: true });
  });
  if (!ok) { stopCamera(); throw new Error('GUM_BLACK_FRAME'); } // -> bascule sur <input capture>

  // TOUJOURS relire la resolution reellement obtenue
  console.log('resolution reelle', videoEl.videoWidth, 'x', videoEl.videoHeight);

  // Ce que iOS NE retourne PAS : torch, zoom, focusMode, exposureMode
  const caps = currentStream.getVideoTracks()[0].getCapabilities?.() ?? {};
  console.log('capabilities iOS', caps); // pas de `torch` -> ne pas afficher de bouton flash
  return caps;
}

function stopCamera() {
  currentStream?.getTracks().forEach(t => t.stop());
  currentStream = null;
}

// Ne JAMAIS demonter ce composant lors d'un changement de route SPA : le flux est perdu.

### Extrait 7

// Capture d'une image fixe : ImageCapture n'existe pas sur iOS -> canvas obligatoire
function grabFrame(videoEl, quality = 0.92) {
  const c = document.createElement('canvas');
  c.width  = videoEl.videoWidth;     // resolution reelle, pas celle demandee
  c.height = videoEl.videoHeight;
  c.getContext('2d', { willReadFrequently: false }).drawImage(videoEl, 0, 0, c.width, c.height);
  return new Promise(res => c.toBlob(res, 'image/jpeg', quality));
}

// Detection de nettete (iOS peut changer d'objectif tout seul en approche macro)
function laplacianVariance(imageData) {
  const { data, width: w, height: h } = imageData;
  const g = new Float32Array(w * h);
  for (let i = 0, p = 0; i < data.length; i += 4, p++)
    g[p] = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
  let sum = 0, sum2 = 0, n = 0;
  for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
    const i = y * w + x;
    const L = -4 * g[i] + g[i - 1] + g[i + 1] + g[i - w] + g[i + w];
    sum += L; sum2 += L * L; n++;
  }
  return sum2 / n - (sum / n) ** 2;   // < ~80 => flou, refuser le declenchement
}

### Extrait 8

// Stockage : OPFS pour les pages scannees (ecriture en flux, pas de serialisation)
async function persistSetup() {
  if (navigator.storage?.persist) {
    // WebKit accorde par heuristique, notamment si la page tourne en Home Screen Web App
    const granted = await navigator.storage.persist();
    const { quota, usage } = await navigator.storage.estimate(); // dispo depuis iOS 17
    console.log({ granted, quotaMB: quota / 1e6, usageMB: usage / 1e6 }); // quota ~60% du disque
  }
}

async function savePage(docId, pageNo, blob) {
  const root = await navigator.storage.getDirectory();   // OPFS, iOS 15.2+
  const dir  = await root.getDirectoryHandle(docId, { create: true });
  const fh   = await dir.getFileHandle(`p${pageNo}.jpg`, { create: true });
  // File System WritableStream : Safari 26+
  const ws = await fh.createWritable();
  await blob.stream().pipeTo(ws);
}
// showOpenFilePicker / showSaveFilePicker / showDirectoryPicker : INDISPONIBLES sur iOS.
// OPFS indisponible en navigation privee -> try/catch obligatoire.

### Extrait 9

// Web Push iOS : requestPermission DOIT venir d'un geste utilisateur direct
document.getElementById('btn-enable-notifs').addEventListener('click', async () => {
  if (!isStandalone) {
    return showOnboarding(); // sur iPhone, le push n'existe QUE dans une web app installee (iOS 16.4+)
  }
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') return;

  const reg = await navigator.serviceWorker.register('/sw.js');
  const sub = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY) // VAPID standard, pas d'APNs cert
  });
  await fetch('/api/push/subscribe', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify(sub)
  });
});

function urlBase64ToUint8Array(b64) {
  const pad = '='.repeat((4 - b64.length % 4) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

// Badge (iOS 16.4+)
navigator.setAppBadge?.(pendingCount);

### Extrait 10

// Declarative Web Push — iOS/iPadOS 18.4+, web apps Home Screen. Pas besoin de service worker.
// Payload chiffre aes128gcm et signe VAPID comme un push classique, seul le corps change :
{
  "web_push": 8030,
  "notification": {
    "title": "Facture EDF — echeance dans 3 jours",
    "body": "Montant 128,40 EUR. Appuyez pour voir la tache.",
    "navigate": "https://papers.example.com/tasks/9f2c",   // OBLIGATOIRE
    "lang": "fr-FR",
    "dir": "ltr",
    "silent": false,
    "app_badge": "3"
  }
}
// title et navigate sont obligatoires.
// Si un service worker est installe, il recoit quand meme un PushEvent et peut afficher
// une notif de remplacement ; s'il echoue, la notif declarative s'affiche => plus de
// penalite de "push silencieux".

### Extrait 11

<?php
// Laravel — middleware d'isolation cross-origin, UNIQUEMENT si vous voulez les threads Wasm.
// Attention : casse toute ressource cross-origin sans CORP/CORS. Safari mobile est plus
// strict que Chrome sur les variantes de COEP : utiliser require-corp, pas credentialless.
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CrossOriginIsolation
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        return $response;
    }
}

// Verification cote client :
// console.log(self.crossOriginIsolated, typeof SharedArrayBuffer);

### Extrait 12

// Clavier iOS : dvh ne bouge PAS a l'ouverture du clavier (clavier = overlay).
// Seul visualViewport le reflete. Bug connu : offsetTop ne revient pas a 0 a la fermeture.
const vv = window.visualViewport;
function syncKeyboard() {
  if (!vv) return;
  const kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
  document.documentElement.style.setProperty('--kb', kb + 'px');
  // contournement du bug offsetTop bloque
  if (kb === 0 && vv.offsetTop !== 0) window.scrollTo(0, 0);
}
vv?.addEventListener('resize', syncKeyboard);
vv?.addEventListener('scroll', syncKeyboard);
syncKeyboard();
/* CSS : .composer { padding-bottom: calc(var(--kb, 0px) + max(env(safe-area-inset-bottom), 12px)); } */

### Extrait 13

// Wake Lock : iOS 16.4+, mais casse dans les web apps installees jusqu'a iOS 18.4 (bug 254545).
let wakeLock = null;
async function keepAwake() {
  try { wakeLock = await navigator.wakeLock?.request('screen'); }
  catch { startVideoFallback(); }   // fallback : <video muted loop playsinline> 1px invisible
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && wakeLock === null) keepAwake();
});

### Extrait 14

// Pas de Background Sync sur iOS : file d'attente maison rejouee au retour au premier plan.
async function flushQueue() {
  const root = await navigator.storage.getDirectory();
  const outbox = await root.getDirectoryHandle('outbox', { create: true });
  for await (const [name, handle] of outbox.entries()) {
    const file = await handle.getFile();
    const fd = new FormData();
    fd.append('page', file, name);
    const r = await fetch('/api/scans', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf } });
    if (r.ok) await outbox.removeEntry(name);
  }
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && navigator.onLine) flushQueue();
});
window.addEventListener('online', flushQueue);
// Tout le traitement lourd (OCR, OpenAI, CalDAV) part ensuite en queue Laravel/Horizon,
// et l'utilisateur est notifie par Web Push.


## Incertitudes

- Valeur exacte de env(safe-area-inset-top) en mode standalone sur iPhone 14 Plus : les sources donnent 47pt pour la safe area native, mais en web app installée le comportement diffère selon apple-mobile-web-app-status-bar-style (0 si status bar opaque, ~47px si black-translucent). Un rapport mentionne aussi une régression WebKit iOS 26 avec 47px parasites en bas. À mesurer sur l'appareil réel avant de figer le layout.
- Résolution maximale réellement obtenue par getUserMedia sur un iPhone 14 Plus sous iOS 26/27 : non testée directement. Les sources vont de 720p (données iOS 11 obsolètes) à 3840x2160 (données 2025 sur iOS 18.7). Mesurer video.videoWidth/videoHeight sur l'appareil.
- Statut exact du bug de non-persistance de la permission caméra en PWA (WebKit 215884) sur iOS 26 et 27 : aucune source ne confirme ni n'infirme un correctif récent. Les derniers rapports datent de juin 2025 (iOS 18.5). À retester.
- Statut réel du bug de flux vidéo noir (WebKit 252465) sur iOS 26/27 : le ticket est marqué RESOLVED FIXED mais des régressions sont rapportées jusqu'à iOS 18.5. Comportement sur iOS 26/27 non vérifié.
- Présence ou non d'une entrée 'Numériser des documents' dans le document picker déclenché par un input[type=file] sous iOS 26/27 : aucune source ne la documente (ni positivement ni négativement). La conclusion 'non' est une inférence forte à partir de l'absence totale de documentation et du mode picker restreint, mais mérite un test manuel sur appareil.
- Date exacte et version de suppression annoncée de apple-mobile-web-app-status-bar-style=black-translucent : WebKit affiche un avertissement de dépréciation mais aucune échéance publique n'a été trouvée.
- Support éventuel de theme_color du manifest pour colorer la status bar en standalone sur iOS 26/27 : les sources divergent (theme-color meta supporté depuis 15.0, mais theme_color du manifest historiquement ignoré). À tester.
- Variantes de COEP réellement acceptées par Safari iOS 26/27 (require-corp vs credentialless) : une source secondaire affirme que Safari mobile est plus strict, sans détail. À valider par un test crossOriginIsolated sur appareil.
- Support de Relaxed SIMD (au-delà du SIMD fixe 128 bits) dans Safari iOS 26/27 : non trouvé.
- Taille et perfs exactes d'un build OpenCV.js custom limité à core+imgproc sur iPhone 14 Plus : l'estimation sous 2 Mo est extrapolée, pas mesurée.
- Détails précis des en-têtes HTTP requis pour Declarative Web Push (Content-Encoding, TTL, Urgency) : le blog WebKit ne les spécifie pas explicitement ; à confirmer via la RFC 8030 et la bibliothèque web-push utilisée côté Laravel.
- Comportement exact du toggle 'Ouvrir comme app web' d'iOS 26/27 vis-à-vis du scope et du start_url du manifest quand l'utilisateur ajoute une page interne à l'écran d'accueil.

## Sources

- https://www.macrumors.com/2026/09/14/ios-27-compatible-iphones/
- https://support.apple.com/guide/iphone/iphone-models-compatible-with-ios-27-iphe3fa5df43/ios
- https://developer.apple.com/documentation/safari-release-notes/safari-27-release-notes
- https://webkit.org/blog/17967/news-from-wwdc26-webkit-in-safari-27-beta/
- https://webkit.org/blog/17333/webkit-features-in-safari-26-0/
- https://webkit.org/blog/17640/webkit-features-for-safari-26-2/
- https://kb.strich.io/article/29-camera-access-issues-in-ios-pwa
- https://bugs.webkit.org/show_bug.cgi?id=252465
- https://bugs.webkit.org/show_bug.cgi?id=215884
- https://bugs.webkit.org/show_bug.cgi?id=179363
- https://bugs.webkit.org/show_bug.cgi?id=254545
- https://bugs.webkit.org/show_bug.cgi?id=204117
- https://developer.apple.com/forums/thread/776460
- https://developer.apple.com/forums/thread/724230
- https://developer.apple.com/forums/thread/113532
- https://oberhofer.co/mediastreamtrack-and-its-capabilities/
- https://caniuse.com/imagecapture
- https://www.testmuai.com/learning-hub/image-capture-api-browser-support/
- https://www.dynamsoft.com/codepool/take-high-resolution-photo-in-the-browser.html
- https://developer.apple.com/forums/thread/743049
- https://zenn.dev/kou_pg_0131/articles/safari-input-file-heic
- https://shkspr.mobi/blog/2020/12/coping-with-heic-in-the-browser/
- https://support.apple.com/en-us/108963
- https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference/share_target
- https://caniuse.com/?search=share_target
- https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide
- https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/
- https://webkit.org/blog/16535/meet-declarative-web-push/
- https://webkit.org/blog/16574/webkit-features-in-safari-18-4/
- https://developer.apple.com/videos/play/wwdc2025/235/
- https://techcrunch.com/2024/03/01/apple-reverses-decision-about-blocking-web-apps-on-iphones-in-the-eu/
- https://9to5mac.com/2024/03/01/apple-home-screen-web-apps-ios-17-eu/
- https://webkit.org/blog/14403/updates-to-storage-policy/
- https://webkit.org/tracking-prevention/
- https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria
- https://webkit.org/blog/12257/the-file-system-access-api-with-origin-private-file-system/
- https://developer.mozilla.org/en-US/docs/Web/API/File_System_API/Origin_private_file_system
- https://lapcatsoftware.com/articles/2026/5/5.html
- https://platform.uno/blog/safari-16-4-support-for-webassembly-fixed-width-simd-how-to-use-it-with-c/
- https://www.lambdatest.com/web-technologies/wasm-simd-safari
- https://webkit.org/blog/12140/new-webkit-features-in-safari-15-2/
- https://docs.opencv.org/4.x/d4/da1/tutorial_js_setup.html
- https://answers.opencv.org/question/229032/opencv_jswasm-is-too-large/
- https://blisk.io/devices/details/iphone-14-plus
- https://yesviz.com/devices/iphone-14-plus/
- https://useyourloaf.com/blog/iphone-14-screen-sizes/
- https://firt.dev/notes/pwa-ios/
- https://developer.apple.com/library/archive/documentation/AppleApplications/Reference/SafariHTMLRef/Articles/MetaTags.html
- https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios
- https://www.macrumors.com/how-to/save-safari-bookmark-web-app-iphone-home-screen/
- https://mjtsai.com/blog/2025/10/03/web-apps-in-ios-26/
- https://caniuse.com/wake-lock
- https://progressier.com/pwa-capabilities/screen-wake-lock
- https://www.testmuai.com/learning-hub/viewport-unit-variants-browser-support/
- https://www.franciscomoretti.com/blog/fix-mobile-keyboard-overlap-with-visualviewport
- https://www.testmuai.com/learning-hub/background-sync-browser-support/
- https://gist.github.com/EvanBacon/7fd4dc3be3d00096579bb0b134c56ec7
- https://www.mobiloud.com/blog/progressive-web-apps-ios/


---

# research:caldav

## Synthèse

# iCloud CalDAV depuis Laravel/PHP — synthèse opérationnelle (vérifié sept. 2026)

## 0. Verdict rapide pour le projet Papers

| Besoin | Faisable via CalDAV iCloud ? |
|---|---|
| Créer/modifier/supprimer des **événements** (VEVENT) dans un calendrier iCloud | ✅ Oui, gratuit, stable |
| Poser des **alarmes/rappels** sur ces événements (VALARM) | ✅ Oui (DISPLAY/AUDIO) |
| Écrire dans l'app **Rappels** d'Apple (VTODO) | ❌ **Non** depuis iOS 13 / Catalina — voir §5 |
| Push/webhooks côté Apple | ❌ Non, polling via `sync-token` uniquement |
| API REST JSON | ❌ Non, CalDAV/XML/ICS uniquement |

**Conséquence d'architecture** : le "todo avec deadline + rappel automatique" doit être matérialisé côté iCloud par un **VEVENT (souvent all-day) dans un calendrier dédié "Papers – Échéances", avec un ou plusieurs VALARM**. Les todos restent la source de vérité en PostgreSQL ; iCloud est une projection.

---

## 1. Coût & prérequis Apple

- **Gratuit.** Aucun compte Apple Developer (99 $/an) requis. CalDAV est un accès standard au compte iCloud de l'utilisateur.
- **2FA obligatoire** sur l'Apple Account pour pouvoir générer un mot de passe d'application.
- Génération : `account.apple.com` → **Sign-In and Security** → **App-Specific Passwords** → *Generate an app-specific password*.
- **25 mots de passe d'application actifs maximum** par compte.
- **Révocation automatique de TOUS les app-specific passwords** dès que l'utilisateur change/réinitialise son mot de passe Apple principal → il faut détecter le 401 et redemander (§8).
- Format observé universellement : `abcd-efgh-ijkl-mnop` (16 lettres minuscules + 3 tirets). ⚠️ Apple ne documente **pas** ce format officiellement : **ne valide pas strictement** côté Laravel, normalise seulement (trim, suppression des espaces, minuscule), et accepte la saisie avec ou sans tirets — envoie la valeur telle que fournie par Apple.

---

## 2. Découverte CalDAV iCloud — flux exact

### Étape 0 (optionnelle) — `.well-known`
`PROPFIND https://caldav.icloud.com/.well-known/caldav` fonctionne, mais en pratique **PROPFIND sur `https://caldav.icloud.com/` suffit** et évite une redirection.

### Étape 1 — `current-user-principal`
```
PROPFIND / HTTP/1.1
Host: caldav.icloud.com
Authorization: Basic <base64(appleId:appPassword)>
Depth: 0
Content-Type: application/xml; charset=utf-8
```
```xml
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop><d:current-user-principal/></d:prop>
</d:propfind>
```
Réponse `207 Multi-Status` :
```xml
<multistatus xmlns="DAV:">
  <response>
    <href>/</href>
    <propstat>
      <prop><current-user-principal><href>/200385701/principal/</href></current-user-principal></prop>
      <status>HTTP/1.1 200 OK</status>
    </propstat>
  </response>
</multistatus>
```
`200385701` = **identifiant numérique de compte iCloud (DSID)**. Il est stable par compte et se retrouve dans tous les chemins.

### Étape 2 — `calendar-home-set`
```
PROPFIND /200385701/principal/ HTTP/1.1
Host: caldav.icloud.com
Depth: 0
```
```xml
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <c:calendar-home-set/>
    <d:displayname/>
    <c:calendar-user-address-set/>
  </d:prop>
</d:propfind>
```
Réponse :
```xml
<prop>
  <C:calendar-home-set xmlns:C="urn:ietf:params:xml:ns:caldav">
    <href xmlns="DAV:">https://p34-caldav.icloud.com:443/200385701/calendars/</href>
  </C:calendar-home-set>
</prop>
```
👉 **Le href est ABSOLU et pointe vers une partition `pNN-caldav.icloud.com`** (numéro propre au compte, ex. `p34`, `p67`, `p127`). Toutes les requêtes suivantes doivent aller sur ce host. **Persiste-le en base**, ne le redécouvre pas à chaque job.

### Étape 3 — lister les calendriers (`Depth: 1`)
```
PROPFIND /200385701/calendars/ HTTP/1.1
Host: p34-caldav.icloud.com
Depth: 1
Content-Type: application/xml; charset=utf-8
```
```xml
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:"
            xmlns:c="urn:ietf:params:xml:ns:caldav"
            xmlns:cs="http://calendarserver.org/ns/"
            xmlns:a="http://apple.com/ns/ical/">
  <d:prop>
    <d:resourcetype/>
    <d:displayname/>
    <d:current-user-privilege-set/>
    <d:sync-token/>
    <cs:getctag/>
    <a:calendar-color/>
    <c:supported-calendar-component-set/>
  </d:prop>
</d:propfind>
```
Réponse (extrait d'une entrée calendrier) :
```xml
<response>
  <href>/200385701/calendars/2b0158b9-9c73-49e8-92a9-9aefbbec3534/</href>
  <propstat>
    <prop>
      <resourcetype><collection/><C:calendar xmlns:C="urn:ietf:params:xml:ns:caldav"/></resourcetype>
      <displayname>Perso</displayname>
      <current-user-privilege-set>
        <privilege><read/></privilege><privilege><write/></privilege>
        <privilege><write-content/></privilege><privilege><bind/></privilege><privilege><unbind/></privilege>
      </current-user-privilege-set>
      <sync-token>HwoQEgwAAGbI6heuOgAAAAEYARgAIhUIvKjK...</sync-token>
      <getctag xmlns="http://calendarserver.org/ns/">FT=-@RU=6f3...</getctag>
      <calendar-color xmlns="http://apple.com/ns/ical/" symbolic-color="blue">#1BADF8FF</calendar-color>
      <C:supported-calendar-component-set xmlns:C="urn:ietf:params:xml:ns:caldav">
        <C:comp name="VEVENT"/>
      </C:supported-calendar-component-set>
    </prop>
    <status>HTTP/1.1 200 OK</status>
  </propstat>
</response>
```

**Filtrage indispensable** dans la home collection :
- ignorer `inbox/`, `outbox/`, `notifications/` (resourcetype `schedule-inbox`, `schedule-outbox`, `notification`) ;
- ne garder que `resourcetype` contenant `{urn:ietf:params:xml:ns:caldav}calendar` ;
- ne garder que les collections dont `supported-calendar-component-set` contient `VEVENT` (celles avec seulement `VTODO` sont des listes de Rappels legacy) ;
- vérifier `write-content` + `bind` dans `current-user-privilege-set` (les calendriers partagés en lecture seule ou abonnés échouent en PUT avec 403) ;
- `calendar-color` est en `#RRGGBBAA` → tronquer l'alpha pour le web.

### MKCALENDAR — créer un calendrier dédié
Supporté par iCloud. Permet de créer "Papers – Échéances" et de ne jamais polluer les calendriers de l'utilisateur :
```
MKCALENDAR /200385701/calendars/papers-echeances/ HTTP/1.1
Host: p34-caldav.icloud.com
Content-Type: application/xml; charset=utf-8
```
```xml
<?xml version="1.0" encoding="UTF-8"?>
<c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">
  <d:set>
    <d:prop>
      <d:displayname>Papers – Échéances</d:displayname>
      <c:calendar-description xml:lang="fr">Échéances extraites de vos documents</c:calendar-description>
      <a:calendar-color symbolic-color="orange">#FF9500FF</a:calendar-color>
      <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
    </d:prop>
  </d:set>
</c:mkcalendar>
```
Réponse `201 Created`. Si le chemin existe déjà → `405 Method Not Allowed`.

---

## 3. Authentification & gestion des redirections (les 2 pièges majeurs)

### Piège n°1 — Guzzle casse la redirection de partition
`caldav.icloud.com` peut répondre `301/302` vers `pNN-caldav.icloud.com`. Deux comportements Guzzle par défaut ruinent la requête :
1. Sans `'strict' => true`, Guzzle suit les 301/302 **en transformant la méthode en GET et en jetant le corps** → ton PROPFIND devient un GET → `400 Bad Request` (iCloud ne répond pas au GET sur la racine).
2. Depuis les correctifs CVE-2022-31043 / **CVE-2022-31090** (Guzzle ≥ 7.4.5), Guzzle **supprime l'en-tête `Authorization` sur tout changement d'hôte/scheme/port** → redirection vers la partition = `401`.

👉 **Solution : `'allow_redirects' => false` et gérer la redirection à la main**, en réémettant la requête complète (méthode + corps + Authorization) vers le nouvel hôte. Voir le code §6.

### Piège n°2 — `421 Misdirected Request`
Les hosts `pNN-caldav.icloud.com` partagent certificats et IP. En **HTTP/2, le coalescing de connexion** envoie une requête pour `p34-…` sur une connexion TLS ouverte vers `p67-…` → le serveur répond `421`. C'est un problème de transport, pas d'auth.
👉 **Forcer HTTP/1.1** (`CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1`, `'version' => '1.1'`) ; sur un 421 résiduel, **rouvrir une connexion neuve** (nouveau handle cURL / nouveau client Guzzle) et rejouer une fois.

### Table des erreurs
| Code | Cause typique | Action |
|---|---|---|
| `400` | GET sur une ressource WebDAV, ou XML malformé | corriger la requête |
| `401` | mot de passe principal utilisé au lieu de l'app-specific ; app password révoqué (changement du mdp Apple) ; Authorization perdu en redirection | marquer le compte `invalid_credentials`, notifier l'utilisateur, **ne pas retry** |
| `403` | calendrier en lecture seule / abonné ; ICS invalide (TZID sans VTIMEZONE) ; quota ou throttling | inspecter le corps XML (`<error>`), ne pas boucler |
| `404` | calendrier supprimé / href obsolète | relancer la découverte |
| `405` | MKCALENDAR sur un chemin existant | ignorer |
| `412` | `If-None-Match: *` sur ressource existante, ou `If-Match` avec ETag périmé | conflit → §9 |
| `415` | mauvais `Content-Type` sur le PUT | `text/calendar; charset=utf-8` |
| `421` | coalescing HTTP/2 | forcer HTTP/1.1 + nouvelle connexion |
| `429` / `503` | throttling iCloud | backoff exponentiel + jitter |
| `507` | quota dépassé (1 Go / 50 000 items) | alerte utilisateur |

---

## 4. CRUD d'événement

**Créer** — un fichier `.ics` = **un seul** UID (une seule série d'événements) :
```
PUT /200385701/calendars/papers-echeances/6F2A9E31-…-papers.ics HTTP/1.1
Host: p34-caldav.icloud.com
Content-Type: text/calendar; charset=utf-8
If-None-Match: *
```
→ `201 Created` (+ `ETag:` la plupart du temps). Si `412` : la ressource existe déjà (idempotence, cf. UID déterministe §9).

**Mettre à jour** : PUT complet (aucun PATCH en CalDAV) avec `If-Match: "C=12@U=…"`, incrémenter `SEQUENCE` → `204 No Content` (+ nouvel `ETag`).

**Supprimer** : `DELETE` + `If-Match: <etag>` → `204`.

⚠️ **iCloud n'est pas garanti de renvoyer l'`ETag` sur PUT.** S'il est absent, refaire un `PROPFIND Depth:0` de `getetag` sur la ressource pour le récupérer avant de le persister.

⚠️ Le **nom de fichier** n'a pas besoin d'être égal à l'UID, mais le faire est la convention la plus robuste. Encoder avec `rawurlencode()` et n'utiliser que des caractères sûrs dans l'UID (hex + tirets).

---

## 5. VTODO / Rappels iCloud — **à ne pas utiliser**

- Avant iOS 12 : les listes de Rappels étaient des collections CalDAV `VTODO` sur `caldav.icloud.com`, accessibles/écrivables.
- **Depuis iOS 13 / iPadOS / macOS Catalina**, Apple a migré Rappels vers un store privé (CloudKit). *« Apple moved those lists into a private Reminders sync store »*. Les listes **upgradées ne sont plus exposées via CalDAV** ; seules survivent d'éventuelles listes jamais migrées — sur la majorité des comptes en 2026, **zéro**.
- Conséquence : un `PROPFIND Depth:1` sur la home peut ne renvoyer **aucune** collection `VTODO`. Ne construis pas de feature dessus.
- **Ce qu'il faut faire pour Papers** : chaque tâche extraite → un **VEVENT** dans le calendrier "Papers – Échéances", en journée entière à la date d'échéance, avec `VALARM`. C'est ce qui déclenche fiablement une notification sur iPhone.
- Si tu veux vraiment des entrées dans l'app **Rappels** : seule voie réaliste = côté appareil (Raccourcis/Shortcuts déclenché par une notification push, ou une app native compagnon avec EventKit). Une PWA n'a aucun accès à EventKit.
- **Alternative sans identifiants** (à proposer en fallback) : exposer un flux ICS par utilisateur (`https://papers.app/feed/{token}.ics`) que l'utilisateur ajoute en *Calendrier avec abonnement* (Réglages → Apps → Calendrier → Comptes → Ajouter un compte → Autre → Ajouter un calendrier avec abonnement, ou lien `webcal://`). Lecture seule, rafraîchissement piloté par iOS.

---

## 6. Stack PHP 2026

| Paquet | Version (sept. 2026) | Rôle |
|---|---|---|
| `sabre/vobject` | **5.0.0** (2026-07-07, PHP ≥ 8.2) | construire/parser l'ICS (RFC 5545), pliage 75 octets + CRLF gérés |
| `guzzlehttp/guzzle` | 7.15.x | transport HTTP (PROPFIND/REPORT/PUT custom verbs) |
| `sabre/dav` | 4.7.0 (2024-10-29) | ⚠️ **serveur** DAV. `Sabre\DAV\Client` existe mais est minimal et suit les redirections via cURL → peu adapté ici |
| `sabre/xml`, `sabre/uri` | — | utiles pour parser proprement le multistatus |
| `smarcet/caldavclient` | maj 2026-01-27 | client CalDAV PHP testé iCloud — utilisable, mais peu maintenu ; je recommande **Guzzle brut + sabre/vobject** |

**Recommandation** : client maison Guzzle (≈300 lignes, contrôle total des redirections/421/ETags) + `sabre/vobject` pour l'ICS. Laravel **13** (sorti 17 mars 2026, PHP 8.3–8.5).

---

## 7. Structure VEVENT correcte pour iCloud

- `BEGIN:VCALENDAR` / `VERSION:2.0` / `PRODID:-//…//…//FR` / `CALSCALE:GREGORIAN` obligatoires.
- **VTIMEZONE** : dès que tu écris `DTSTART;TZID=Europe/Paris:…`, **un composant `VTIMEZONE` avec le même `TZID` doit être présent dans le même VCALENDAR**, sinon iCloud rejette (403) ou décale l'heure.
  👉 **Le plus simple et le plus sûr : écrire en UTC** (`DTSTART:20261015T080000Z`) → aucun VTIMEZONE nécessaire, iOS affiche dans le fuseau de l'appareil. N'utilise TZID+VTIMEZONE que pour les événements **récurrents** devant garder l'heure murale à travers les changements d'heure.
- **All-day** : `DTSTART;VALUE=DATE:20261015` et `DTEND;VALUE=DATE:20261016` — **DTEND est exclusif** (J+1). Oublier ça = événement de 0 jour invisible.
- `DTSTAMP` **toujours en UTC avec `Z`**.
- `DTEND` **ou** `DURATION`, jamais les deux.
- `SEQUENCE` : entier, à **incrémenter à chaque mise à jour** (sinon les clients peuvent ignorer le changement).
- `VALARM` : plusieurs autorisés. `ACTION:DISPLAY` + `DESCRIPTION` (obligatoire pour DISPLAY) + `TRIGGER`.
  - relatif : `TRIGGER:-PT1H`, `TRIGGER;RELATED=END:-PT15M`, `TRIGGER:-P1D`
  - absolu : `TRIGGER;VALUE=DATE-TIME:20261014T080000Z`
  - pour un all-day, `TRIGGER:-PT15H` depuis minuit ⇒ alerte la veille à 9 h.
- `URL:` accepte l'URL de la fiche document dans Papers (deep-link) — bien rendu par iOS.
- `X-APPLE-…` inutiles.

---

## 8. Sécurité (multi-utilisateurs)

- Colonne `TEXT` + cast Laravel `'app_password' => 'encrypted'`. Rappel doc Laravel 13 : *« since the values are encrypted in the database, you will not be able to query or search encrypted attribute values »* → ne jamais indexer/chercher dessus.
- Rotation de clé : `APP_KEY` + `APP_PREVIOUS_KEYS` (rotation gracieuse documentée par Laravel).
- **Ne jamais logger l'en-tête `Authorization`** : Guzzle le met dans les exceptions `RequestException` → configurer un handler qui strippe `Authorization` du message avant tout `Log::`/Sentry.
- **Détection d'un mot de passe invalidé** : un `401` sur une requête qui marchait ⇒ statut `invalid_credentials`, arrêt immédiat des jobs pour ce compte (pas de retry : boucler sur des 401 peut déclencher un blocage temporaire côté Apple), notification in-app + email pour re-saisie.
- Stocke `last_ok_at` et un `credentials_version` ; permets à l'utilisateur de révoquer côté Papers (suppression de la ligne) **et** rappelle-lui de révoquer sur `account.apple.com`.
- Chiffrement au repos ≠ suffisant : limite l'accès à `APP_KEY` (secret manager), et ne renvoie jamais le mot de passe dans une réponse API (même masqué partiellement).

---

## 9. Synchronisation, idempotence, conflits

**UID déterministe** (clé de l'idempotence, essentielle quand OpenAI ré-analyse un document) :
```
uid = UUIDv5(NAMESPACE_PAPERS, "{user_id}:{document_id}:{task_key}") + "@papers.app"
```
Même tâche re-générée ⇒ même UID ⇒ même href ⇒ PUT idempotent.

**Détection des changements côté Apple** :
1. Cheap poll : `PROPFIND Depth:0` sur `getctag` (+`sync-token`). Si inchangé → rien à faire.
2. Si changé : `REPORT sync-collection` avec le `sync-token` mémorisé → ne renvoie que les href modifiés/supprimés (les supprimés arrivent avec `<status>HTTP/1.1 404 Not Found</status>`).
3. iCloud pagine : s'il renvoie `507` ou tronque, respecter `<d:limit><d:nresults>` et reboucler avec le nouveau token.
4. **Token invalide (`403` avec `<d:valid-sync-token/>`)** ⇒ resync complète via `calendar-query`.

**Conflits** :
- Écriture Papers : toujours `If-Match: <etag_stocké>`. `412` ⇒ l'utilisateur a modifié l'événement sur son iPhone. Politique recommandée : **l'appareil gagne** (GET, on log la divergence, on n'écrase pas), sauf si l'utilisateur a explicitement re-validé la tâche dans Papers (alors PUT sans If-Match ou avec le nouvel ETag + `SEQUENCE+1`).
- Suppression côté iPhone (404 au GET) ⇒ marquer la tâche `calendar_detached`, ne pas recréer en boucle.

**Limites iCloud officielles (Apple, HT103188)** : 50 000 items au total (calendriers + événements + rappels) ; 100 calendriers/listes max ; 1 Go de données calendrier ; **20 Mo par événement** ; 20 pièces jointes/événement ; 300 invités ; 100 personnes pour un calendrier partagé.
**Rate limits : non documentés par Apple.** Pratique observée dans les implémentations open source : **plafonner à ~5–10 requêtes/s par compte**, 3 tentatives max avec backoff exponentiel, timeout de lecture **≥ 25–30 s** (10 s est trop agressif, iCloud est lent sur les gros PROPFIND), et traiter `403/429/503` comme transitoires avec backoff.

---

## 10. Pièges ICS à connaître

1. **CRLF obligatoire** (`\r\n`) entre toutes les lignes, y compris la dernière. `\n` seul ⇒ 403/parse errors.
2. **Pliage à 75 octets** (pas 75 caractères) : ligne suivante préfixée d'**un espace** (qui compte dans les 75). Ne jamais couper au milieu d'une séquence UTF-8 multi-octets — un `é` fait 2 octets. `sabre/vobject::serialize()` le fait correctement ; du code maison doit compter en octets tout en itérant en points de code.
3. **Accents** : pas d'encodage spécial, c'est de l'UTF-8 brut, mais le `Content-Type` **doit** porter `; charset=utf-8`.
4. **Échappement TEXT** : dans `SUMMARY`/`DESCRIPTION`/`LOCATION`, échapper dans cet ordre `\` → `\\`, puis `;` → `\;`, `,` → `\,`, et remplacer les retours à la ligne par le littéral `\n`. Ne PAS échapper `:` ni `"`.
5. **DTEND all-day exclusif** (J+1).
6. `DTSTAMP` en UTC, différent de `LAST-MODIFIED`.
7. `TZID` sans `VTIMEZONE` correspondant = rejet iCloud.
8. Un `.ics` = un UID. Mettre 10 événements dans un fichier casse la sémantique CalDAV.
9. `METHOD:PUBLISH` **ne doit pas** figurer dans un ICS poussé par PUT CalDAV (c'est pour l'iTIP/email).
10. `ORGANIZER` + `ATTENDEE` déclenchent le scheduling iCloud (envoi d'invitations réelles). **À omettre** pour des rappels personnels.
11. `URL:` avec des `,`/`;` doit aussi être échappé.

---

## 11. Intégration Laravel recommandée

- Table `icloud_accounts` (user_id, apple_id, app_password chiffré, principal_url, calendar_home_url, calendar_url, sync_token, ctag, status, last_ok_at).
- Table `calendar_objects` (task_id, icloud_account_id, uid, href, etag, sequence, last_pushed_at, state).
- Job `PushTaskToICloud` avec `WithoutOverlapping($accountId)` + `RateLimited('icloud-caldav')` (RateLimiter `perSecond(5)` clé = account id) + `$backoff = [5, 30, 120]`, et `$tries = 3`.
- Job `PullICloudChanges` planifié toutes les 15 min (ctag cheap-check puis sync-collection).
- Un `ICloudCalDavClient` non-Laravel-dépendant (testable avec `GuzzleHttp\Handler\MockHandler`).
- Bun/Vite ne sert qu'au front PWA ; **aucune** requête CalDAV depuis le navigateur (CORS bloqué par Apple + fuite du mot de passe).

## Faits clés vérifiés

- CalDAV iCloud est gratuit : aucun compte Apple Developer requis, juste un Apple Account avec 2FA activée.
- Un app-specific password se genere sur account.apple.com > Sign-In and Security > App-Specific Passwords ; maximum 25 actifs simultanement.
- Tout changement/reinitialisation du mot de passe Apple principal revoque AUTOMATIQUEMENT tous les app-specific passwords (source : Apple HT102654).
- URL racine CalDAV iCloud : https://caldav.icloud.com/ (HTTPS port 443 uniquement).
- Decouverte en 3 etapes : PROPFIND / (current-user-principal) -> PROPFIND /{dsid}/principal/ (calendar-home-set) -> PROPFIND Depth:1 /{dsid}/calendars/.
- Le principal renvoye a la forme /200385701/principal/ ou 200385701 est le DSID numerique du compte iCloud.
- Le calendar-home-set renvoie un href ABSOLU vers une partition numerotee : https://pNN-caldav.icloud.com:443/{dsid}/calendars/ (NN varie par compte : p34, p67, p127...). Il faut le persister.
- Guzzle, par defaut (sans 'strict' => true), transforme un PROPFIND redirige en 301/302 en GET et jette le corps de la requete.
- Depuis les correctifs CVE-2022-31043 et CVE-2022-31090 (Guzzle >= 7.4.5), Guzzle SUPPRIME l'en-tete Authorization sur tout changement d'hote, de scheme ou de port lors d'une redirection -> 401 sur la redirection vers la partition iCloud. Solution : allow_redirects=false + redirection manuelle.
- HTTP 421 Misdirected Request sur iCloud vient du coalescing de connexion HTTP/2 (partitions partageant IP et certificat wildcard) : forcer HTTP/1.1 (CURLOPT_HTTP_VERSION = CURL_HTTP_VERSION_1_1) et rejouer sur une connexion neuve.
- 401 = mot de passe principal utilise au lieu de l'app-specific, ou app password revoque. 400 = GET sur une ressource qui n'accepte que les verbes WebDAV.
- MKCALENDAR est supporte par iCloud (avec droits d'ecriture) : permet de creer un calendrier dedie 'Papers - Echeances'.
- Verbes supportes par iCloud : PROPFIND (Depth 0/1), MKCALENDAR, REPORT (calendar-query, calendar-multiget, free-busy-query, sync-collection), PUT, DELETE, GET, COPY, MOVE. PAS de PATCH : toute modification est un PUT du VCALENDAR complet.
- Creation d'evenement : PUT <calendar>/<uid>.ics avec Content-Type: text/calendar; charset=utf-8 et If-None-Match: * -> 201 Created. Mise a jour : PUT avec If-Match: <etag> -> 204. Suppression : DELETE avec If-Match.
- iCloud ne renvoie pas systematiquement l'ETag sur le PUT : prevoir un PROPFIND Depth:0 de getetag en repli.
- Les Rappels Apple (VTODO) ne sont PLUS accessibles via CalDAV depuis iOS 13 / macOS Catalina : Apple a migre Reminders vers un store prive CloudKit. Seules d'eventuelles listes jamais migrees restent exposees, soit zero sur la plupart des comptes en 2026.
- supported-calendar-component-set permet de distinguer les collections VEVENT des collections VTODO (listes de Rappels legacy) ; filtrer aussi inbox/outbox/notifications.
- calendar-color iCloud est au format #RRGGBBAA (alpha inclus) dans le namespace http://apple.com/ns/ical/.
- VTIMEZONE : tout TZID reference dans DTSTART/DTEND doit avoir un composant VTIMEZONE correspondant dans le meme VCALENDAR, sinon rejet (403) ou decalage horaire. Ecrire en UTC (suffixe Z) evite totalement le probleme.
- All-day : DTSTART;VALUE=DATE:20261015 + DTEND;VALUE=DATE:20261016 (DTEND exclusif, J+1).
- VALARM multiples autorises ; ACTION:DISPLAY exige un DESCRIPTION ; TRIGGER relatif (-PT1H, -P1D, RELATED=END) ou absolu (TRIGGER;VALUE=DATE-TIME:...Z).
- sabre/vobject 5.0.0, publiee le 7 juillet 2026, requiert PHP >= 8.2 (compatible 8.4 et 8.5) ; breaking change principal : types de parametres et de retour declares partout.
- sabre/dav est en 4.7.0 (29 octobre 2024) ; c'est un framework serveur, son Sabre\DAV\Client est minimal - preferer Guzzle brut cote client.
- smarcet/caldavclient (dernier update 2026-01-27) est un client CalDAV PHP teste avec iCloud, base sur guzzlehttp/guzzle, sabre/uri, sabre/xml, eluceo/ical.
- Laravel 13 est la version courante (sortie le 17 mars 2026), requiert PHP >= 8.3 et supporte PHP 8.3, 8.4 et 8.5.
- Cast Laravel 'encrypted' : la colonne doit etre de type TEXT ou plus grand, et les valeurs chiffrees ne peuvent etre ni requetees ni recherchees. Rotation de cle via APP_PREVIOUS_KEYS.
- Limites iCloud officielles (Apple HT103188) : 50 000 items au total (calendriers + evenements + rappels), 100 calendriers et listes de rappels combines, 1 Go de donnees calendrier, 20 Mo par evenement, 20 pieces jointes par evenement, 300 invites, 100 personnes par calendrier partage.
- Apple ne documente aucun rate limit CalDAV ; les implementations open source plafonnent a ~10 req/s par compte avec 3 tentatives et backoff exponentiel, et traitent 403/429/503 comme transitoires.
- Le timeout de lecture doit etre >= 25-30 s : 10 s provoque des ReadTimeout frequents sur iCloud CalDAV.
- Synchronisation : PROPFIND getctag (cheap check) puis REPORT sync-collection avec sync-token ; les ressources supprimees remontent avec <status>HTTP/1.1 404 Not Found</status>. Pas de webhook/push cote Apple : polling obligatoire.
- Pliage ICS : 75 OCTETS (pas caracteres), continuation prefixee d'un espace compte dans la limite ; CRLF obligatoire partout ; echappement TEXT dans l'ordre backslash, puis ; et , puis newline -> \n.

## Pièges / ce qui ne marche PAS

- NE PAS laisser Guzzle suivre les redirections : il perd l'Authorization sur changement d'hote (CVE-2022-31090) ET transforme PROPFIND en GET sans corps. Mettre allow_redirects=false et rejouer manuellement.
- NE PAS utiliser HTTP/2 vers les partitions pNN-caldav.icloud.com : coalescing -> 421 Misdirected Request aleatoire. Forcer HTTP/1.1.
- NE PAS construire de fonctionnalite sur les VTODO / Rappels iCloud : casse depuis iOS 13, la plupart des comptes n'exposent aucune liste VTODO via CalDAV. Une PWA n'a aucun acces a EventKit non plus.
- NE PAS faire de requetes CalDAV depuis le navigateur/PWA : Apple ne renvoie pas de headers CORS et cela exposerait l'app-specific password au client.
- NE PAS valider strictement le format xxxx-xxxx-xxxx-xxxx du mot de passe d'application : Apple ne le documente pas officiellement et pourrait le changer. Normaliser (trim/lowercase) sans rejeter.
- NE PAS boucler avec retry sur un 401 : c'est un etat terminal (mot de passe revoque). Marquer le compte invalid_credentials et notifier l'utilisateur.
- NE PAS hardcoder le numero de partition ni le DSID : ils sont propres a chaque compte. Les redecouvrir et les persister par compte.
- NE PAS oublier que DTEND d'un evenement all-day est EXCLUSIF (J+1) : sinon evenement invisible.
- NE PAS utiliser TZID sans inclure le VTIMEZONE correspondant : iCloud rejette (403) ou decale l'heure. Preferer l'UTC avec suffixe Z.
- NE PAS mettre plusieurs UID differents dans un meme fichier .ics : CalDAV impose une ressource = un objet calendaire.
- NE PAS inclure METHOD:PUBLISH dans un ICS pousse par PUT CalDAV (reserve a l'iTIP/email).
- NE PAS mettre ORGANIZER/ATTENDEE pour des rappels personnels : iCloud declenche le scheduling et envoie de vraies invitations.
- NE PAS plier les lignes ICS a 75 caracteres : c'est 75 OCTETS, et couper au milieu d'un caractere UTF-8 multi-octets corrompt le fichier.
- NE PAS utiliser \n seul comme separateur de lignes ICS : CRLF obligatoire.
- NE PAS tenter d'ecrire dans un calendrier abonne ou partage en lecture seule : verifier current-user-privilege-set (write-content + bind) avant, sinon 403.
- NE PAS oublier de filtrer inbox/outbox/notifications dans le PROPFIND Depth:1 de la home collection.
- NE PAS logger l'exception Guzzle brute : elle contient l'en-tete Authorization en clair. Stripper avant Log/Sentry.
- NE PAS indexer ni requeter la colonne app_password (cast encrypted) : Laravel documente explicitement que c'est impossible.
- NE PAS utiliser un timeout de 10 s : iCloud CalDAV est lent, cela provoque des ReadTimeout frequents.
- NE PAS supposer que le PUT renvoie toujours un ETag : prevoir un PROPFIND getetag de repli.
- sabre/dav 4.7.0 est un framework SERVEUR ; son Client est trop limite (redirections cURL non maitrisees) pour ce cas d'usage.
- Migrer vers sabre/vobject 5.0.0 depuis la 4.x introduit des types stricts : du code passant null ou des types laxistes cassera.

## Extraits de code de référence

### Extrait 1

// composer.json — dependances verifiees (sept. 2026)
{
  "require": {
    "php": "^8.3",
    "laravel/framework": "^13.0",
    "guzzlehttp/guzzle": "^7.15",
    "sabre/vobject": "^5.0",
    "ramsey/uuid": "^4.7"
  }
}

### Extrait 2

<?php
// database/migrations/xxxx_create_icloud_accounts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('icloud_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('apple_id');                    // email Apple ID
            $t->text('app_password');                  // CHIFFRE -> TEXT obligatoire
            $t->string('principal_url')->nullable();   // https://caldav.icloud.com/200385701/principal/
            $t->string('calendar_home_url')->nullable(); // https://p34-caldav.icloud.com/200385701/calendars/
            $t->string('calendar_url')->nullable();    // calendrier cible 'Papers - Echeances'
            $t->text('sync_token')->nullable();
            $t->string('ctag')->nullable();
            $t->string('status')->default('pending');  // pending|ok|invalid_credentials|error
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'apple_id']);
        });

        Schema::create('calendar_objects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('icloud_account_id')->constrained()->cascadeOnDelete();
            $t->string('uid');                 // UID deterministe (UUIDv5)
            $t->string('href');                // chemin absolu de la ressource .ics
            $t->string('etag')->nullable();
            $t->unsignedInteger('sequence')->default(0);
            $t->string('state')->default('pending'); // pending|synced|conflict|detached
            $t->timestamp('last_pushed_at')->nullable();
            $t->timestamps();
            $t->unique(['icloud_account_id', 'uid']);
        });
    }
};

### Extrait 3

<?php
// app/Models/ICloudAccount.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ICloudAccount extends Model
{
    protected $table = 'icloud_accounts';

    protected $fillable = ['user_id', 'apple_id', 'app_password'];

    // Ne JAMAIS serialiser le secret
    protected $hidden = ['app_password'];

    protected function casts(): array
    {
        return [
            'app_password' => 'encrypted',   // AES via APP_KEY ; colonne TEXT ; non requetable
            'last_ok_at'   => 'datetime',
        ];
    }

    /** Normalisation permissive : Apple ne documente pas le format officiellement. */
    public function setAppPasswordAttribute(string $value): void
    {
        $this->attributes['app_password'] = encrypt(
            strtolower(preg_replace('/\s+/', '', trim($value)))
        );
    }
}

### Extrait 4

<?php
// app/Services/CalDav/ICloudCalDavClient.php
declare(strict_types=1);

namespace App\Services\CalDav;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class ICloudCalDavClient
{
    public const ROOT = 'https://caldav.icloud.com/';

    private Client $http;

    public function __construct(
        private readonly string $appleId,
        private readonly string $appPassword,
    ) {
        $this->http = $this->makeClient();
    }

    private function makeClient(): Client
    {
        return new Client([
            'http_errors'     => false,
            'timeout'         => 30,   // 10s est trop agressif pour iCloud
            'connect_timeout' => 10,
            'version'         => '1.1',
            'curl'            => [
                // Evite les 421 Misdirected Request (coalescing HTTP/2 entre partitions pNN)
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FORBID_REUSE => false,
            ],
            // CRITIQUE : Guzzle transforme PROPFIND->GET sur 301/302 et supprime
            // Authorization sur changement d'hote (CVE-2022-31090). On gere a la main.
            'allow_redirects' => false,
            'headers'         => ['User-Agent' => 'Papers/1.0 (CalDAV)'],
        ]);
    }

    /**
     * Envoie une requete DAV en gerant manuellement redirections + 421.
     */
    public function dav(string $method, string $url, ?string $body = null, array $headers = []): ResponseInterface
    {
        $headers = array_merge([
            'Authorization' => 'Basic ' . base64_encode($this->appleId . ':' . $this->appPassword),
            'Accept'        => 'application/xml, text/xml, text/calendar',
        ], $headers);

        if ($body !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';
        }

        $hops = 0;
        $retried421 = false;

        while (true) {
            $res = $this->http->send(new Request($method, $url, $headers, $body));
            $code = $res->getStatusCode();

            // 301/302/307/308 : on rejoue la MEME methode avec le MEME corps ET l'auth
            if (in_array($code, [301, 302, 307, 308], true) && $res->hasHeader('Location') && $hops < 5) {
                $url = \GuzzleHttp\Psr7\UriResolver::resolve(
                    new \GuzzleHttp\Psr7\Uri($url),
                    new \GuzzleHttp\Psr7\Uri($res->getHeaderLine('Location'))
                )->__toString();
                $hops++;
                continue;
            }

            // 421 : connexion TLS mal dirigee -> nouvelle connexion, un seul retry
            if ($code === 421 && !$retried421) {
                $this->http = $this->makeClient();
                $retried421 = true;
                continue;
            }

            if ($code === 401) {
                throw new ICloudAuthException(
                    'app-specific password invalide ou revoque pour ' . $this->appleId
                );
            }

            return $res;
        }
    }

    private function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('d',  'DAV:');
        $xp->registerNamespace('c',  'urn:ietf:params:xml:ns:caldav');
        $xp->registerNamespace('cs', 'http://calendarserver.org/ns/');
        $xp->registerNamespace('a',  'http://apple.com/ns/ical/');
        return $xp;
    }

    private function abs(string $base, string $href): string
    {
        return (string) \GuzzleHttp\Psr7\UriResolver::resolve(
            new \GuzzleHttp\Psr7\Uri($base),
            new \GuzzleHttp\Psr7\Uri(trim($href))
        );
    }

    /** Etape 1 : current-user-principal */
    public function discoverPrincipal(): string
    {
        $body = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop><d:current-user-principal/></d:prop>
        </d:propfind>
        XML;

        $res = $this->dav('PROPFIND', self::ROOT, $body, ['Depth' => '0']);
        if ($res->getStatusCode() !== 207) {
            throw new \RuntimeException('PROPFIND principal: HTTP ' . $res->getStatusCode());
        }

        $xp = $this->xpath((string) $res->getBody());
        $href = $xp->query('//d:current-user-principal/d:href')?->item(0)?->textContent;
        if (!$href) {
            throw new \RuntimeException('current-user-principal absent de la reponse');
        }
        return $this->abs(self::ROOT, $href); // https://caldav.icloud.com/200385701/principal/
    }

    /** Etape 2 : calendar-home-set -> renvoie l'URL absolue sur pNN-caldav.icloud.com */
    public function discoverCalendarHome(string $principalUrl): string
    {
        $body = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
          <d:prop>
            <c:calendar-home-set/>
            <d:displayname/>
          </d:prop>
        </d:propfind>
        XML;

        $res = $this->dav('PROPFIND', $principalUrl, $body, ['Depth' => '0']);
        $xp  = $this->xpath((string) $res->getBody());
        $href = $xp->query('//c:calendar-home-set/d:href')?->item(0)?->textContent;
        if (!$href) {
            throw new \RuntimeException('calendar-home-set absent');
        }
        // ex. https://p34-caldav.icloud.com:443/200385701/calendars/
        return rtrim($this->abs($principalUrl, $href), '/') . '/';
    }

    /** Etape 3 : liste des calendriers ecrivables supportant VEVENT */
    public function listCalendars(string $homeUrl): array
    {
        $body = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:"
                    xmlns:c="urn:ietf:params:xml:ns:caldav"
                    xmlns:cs="http://calendarserver.org/ns/"
                    xmlns:a="http://apple.com/ns/ical/">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
            <d:sync-token/>
            <cs:getctag/>
            <a:calendar-color/>
            <c:supported-calendar-component-set/>
          </d:prop>
        </d:propfind>
        XML;

        $res = $this->dav('PROPFIND', $homeUrl, $body, ['Depth' => '1']);
        $xp  = $this->xpath((string) $res->getBody());

        $out = [];
        foreach ($xp->query('//d:response') as $node) {
            $href = trim($xp->evaluate('string(d:href)', $node));
            if ($href === '' || rtrim($this->abs($homeUrl, $href), '/') === rtrim($homeUrl, '/')) {
                continue; // la home elle-meme
            }

            // doit etre une collection CalDAV
            if ($xp->evaluate('count(.//d:resourcetype/c:calendar)', $node) == 0) {
                continue;
            }
            // exclure inbox / outbox / notifications
            if ($xp->evaluate('count(.//d:resourcetype/c:schedule-inbox)', $node) > 0
             || $xp->evaluate('count(.//d:resourcetype/c:schedule-outbox)', $node) > 0
             || $xp->evaluate('count(.//d:resourcetype/cs:notification)', $node) > 0) {
                continue;
            }

            $comps = [];
            foreach ($xp->query('.//c:supported-calendar-component-set/c:comp', $node) as $c) {
                $comps[] = strtoupper($c->getAttribute('name'));
            }
            // pas de composant declare = tout supporte (rare chez iCloud)
            if ($comps !== [] && !in_array('VEVENT', $comps, true)) {
                continue; // collection VTODO-only = liste de Rappels legacy
            }

            $writable = $xp->evaluate('count(.//d:current-user-privilege-set/d:privilege/d:write-content)', $node) > 0
                     && $xp->evaluate('count(.//d:current-user-privilege-set/d:privilege/d:bind)', $node) > 0;

            $color = trim($xp->evaluate('string(.//a:calendar-color)', $node));

            $out[] = [
                'url'         => $this->abs($homeUrl, $href),
                'displayname' => trim($xp->evaluate('string(.//d:displayname)', $node)),
                'components'  => $comps,
                'color'       => $color !== '' ? substr($color, 0, 7) : null, // #RRGGBBAA -> #RRGGBB
                'ctag'        => trim($xp->evaluate('string(.//cs:getctag)', $node)) ?: null,
                'sync_token'  => trim($xp->evaluate('string(.//d:sync-token)', $node)) ?: null,
                'writable'    => $writable,
            ];
        }
        return $out;
    }
}

### Extrait 5

<?php
// Creation d'un calendrier dedie (MKCALENDAR) + CRUD evenement

/** Cree 'Papers - Echeances'. Renvoie l'URL, ou null si deja existant (405). */
public function createCalendar(string $homeUrl, string $slug, string $name, string $colorHexAlpha = '#FF9500FF'): ?string
{
    $url  = rtrim($homeUrl, '/') . '/' . rawurlencode($slug) . '/';
    $body = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">
      <d:set>
        <d:prop>
          <d:displayname>{$name}</d:displayname>
          <c:calendar-description xml:lang="fr">Echeances extraites de vos documents</c:calendar-description>
          <a:calendar-color symbolic-color="orange">{$colorHexAlpha}</a:calendar-color>
          <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
        </d:prop>
      </d:set>
    </c:mkcalendar>
    XML;

    $res = $this->dav('MKCALENDAR', $url, $body);
    return match ($res->getStatusCode()) {
        201      => $url,
        405, 301 => $url,   // existe deja
        default  => throw new \RuntimeException('MKCALENDAR HTTP ' . $res->getStatusCode() . ' : ' . $res->getBody()),
    };
}

/** PUT initial, idempotent grace a If-None-Match: * */
public function putNew(string $calendarUrl, string $uid, string $ics): array
{
    $href = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';
    $res  = $this->dav('PUT', $href, $ics, [
        'Content-Type'  => 'text/calendar; charset=utf-8',
        'If-None-Match' => '*',
    ]);

    $code = $res->getStatusCode();
    if ($code === 412) {
        // deja present cote iCloud : on recupere l'etag courant
        return ['href' => $href, 'etag' => $this->fetchEtag($href), 'created' => false];
    }
    if (!in_array($code, [200, 201, 204], true)) {
        throw new \RuntimeException("PUT HTTP {$code} : " . $res->getBody());
    }

    // iCloud ne renvoie PAS toujours l'ETag sur le PUT
    $etag = $res->getHeaderLine('ETag') ?: $this->fetchEtag($href);
    return ['href' => $href, 'etag' => $etag, 'created' => true];
}

/** Mise a jour conditionnelle. Renvoie null si conflit (412). */
public function putUpdate(string $href, string $ics, string $etag): ?string
{
    $res = $this->dav('PUT', $href, $ics, [
        'Content-Type' => 'text/calendar; charset=utf-8',
        'If-Match'     => $etag,
    ]);

    if ($res->getStatusCode() === 412) {
        return null; // modifie cote iPhone -> politique de resolution appelant
    }
    if (!in_array($res->getStatusCode(), [200, 204], true)) {
        throw new \RuntimeException('PUT update HTTP ' . $res->getStatusCode());
    }
    return $res->getHeaderLine('ETag') ?: $this->fetchEtag($href);
}

public function delete(string $href, ?string $etag = null): bool
{
    $headers = $etag ? ['If-Match' => $etag] : [];
    $res = $this->dav('DELETE', $href, null, $headers);
    return in_array($res->getStatusCode(), [200, 204, 404], true);
}

private function fetchEtag(string $href): ?string
{
    $body = '<?xml version="1.0" encoding="UTF-8"?>'
          . '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>';
    $res  = $this->dav('PROPFIND', $href, $body, ['Depth' => '0']);
    $xp   = $this->xpath((string) $res->getBody());
    return trim($xp->evaluate('string(//d:getetag)')) ?: null;
}

### Extrait 6

<?php
// app/Services/CalDav/DeadlineIcsBuilder.php — construction ICS avec sabre/vobject 5.0
declare(strict_types=1);

namespace App\Services\CalDav;

use Ramsey\Uuid\Uuid;
use Sabre\VObject\Component\VCalendar;

final class DeadlineIcsBuilder
{
    // namespace UUIDv5 fige une fois pour toutes dans le projet
    private const NS = '6ba7b814-9dad-11d1-80b4-00c04fd430c8';

    /** UID deterministe : re-analyser le meme document produit le meme UID. */
    public static function uid(int $userId, int $documentId, string $taskKey): string
    {
        return Uuid::uuid5(self::NS, "{$userId}:{$documentId}:{$taskKey}")->toString() . '@papers.app';
    }

    /**
     * Evenement "journee entiere" a la date d'echeance, avec 2 rappels.
     * All-day => aucun probleme de fuseau horaire, aucun VTIMEZONE necessaire.
     */
    public function allDayDeadline(
        string $uid,
        \DateTimeImmutable $dueDate,
        string $summary,
        string $description,
        ?string $location = null,
        ?string $url = null,
        int $sequence = 0,
    ): string {
        $vcal = new VCalendar([
            'PRODID'   => '-//BWA Agence//Papers 1.0//FR',
            'VERSION'  => '2.0',
            'CALSCALE' => 'GREGORIAN',
        ]);
        // NE PAS ajouter METHOD:PUBLISH sur un PUT CalDAV.

        $ev = $vcal->add('VEVENT', [
            'UID'         => $uid,
            'DTSTAMP'     => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'SUMMARY'     => $summary,
            'DESCRIPTION' => $description,
            'SEQUENCE'    => $sequence,
            'TRANSP'      => 'TRANSPARENT',
            'CATEGORIES'  => 'Papers',
            'STATUS'      => 'CONFIRMED',
        ]);

        // VALUE=DATE ; DTEND EXCLUSIF (J+1)
        $ev->add('DTSTART', $dueDate->format('Ymd'), ['VALUE' => 'DATE']);
        $ev->add('DTEND',   $dueDate->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);

        if ($location !== null) { $ev->add('LOCATION', $location); }
        if ($url !== null)      { $ev->add('URL', $url, ['VALUE' => 'URI']); }

        // Rappel 1 : la veille a 09h00 (minuit - 15h)
        $a1 = $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $summary]);
        $a1->add('TRIGGER', '-PT15H', ['RELATED' => 'START']);

        // Rappel 2 : 7 jours avant, a 09h00
        $a2 = $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => 'Dans 7 jours : ' . $summary]);
        $a2->add('TRIGGER', '-P6DT15H', ['RELATED' => 'START']);

        // serialize() produit du CRLF et plie a 75 OCTETS (UTF-8 safe)
        return $vcal->serialize();
    }

    /**
     * Evenement horodate. En UTC -> pas de VTIMEZONE requis.
     * (N'utiliser TZID + VTIMEZONE que pour du recurrent a heure murale fixe.)
     */
    public function timedDeadline(string $uid, \DateTimeImmutable $startLocal, int $durationMin, string $summary, string $description, int $sequence = 0): string
    {
        $utc = new \DateTimeZone('UTC');
        $start = $startLocal->setTimezone($utc);

        $vcal = new VCalendar([
            'PRODID'   => '-//BWA Agence//Papers 1.0//FR',
            'VERSION'  => '2.0',
            'CALSCALE' => 'GREGORIAN',
        ]);

        $ev = $vcal->add('VEVENT', [
            'UID'         => $uid,
            'DTSTAMP'     => new \DateTimeImmutable('now', $utc),
            'SUMMARY'     => $summary,
            'DESCRIPTION' => $description,
            'SEQUENCE'    => $sequence,
        ]);
        $ev->add('DTSTART', $start);                                   // -> DTSTART:20261015T080000Z
        $ev->add('DTEND',   $start->modify("+{$durationMin} minutes")); // DTEND ou DURATION, jamais les deux

        $al = $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $summary]);
        $al->add('TRIGGER', '-PT1H');

        return $vcal->serialize();
    }
}

### Extrait 7

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//BWA Agence//Papers 1.0//FR
CALSCALE:GREGORIAN
BEGIN:VEVENT
UID:7f3a1c22-8e1b-5a9d-b0c4-1f2e3d4a5b6c@papers.app
DTSTAMP:20260917T101500Z
SUMMARY:Echeance : facture EDF 128\,40 EUR
DESCRIPTION:Extrait de "Facture EDF septembre 2026".\nMontant : 128\,40 EU
 R\nReference : 9032-4471\nA payer avant le 15/10/2026.
LOCATION:
URL;VALUE=URI:https://papers.bwagence.fr/documents/4821
CATEGORIES:Papers
STATUS:CONFIRMED
TRANSP:TRANSPARENT
SEQUENCE:0
DTSTART;VALUE=DATE:20261015
DTEND;VALUE=DATE:20261016
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Echeance : facture EDF 128\,40 EUR
TRIGGER;RELATED=START:-PT15H
END:VALARM
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Dans 7 jours : facture EDF
TRIGGER;RELATED=START:-P6DT15H
END:VALARM
END:VEVENT
END:VCALENDAR


### Extrait 8

BEGIN:VTIMEZONE
TZID:Europe/Paris
X-LIC-LOCATION:Europe/Paris
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE


### Extrait 9

<?php
// Pliage + echappement ICS a la main (si tu n'utilises pas sabre/vobject)

/** Echappement TEXT RFC 5545 : ordre IMPERATIF (backslash d'abord). */
function icsText(string $v): string
{
    $v = str_replace('\\', '\\\\', $v);
    $v = str_replace([';', ','], ['\\;', '\\,'], $v);
    return preg_replace("/\r\n|\r|\n/", '\\n', $v);
}

/** Pliage a 75 OCTETS, sans jamais couper un caractere UTF-8. */
function icsFold(string $line): string
{
    $out = '';
    $len = 0;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $b = strlen($ch);            // strlen = octets
        if ($len + $b > 75) {
            $out .= "\r\n ";          // l'espace de continuation compte dans les 75
            $len = 1;
        }
        $out .= $ch;
        $len += $b;
    }
    return $out;
}

function icsLines(array $lines): string
{
    return implode('', array_map(fn ($l) => icsFold($l) . "\r\n", $lines));
}

### Extrait 10

<?php
// REPORT sync-collection (RFC 6578) — detecter les changements cote Apple

public function syncCollection(string $calendarUrl, ?string $syncToken): array
{
    $tokenEl = $syncToken ? '<d:sync-token>' . htmlspecialchars($syncToken, ENT_XML1) . '</d:sync-token>'
                          : '<d:sync-token/>';

    $body = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <d:sync-collection xmlns:d="DAV:">
      {$tokenEl}
      <d:sync-level>1</d:sync-level>
      <d:limit><d:nresults>200</d:nresults></d:limit>
      <d:prop>
        <d:getetag/>
        <d:getcontenttype/>
      </d:prop>
    </d:sync-collection>
    XML;

    $res = $this->dav('REPORT', $calendarUrl, $body, ['Depth' => '1']);

    // sync-token invalide/expire : iCloud repond 403 + <d:valid-sync-token/> -> resync complet
    if ($res->getStatusCode() === 403 && str_contains((string) $res->getBody(), 'valid-sync-token')) {
        return ['reset' => true, 'changed' => [], 'deleted' => [], 'sync_token' => null];
    }

    $xp = $this->xpath((string) $res->getBody());
    $changed = $deleted = [];

    foreach ($xp->query('//d:response') as $n) {
        $href   = trim($xp->evaluate('string(d:href)', $n));
        $status = trim($xp->evaluate('string(d:status)', $n));
        if (str_contains($status, '404')) {
            $deleted[] = $href;
        } else {
            $changed[$href] = trim($xp->evaluate('string(.//d:getetag)', $n));
        }
    }

    return [
        'reset'      => false,
        'changed'    => $changed,
        'deleted'    => $deleted,
        'sync_token' => trim($xp->evaluate('string(//d:multistatus/d:sync-token)')) ?: null,
    ];
}

/** Cheap check : le ctag a-t-il bouge ? (1 requete, tres peu couteuse) */
public function ctag(string $calendarUrl): ?string
{
    $body = '<?xml version="1.0" encoding="UTF-8"?>'
          . '<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
          . '<d:prop><cs:getctag/><d:sync-token/></d:prop></d:propfind>';
    $res = $this->dav('PROPFIND', $calendarUrl, $body, ['Depth' => '0']);
    $xp  = $this->xpath((string) $res->getBody());
    return trim($xp->evaluate('string(//cs:getctag)')) ?: null;
}

### Extrait 11

<?xml version="1.0" encoding="UTF-8"?>
<!-- REPORT calendar-query : evenements sur une plage de dates -->
<!-- REPORT <calendar-url>  |  Depth: 1  |  Content-Type: application/xml; charset=utf-8 -->
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag/>
    <c:calendar-data/>
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="20260901T000000Z" end="20261201T000000Z"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>

<!-- REPORT calendar-multiget : recuperer N ressources connues en une requete -->
<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag/>
    <c:calendar-data/>
  </d:prop>
  <d:href>/200385701/calendars/papers-echeances/7f3a1c22-....ics</d:href>
  <d:href>/200385701/calendars/papers-echeances/9b1d4e07-....ics</d:href>
</c:calendar-multiget>

### Extrait 12

<?php
// app/Jobs/PushDeadlineToICloud.php — job Laravel 13 avec throttling et gestion 401
declare(strict_types=1);

namespace App\Jobs;

use App\Models\CalendarObject;
use App\Models\Task;
use App\Services\CalDav\DeadlineIcsBuilder;
use App\Services\CalDav\ICloudAuthException;
use App\Services\CalDav\ICloudCalDavClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class PushDeadlineToICloud implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60, 300]; // backoff exponentiel

    public function __construct(public int $taskId) {}

    public function middleware(): array
    {
        $task = Task::findOrFail($this->taskId);
        return [
            new WithoutOverlapping('icloud:' . $task->icloud_account_id),
            new RateLimited('icloud-caldav'), // RateLimiter::for('icloud-caldav', fn($j) => Limit::perMinute(300))
        ];
    }

    public function handle(DeadlineIcsBuilder $ics): void
    {
        $task    = Task::with('icloudAccount')->findOrFail($this->taskId);
        $account = $task->icloudAccount;

        if ($account->status === 'invalid_credentials') {
            $this->delete();  // inutile de reessayer
            return;
        }

        $client = new ICloudCalDavClient($account->apple_id, $account->app_password);

        $uid = DeadlineIcsBuilder::uid($account->user_id, $task->document_id, $task->key);
        $obj = CalendarObject::firstOrNew([
            'icloud_account_id' => $account->id,
            'uid'               => $uid,
        ]);

        $payload = $ics->allDayDeadline(
            uid:         $uid,
            dueDate:     $task->due_at->toImmutable(),
            summary:     $task->title,
            description: $task->summary . "\n\n" . route('documents.show', $task->document_id),
            url:         route('documents.show', $task->document_id),
            sequence:    $obj->sequence,
        );

        try {
            if (!$obj->exists || $obj->etag === null) {
                $r = $client->putNew($account->calendar_url, $uid, $payload);
                $obj->fill(['task_id' => $task->id, 'href' => $r['href'], 'etag' => $r['etag'], 'state' => 'synced']);
            } else {
                $obj->sequence++;
                $payload = $ics->allDayDeadline(
                    uid: $uid, dueDate: $task->due_at->toImmutable(),
                    summary: $task->title, description: $task->summary,
                    url: route('documents.show', $task->document_id), sequence: $obj->sequence,
                );
                $newEtag = $client->putUpdate($obj->href, $payload, $obj->etag);
                if ($newEtag === null) {          // 412 : modifie sur l'iPhone
                    $obj->state = 'conflict';     // politique : l'appareil gagne
                    Log::info('Conflit CalDAV, appareil prioritaire', ['uid' => $uid]);
                } else {
                    $obj->etag  = $newEtag;
                    $obj->state = 'synced';
                }
            }
            $obj->last_pushed_at = now();
            $obj->save();

            $account->forceFill(['status' => 'ok', 'last_ok_at' => now()])->save();

        } catch (ICloudAuthException $e) {
            // 401 = etat TERMINAL (mot de passe Apple change -> tous les app passwords revoques)
            $account->forceFill(['status' => 'invalid_credentials'])->save();
            // notifier l'utilisateur pour re-saisir un app-specific password
            $this->fail($e);
        }
    }

    /** Ne JAMAIS laisser fuiter l'en-tete Authorization dans les logs/Sentry. */
    public function failed(\Throwable $e): void
    {
        Log::error('PushDeadlineToICloud', [
            'task_id' => $this->taskId,
            'error'   => preg_replace('/Basic\s+[A-Za-z0-9+\/=]+/', 'Basic [REDACTED]', $e->getMessage()),
        ]);
    }
}

### Extrait 13

<?php
// bootstrap/app.php ou AppServiceProvider — limiteur de debit iCloud
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('icloud-caldav', function (object $job) {
    // ~5 req/s par compte ; Apple ne publie aucun quota, on reste conservateur
    return Limit::perMinute(300)->by('icloud:' . ($job->accountId ?? 'global'));
});


## Incertitudes

- Le format exact 'xxxx-xxxx-xxxx-xxxx' (16 lettres minuscules + 3 tirets) du mot de passe d'application Apple est universellement observe mais N'EST PAS documente officiellement par Apple (la page HT102654 ne le mentionne pas). Ne pas valider strictement.
- Les rate limits precis d'iCloud CalDAV ne sont documentes nulle part par Apple. Le chiffre de ~10 req/s par compte provient d'une implementation tierce (mcp-icloud-calendar), pas d'Apple. A calibrer empiriquement.
- Le comportement exact de la redirection caldav.icloud.com -> pNN-caldav.icloud.com varie selon les sources : certaines decrivent un 301/302 HTTP, d'autres decrivent simplement un href absolu renvoye dans calendar-home-set sans redirection. Les deux cas doivent etre geres.
- Je n'ai pas pu verifier si iCloud renvoie systematiquement l'en-tete ETag sur un PUT reussi, ni le format exact de ses ETags. A tester sur un compte reel.
- Le support des VALARM sur les calendriers ABONNES (flux ICS via webcal://) par iOS est incertain : iOS propose une option 'Supprimer les alertes' a l'abonnement dont je n'ai pas pu verifier la valeur par defaut en 2026. A tester avant de proposer ce fallback comme porteur de rappels.
- La syntaxe exacte et le support par iCloud du REPORT sync-collection avec <d:limit><d:nresults> (pagination) n'ont pas ete verifies contre un serveur reel ; le comportement en cas de token invalide (403 + <d:valid-sync-token/>) est le comportement RFC 6578 standard, pas confirme specifiquement pour iCloud.
- Le comportement exact d'iCloud sur MKCALENDAR (proprietes acceptees, code retour si le displayname existe deja) n'est confirme que par une source tierce, pas par Apple.
- Je n'ai pas verifie si sabre/vobject 5.0.0 genere automatiquement les composants VTIMEZONE ; a priori non (il faut les injecter soi-meme ou passer par l'UTC).
- La possibilite qu'il reste, sur certains comptes de 2026, des listes de Rappels 'non upgradees' encore exposees en VTODO est rapportee mais non quantifiee. A traiter comme 'zero' par defaut.
- L'existence et le format exact d'un eventuel en-tete Retry-After renvoye par iCloud lors d'un throttling n'ont pas ete verifies.

## Sources

- https://support.apple.com/en-us/102654
- https://support.apple.com/en-us/103188
- https://www.aurinko.io/blog/caldav-apple-calendar-integration/
- https://cli.nylas.com/guides/icloud-caldav-settings
- https://cli.nylas.com/guides/caldav-explained
- https://www.apiroc.com/blog/how-to-integrate-icloud-calendar-api-into-your-app
- https://sabre.io/dav/building-a-caldav-client/
- https://github.com/sabre-io/vobject/releases
- https://packagist.org/packages/sabre/vobject
- https://github.com/sabre-io/dav/releases
- https://packagist.org/packages/smarcet/caldavclient
- https://github.com/smarcet/CalDAVClient
- https://github.com/KeepSoftwareSimple/compass-calendar/issues/3254
- https://github.com/JeroenAV/CalDav
- https://github.com/roygabriel/mcp-icloud-calendar
- https://www.2doapp.com/docs/faqs/data-deleted-after-ios-13-catalina-upgrade/
- https://github.com/home-assistant/core/issues/86221
- https://github.com/ivenos/ha_caldav/issues/2
- https://docs.guzzlephp.org/en/stable/request-options.html
- https://github.com/advisories/GHSA-w248-ffj2-4v5q
- https://www.strix.ai/cve/CVE-2022-31090
- https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Status/421
- https://laravel.com/docs/13.x/eloquent-mutators
- https://laravel.com/docs/13.x/releases
- https://correctics.com/help/ics-timezone-errors-tzid-vtimezone/
- https://heywoodlh.io/cross-platform-icloud/


---

# research:pwa-front

## Synthèse

## Contexte de vérification (17 sept. 2026)

Toutes les versions ci-dessous ont été relevées sur `registry.npmjs.org` ou les sites officiels **aujourd'hui**. iOS 27 / Safari 27 sont sortis le **14 septembre 2026** (donc très récents — l'iPhone 14 Plus est compatible).

---

## 1. Choix du framework front

**Recommandation : React 19.3 + TypeScript + Vite 8.3 (bundler Rolldown) + TanStack Router + TanStack Query.**

Versions vérifiées :
| Paquet | Version | Note |
|---|---|---|
| `react` / `react-dom` | **19.3.0** (09/09/2026) | View Transitions + Fragment Refs **stables** |
| `vite` | **8.3.0** | Rolldown+Oxc unifié, sorti 12/03/2026, Node `^20.19 \|\| >=22.12` |
| `@vitejs/plugin-react` | **6.1.1** | peer `vite ^8.0.0`, Refresh via Oxc (plus Babel), React Compiler optionnel |
| `svelte` | 5.57.0 | SvelteKit 3 encore en RC en sept. 2026 |
| `vue` | 3.6.0-rc.2 | Vapor Mode toujours RC → à éviter en prod |
| `laravel-vite-plugin` | **3.2.0** | peer `vite ^8.0.0` |
| `tailwindcss` | 4.3.3 | moteur Oxide |

**Pourquoi React ici plutôt que Svelte/Vue :**
1. **Le travail lourd n'est pas dans le framework.** Le scan (OpenCV.js/WASM, canvas, ImageData) tourne dans un **Web Worker**, hors du cycle de rendu. Le framework ne sert qu'à l'app shell, aux listes de documents et aux formulaires → le gain de perf de Svelte/Vapor est marginal.
2. **React 19.3 rend `<ViewTransition>` stable**, ce qui donne des transitions type-natif sur iOS (Safari supporte les view transitions same-document depuis 18.x) sans lib tierce.
3. Écosystème mature pour le reste du cahier des charges : `@tanstack/react-query` (polling/refetch/offline mutations), `@tanstack/react-router` (type-safe, file-based), `react-hook-form`+`zod`.
4. Svelte 5.57 est excellent techniquement mais **SvelteKit 3 est encore RC** au 17/09/2026 — risque inutile sur un projet multi-utilisateurs.
5. Vue 3.6 / Vapor : RC, Options API non supportée en Vapor → écarter.

**Vanilla + Vite** : viable uniquement si l'app est mono-écran. Ici il y a tri, listes, todo, détail document, réglages → un routeur + un cache serveur sont obligatoires, donc framework.

**Bun dans la chaîne :** `bun` **1.4.2**. Usage recommandé : Bun = gestionnaire de paquets + runner de scripts, Vite = build. Bun ne remplace **pas** Vite pour le front (pas de plugin PWA équivalent, pas d'HMR framework-aware).
```jsonc
// package.json
"scripts": {
  "dev":   "bunx --bun vite",
  "build": "bunx --bun vite build",
  "pwa:assets": "bunx pwa-assets-generator --preset minimal-2023 public/logo.svg"
}
```
Attention : `bunx --bun` force l'exécution du CLI Vite sous Bun. Si un plugin natif (sharp, lightningcss, rolldown) pose problème, retirer `--bun` et laisser Node exécuter Vite tout en gardant `bun install`.

---

## 2. Service Worker / Workbox

| Paquet | Version |
|---|---|
| `vite-plugin-pwa` | **1.3.0** |
| `workbox-build` / `workbox-window` / `workbox-*` | **7.4.1** |
| `@vite-pwa/assets-generator` | **2.0.0** |

`vite-plugin-pwa@1.3.0` accepte en peer `vite ^3 || ^4 || ^5 || ^6 || ^7 || ^8` → **compatible Vite 8**.

**Stratégie : `injectManifest`** (obligatoire ici, car il faut le handler `push` pour les notifications iOS et la file offline custom). En mode `injectManifest`, l'option `workbox.runtimeCaching` du plugin est **ignorée** : tout le runtime caching doit être écrit à la main dans le SW.

Découpage de cache recommandé :
- **App shell** → `precacheAndRoute(self.__WB_MANIFEST)` (JS/CSS/HTML/fonts hashés).
- **WASM OpenCV (~8 Mo)** → `CacheFirst` dédié, cache séparé, jamais dans le precache (sinon chaque déploiement re-télécharge 8 Mo sur réseau mobile).
- **Miniatures de documents** (`/api/documents/*/thumb`) → `CacheFirst` + `ExpirationPlugin` (`maxEntries: 300`, `maxAgeSeconds: 30j`, `purgeOnQuotaError: true`).
- **API JSON** (`/api/*` GET) → `NetworkFirst` avec `networkTimeoutSeconds: 4`.
- **API mutations** (POST/PATCH) → **jamais** via Workbox : passer par la file IndexedDB maison (cf. §3).
- **Navigations** → `NavigationRoute(createHandlerBoundToURL('index.html'))` avec `denylist` sur `/api/`.

**Pièges iOS spécifiques :**
- Le SW en mode standalone a un **scope de stockage séparé** de Safari : cookies, localStorage, IndexedDB et enregistrement SW ne sont pas partagés entre l'onglet Safari et l'app installée. Conséquence directe : un login fait dans Safari n'est pas repris dans la PWA.
- Le SW iOS est tué agressivement ; ne jamais garder d'état en mémoire dans le SW.
- Pas de `beforeinstallprompt` : il faut une UI d'onboarding maison « Partager → Sur l'écran d'accueil ».
- Depuis iOS 26, tout site ajouté à l'écran d'accueil s'ouvre **par défaut** en mode web app.
- Safari 27 ajoute la **Service Worker static routing API** (permet de bypasser le SW sur certaines routes → utile pour les uploads).

---

## 3. File d'attente offline (Background Sync indisponible)

**Fait confirmé 2026 : Background Sync et Periodic Background Sync ne sont toujours pas implémentés dans WebKit/iOS.** Workbox `BackgroundSyncPlugin` dégrade en « rejeu au démarrage du SW », ce qui est insuffisant. → **file persistante maison obligatoire**.

Libs : `dexie` **4.4.6** (recommandé, API plus lisible, hooks de migration) ou `idb` **8.0.3** (plus léger, ~1 Ko).

**Design concret :**
- Table `outbox` : `{ id, type, status, createdAt, attempts, nextAttemptAt, payloadMeta }`.
- Table `blobs` séparée : `{ id, docId, page, blob, bytes, sha256 }` — **ne jamais mettre le Blob dans le même objet que les métadonnées** (sinon chaque lecture de la liste désérialise des dizaines de Mo).
- Stocker les **Blob** directement (IndexedDB les supporte nativement sur WebKit et les garde hors du heap JS). **Ne jamais** stocker de base64 : +33 % de taille et coût CPU énorme.
- Table `upload_sessions` : `{ blobId, uploadUrl, offset, chunkSize, totalSize }` pour la reprise.

**Déclencheurs de rejeu** (il n'y en a pas un seul fiable sur iOS, il faut les cumuler) :
1. `window.addEventListener('online')`
2. `document.addEventListener('visibilitychange')` quand `visibilityState === 'visible'` ← **le plus important sur iOS**, car l'app est gelée en arrière-plan et « revient » par là.
3. `pageshow` (y compris `event.persisted` pour le bfcache).
4. Au démarrage de l'app.
5. Un `setInterval` de garde (30 s) tant que la file n'est pas vide.

**Quota / éviction :**
- Safari alloue ~**15 % du disque par origine** pour les apps non-navigateur et jusqu'à **20 % au total** (le mode app à l'écran d'accueil a le même quota qu'en navigateur). Sur un iPhone 14 Plus 128 Go cela reste très large (≈19 Go) — la limite pratique est l'éviction, pas le quota.
- **Appeler `navigator.storage.persist()`** : WebKit l'accorde par heuristique, et le fait d'être une Home Screen Web App est explicitement l'une de ces heuristiques. C'est le seul rempart contre l'éviction LRU.
- Surveiller `navigator.storage.estimate()` et refuser de mettre en file au-delà de ~60 % du quota → message utilisateur « videz la file d'envoi ».
- Gérer `QuotaExceededError` explicitement (nom d'erreur `QuotaExceededError`) : purger d'abord les caches de miniatures, puis alerter.
- L'éviction ITP « 7 jours sans interaction » compte **séparément** pour une app à l'écran d'accueil (compteur de jours d'usage propre), mais elle existe : documenter que laisser l'app 7+ jours sans l'ouvrir peut détruire la file si `persist()` a échoué.

---

## 4. Upload de gros fichiers

**Ce qui NE marche pas de façon fiable sur iOS :** `fetch()` avec un `ReadableStream` en body + `duplex: 'half'`. WebKit ne l'a ajouté qu'en **Safari Technology Preview 250**, marqué « initial support ». → **à ne pas utiliser en prod en 2026.**

**Ce qui marche :**
1. **`XMLHttpRequest` + `xhr.upload.onprogress`** : seul moyen réellement portable d'avoir une barre de progression d'upload (fetch ne donne pas la progression d'upload). Un `Blob.slice()` par chunk.
2. **Chunked upload maison** : `Blob.slice(offset, offset + chunkSize)`, endpoint `PUT /api/uploads/{session}/chunk` avec `Content-Range`, réponse renvoyant l'offset serveur. Reprise = `HEAD` pour récupérer l'offset. Chunk de **2–4 Mo** (au-delà, une coupure réseau 4G coûte cher ; en dessous, trop d'aller-retours TLS).
3. **tus** : `tus-js-client` **4.3.1** — protocole standard, reprise native, `chunkSize`, `retryDelays`, `onProgress`. Côté Laravel, il n'existe pas de serveur tus first-party maintenu : soit un paquet communautaire, soit implémenter les 4 verbes du protocole core (HEAD/PATCH/POST/OPTIONS). **Recommandation : chunked maison si le backend est Laravel** (moins de dépendances, protocole trivial), tus si vous prévoyez d'autres clients.
4. Sur iOS, **l'app est gelée dès qu'on quitte** → un upload de 20 Mo en 3G sera interrompu. La reprise par offset n'est donc pas un luxe, c'est le cas nominal.
5. Toujours compresser **avant** l'upload : le scan sort un JPEG re-binarisé de 300–800 Ko, pas le HEIC 4 Mo original.

---

## 5. Manifest + icônes + splash iPhone 14 Plus

**Chiffres exacts iPhone 14 Plus** (vérifiés) : viewport CSS **428 × 926**, DPR **3**, résolution physique **1284 × 2778**.

Splash portrait = `1284×2778`, splash paysage = `2778×1284`.

Icônes générées par `@vite-pwa/assets-generator@2.0.0` preset `minimal-2023` : `pwa-64x64.png`, `pwa-192x192.png`, `pwa-512x512.png` (`purpose: any`), `maskable-icon-512x512.png` (`purpose: maskable`), `apple-touch-icon-180x180.png`, `favicon.ico` (48×48), `favicon.svg`.

Champs manifest à ne pas oublier : `id` (fige l'identité de l'app même si `start_url` change), `scope`, `start_url` avec `?source=pwa`, `display: "standalone"`, `display_override: ["standalone"]`, `orientation: "portrait"`, `theme_color`, `background_color`, `shortcuts` (iOS 26+ les expose via appui long sur l'icône), `categories`, `lang`, `dir`.

⚠️ **iOS ignore `shortcuts` historiquement** — les tester, ne pas en dépendre pour un parcours critique.

---

## 6. UI/UX mobile iOS

- `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">` — **sans `viewport-fit=cover`, tous les `env(safe-area-inset-*)` valent `0px`.**
- Ne PAS mettre `user-scalable=no` / `maximum-scale=1` : c'est un anti-pattern d'accessibilité et Safari l'ignore de toute façon depuis longtemps. Pour tuer le zoom double-tap, utiliser **`touch-action: manipulation`** sur les éléments interactifs.
- `100dvh` : disponible depuis iOS 15.4, mais **bug de cold-start connu** (valeur fausse au tout premier paint en standalone). Pattern sûr : `height: 100vh; height: 100dvh;` + un fallback JS `--vh` recalculé sur `resize`/`orientationchange`.
- Régression iOS 26.1 : `safe-area-inset-top` renvoyait 20pt en paysage — corrigé en 26.2. Tester sur iOS 27.
- `-webkit-touch-callout: none` sur les zones de scan (évite le menu « Copier l'image » à l'appui long), `user-select: none` sur la chrome de l'app, mais **jamais** sur le texte extrait du document (l'utilisateur doit pouvoir le copier).
- `overscroll-behavior-y: contain` sur les listes pour couper le pull-to-refresh natif et le rubber-band du body.
- `-webkit-overflow-scrolling: touch` est **obsolète** : le momentum scroll est le défaut depuis iOS 13.
- **Geste de retour (swipe depuis le bord)** : la **Navigation API** est Baseline Newly Available depuis janvier 2026 et supportée dans **Safari 26.2+**. `navigation.addEventListener('navigate', …)` intercepte aussi le swipe-back. ⚠️ Safari n'implémente pas encore `precommitHandler`. `NavigateEvent.hasUAVisualTransition` permet de **ne pas rejouer** d'animation quand iOS a déjà animé le geste.
- **Haptics : impossible proprement.** WebKit n'expose aucune API de vibration ; la Vibration API n'est pas supportée. Le hack historique (toggle d'un `<input type="checkbox" switch>` caché, iOS 17.4+) **a été corrigé/bloqué par Apple en iOS 26.5** → considérer les haptics comme indisponibles et compenser par du retour visuel/sonore.
- **View Transitions** : same-document supporté sur Safari (Baseline) ; cross-document depuis Safari 18.2. En SPA React, utiliser `<ViewTransition>` de React 19.3 + `addTransitionType` pour les transitions directionnelles (liste → détail document). Toujours envelopper dans `startTransition`.

---

## 7. Accessibilité & mode sombre

- `color-scheme: light dark` sur `:root` → contrôles natifs (scrollbars, inputs, date pickers) corrects en sombre.
- Deux `<meta name="theme-color">` avec `media="(prefers-color-scheme: light|dark)"` : iOS colore la barre d'état de la PWA standalone avec.
- `background_color` du manifest = la couleur du splash iOS ; la choisir cohérente avec le thème clair (iOS n'a qu'un seul splash).
- `@media (prefers-reduced-motion: reduce)` → désactiver les View Transitions (`view-transition-name: none`) et les animations de capture.
- `@media (prefers-contrast: more)`.
- `-webkit-text-size-adjust: 100%` mais respecter le Dynamic Type : tailles en `rem`, jamais en `px` fixes pour le texte des documents.
- Cibles tactiles ≥ 44×44 pt (HIG Apple), particulièrement pour les poignées de recadrage des 4 coins du document.
- Le viewfinder de scan doit annoncer son état via `aria-live="polite"` (« document détecté », « rapprochez-vous »).
- Prévoir un chemin **sans caméra** : import depuis Fichiers/Photos, indispensable pour les utilisateurs VoiceOver.

---

## 8. Auth

**Décision : cookie `httpOnly` + `Secure` + `SameSite=Lax` via Laravel Sanctum en mode SPA, si et seulement si le front et l'API sont sur le même site** (ex. `app.exemple.fr` + `api.exemple.fr` avec `SESSION_DOMAIN=.exemple.fr`). Sinon, token porteur.

- **`localStorage` : à proscrire** (lisible par n'importe quel XSS, et synchrone donc bloquant).
- Si token porteur obligatoire : le stocker en **IndexedDB** (asynchrone, pas dans le DOM, pas exfiltrable par un simple `localStorage.getItem` dans un snippet injecté) — mais ce n'est **pas** une protection contre le XSS, seulement une friction. La vraie défense reste CSP stricte + Trusted Types (React 19.3 supporte nativement Trusted Types).
- **Refresh** : intercepteur unique avec mutex (une seule requête de refresh concurrente, les autres attendent la promesse). Rotation du refresh token + détection de réutilisation côté Laravel.
- **Déconnexion** : révoquer côté serveur, vider IndexedDB, `caches.delete()` des caches API, `registration.unregister()` optionnel, puis `pushSubscription.unsubscribe()` — sinon les push continuent d'arriver.
- ⚠️ **Piège majeur iOS** : en standalone, le stockage est **isolé de Safari**. Un flux OAuth qui sort de la PWA (redirection vers un IdP) revient dans Safari, pas dans l'app → boucle de login infinie. **Solution : login par formulaire natif dans l'app** (email/mot de passe ou magic link avec deep link vers `start_url`), pas d'OAuth redirect.

**Biométrie / Face ID : oui, faisable via WebAuthn/passkeys.**
- Passkeys supportées depuis iOS 16 ; Face ID/Touch ID déclenche la cérémonie. Fonctionne en PWA installée.
- Pour **déverrouiller l'app** : créer une passkey liée au compte, puis à chaque ouverture appeler `navigator.credentials.get()` avec `userVerification: 'required'` → Face ID. C'est le pattern « app lock » standard.
- **Extension PRF supportée depuis iOS 18 / Safari 18** avec les passkeys iCloud Keychain → permet de **dériver une clé de chiffrement** depuis Face ID pour chiffrer localement les documents en IndexedDB (E2EE côté client). ⚠️ Non supporté pour les authentificateurs roaming externes sur iOS.
- `largeBlob` : depuis iOS 17 / Safari 17, mais support cross-plateforme faible.
- Toujours prévoir un repli code PIN : une passkey peut être supprimée d'iCloud Keychain.

---

## 9. Temps réel (« le document est analysé »)

Trois canaux, à combiner :

| Situation | Mécanisme |
|---|---|
| App au premier plan | **WebSocket via Laravel Reverb + `laravel-echo@2.5.0`** sur un canal privé `private-user.{id}` |
| App en arrière-plan / fermée | **Web Push** (seul canal qui traverse le gel iOS) |
| Repli / réseau capricieux | **Polling TanStack Query** (`refetchInterval` adaptatif, `refetchOnWindowFocus: true`) |

**Faits iOS à connaître :**
- Dès que la PWA passe en arrière-plan, **le JS est gelé** : WebSocket et SSE sont coupés (bug WebKit historique #211018, et Safari coupe les WS inactifs). Il n'existe **aucun** moyen de maintenir une connexion en arrière-plan.
- **Web Push fonctionne** sur iOS depuis 16.4, **exclusivement pour les web apps ajoutées à l'écran d'accueil** (jamais dans un onglet Safari). Nécessite VAPID avec un `VAPID_SUBJECT` (`mailto:` ou URL) — Apple renvoie `BadJwtToken` sans.
- **Declarative Web Push** (Safari 18.4+, mars 2025) : le serveur envoie un JSON avec `web_push: 8030` + un objet `notification`, et le navigateur affiche la notification **sans exécuter le handler `push` du SW**. Beaucoup plus fiable sur iOS (pas de risque que le SW ne démarre pas). Le SW peut optionnellement modifier la notification.
- ⚠️ La permission de notification doit être demandée **sur un geste utilisateur** et **uniquement en mode standalone** (`window.matchMedia('(display-mode: standalone)').matches`).
- Au retour au premier plan : `visibilitychange` → reconnecter Echo + `queryClient.invalidateQueries()`. Ne pas se fier à la reconnexion auto de Pusher/Echo seule.

**Recommandation pragmatique** : si vous n'avez pas besoin de temps réel bidirectionnel, **Reverb est peut-être surdimensionné**. Un `NetworkFirst` + polling à 3 s pendant l'analyse (typiquement 5–20 s) + Web Push pour le reste couvre 100 % du besoin sans serveur WS à opérer. Reverb devient intéressant si vous ajoutez du collaboratif.

---

## 10. Pipeline de capture/scan — point d'architecture critique

**N'utilisez PAS `getUserMedia` comme chemin principal de capture sur iOS.** Constats 2026 :
- Safari ne propose généralement qu'une piste **720p** même si on demande plus dans les contraintes → insuffisant pour de l'OCR sur un document A4.
- L'**ImageCapture API (`takePhoto`, `grabFrame`) n'est pas supportée** par Safari (macOS ni iOS) → pas d'accès au capteur pleine résolution.
- La permission caméra **n'est pas persistée** en mode PWA sur iOS → l'utilisateur est re-sollicité à chaque lancement.

**Architecture recommandée :**
- **Capture** : `<input type="file" accept="image/jpeg,image/png" capture="environment" multiple>` → ouvre l'appareil photo natif, rend une image **pleine résolution (12 MP)**, sans problème de permission persistante.
  - ⚠️ **Piège HEIC** : si `accept` autorise `image/heic`, Safari renvoie le HEIC brut (illisible par canvas). Si `accept` **n'inclut pas** HEIC, Safari **convertit automatiquement** vers le premier format convertible listé → mettre `accept="image/jpeg"` force un JPEG exploitable.
- **Aperçu temps réel** (cadre de détection live, optionnel) : `getUserMedia` en 720p uniquement pour le guidage visuel, puis déclencher la vraie capture via l'input file.
- **Traitement** : `jscanify@1.4.3` (wrapper OpenCV.js : détection de contours → `cv.warpPerspective` → binarisation) exécuté dans un **Web Worker** piloté par `comlink@4.4.2`, avec `OffscreenCanvas` + transfert d'`ImageBitmap` (zéro copie).
  - Le WASM OpenCV.js fait **~8 Mo** : charger en `async`, gater l'init sur `onRuntimeInitialized`, et le mettre dans un cache SW `CacheFirst` dédié.
- **Multipage** : accumuler les pages traitées dans IndexedDB, assembler le PDF côté serveur (Laravel) plutôt que dans le navigateur — plus fiable et moins gourmand en mémoire sur iPhone.

---

## 11. Bibliothèques — versions vérifiées au 17/09/2026

| Paquet | Version | Remarque |
|---|---|---|
| `zod` | **4.6.5** | Zod 4 : `z.output`/`z.input`, perfs ×N vs v3 ; partager les schémas avec les réponses Laravel |
| `@tanstack/react-query` | **5.103.1** | peer `react ^18 \|\| ^19` |
| `@tanstack/react-router` | **1.170.38** | Node >= 20.19 |
| `comlink` | **4.4.2** | RPC vers le Worker OpenCV |
| `dexie` | **4.4.6** | file offline |
| `idb` | **8.0.3** | alternative légère |
| `tus-js-client` | **4.3.1** | uploads reprenables |
| `laravel-echo` | **2.5.0** | Node `^20.19 \|\| >=22.12` |
| `workbox-*` | **7.4.1** | |
| `vite-plugin-pwa` | **1.3.0** | |
| `@vite-pwa/assets-generator` | **2.0.0** | |
| `pdf-lib` | **1.17.1** | ⚠️ **dernière publication : 7 octobre 2021** — projet non maintenu depuis ~5 ans. Voir §Pièges. |

---

## 12. Backend (contexte, vérifié)

- **Laravel 13** sorti le **17 mars 2026**, PHP **8.3 minimum**, supporte 8.3/8.4/8.5. Annoncé sans breaking change depuis Laravel 12. Support bugfix jusqu'au Q3 2027, sécurité jusqu'au Q1 2028.
- **Laravel Reverb** : serveur WebSocket first-party, protocole Pusher, compatible Echo, scaling horizontal via Redis pub/sub. ~1000 connexions concurrentes par cœur CPU.
- `php artisan install:broadcasting --reverb` pour le setup.


## Faits clés vérifiés

- React 19.3.0 est sorti le 9 septembre 2026 ; <ViewTransition> et Fragment Refs y sont STABLES (plus expérimentaux). Nouvelle API react-dom `browser()` utilisable avec `use()` pour opt-out du SSR.
- Vite 8.3.0 est la version courante (Vite 8.0 sorti le 12 mars 2026). Rolldown (Rust) remplace esbuild+Rollup pour dev ET prod. Node requis : ^20.19.0 || >=22.12.0. Taille d'install ~15 Mo de plus que Vite 7.
- @vitejs/plugin-react 6.1.1 — peer `vite ^8.0.0`, React Refresh via Oxc et non plus Babel ; `babel-plugin-react-compiler ^1.0.0` en peer optionnel.
- laravel-vite-plugin 3.2.0 — peer `vite ^8.0.0`.
- vite-plugin-pwa 1.3.0 — dépend de workbox-build/workbox-window ^7.4.1 ; peer vite `^3 || ^4 || ^5 || ^6 || ^7 || ^8` (donc Vite 8 OK).
- Workbox courant : 7.4.1.
- @vite-pwa/assets-generator : 2.0.0 (preset minimal-2023 -> pwa-64x64, pwa-192x192, pwa-512x512, maskable-icon-512x512, apple-touch-icon-180x180, favicon.ico 48x48, favicon.svg).
- Bun 1.4.2 est la version courante (sept. 2026). `bunx --bun vite` exécute le CLI Vite sous Bun.
- Svelte 5.57.0 courant, mais SvelteKit 3 est encore en Release Candidate en septembre 2026. Vue 3.6 (Vapor Mode) est encore en 3.6.0-rc.2 — Vapor ne supporte que la Composition API.
- Tailwind CSS 4.3.3 (moteur Oxide en Rust, config CSS-first via @theme).
- iOS 27 et Safari 27 sont sortis le 14 septembre 2026. Safari 27 apporte : Service Worker static routing API, WebAssembly JSPI, customizable <select>, scroll anchoring, ReadableStream async iteration + transférable entre contextes, workers dédiés dans les shared workers, Cookie Store API maxAge.
- Depuis iOS 26, tout site ajouté à l'écran d'accueil s'ouvre par DÉFAUT comme web app (mode standalone).
- iPhone 14 Plus : viewport CSS 428 x 926, device-pixel-ratio 3, résolution physique 1284 x 2778. Splash portrait = 1284x2778, paysage = 2778x1284.
- Web Push fonctionne sur iOS depuis 16.4 mais UNIQUEMENT pour les web apps ajoutées à l'écran d'accueil (jamais dans un onglet Safari). VAPID obligatoire, avec VAPID_SUBJECT (mailto: ou URL) sinon Apple renvoie BadJwtToken.
- Declarative Web Push : Safari 18.4 (mars 2025). Payload JSON avec `web_push: 8030` + objet `notification` ; la notification s'affiche SANS exécuter le handler push du service worker. Implémenté pour les web apps Home Screen iOS/iPadOS et macOS.
- Storage Safari : quota par origine jusqu'à 15 % du disque pour les apps non-navigateur (60 % pour les navigateurs), quota global 20 % (80 % navigateurs). Une Home Screen Web App a le MÊME quota qu'en navigateur. Éviction LRU par origine ; `StorageManager.persist()` est accordé par heuristique, et « être une Home Screen Web App » est explicitement une de ces heuristiques.
- Éviction ITP 7 jours sans interaction : les web apps ajoutées à l'écran d'accueil ne font pas partie de Safari et ont leur PROPRE compteur de jours d'usage.
- OPFS est disponible dans Safari (iOS 15.2+) mais SANS les méthodes de picker (pas d'accès au disque utilisateur). Depuis macOS 14 / iOS 17, ~20 % de l'espace disque par origine. Limite de 10 Mo par fichier dans un WKWebView embarqué (pas dans Safari/PWA).
- Navigation API : Baseline Newly Available en janvier 2026, supportée dans Safari 26.2+, Chrome, Edge, Firefox 147. Safari n'implémente PAS encore `precommitHandler`. `NavigateEvent.hasUAVisualTransition` permet de détecter que l'UA a déjà animé le geste de retour.
- View Transitions same-document : Baseline / supporté Safari. Cross-document : Chrome 126+, Safari 18.2+.
- WebAuthn/passkeys : supportées iOS 16+. Extension PRF supportée depuis iOS 18 / Safari 18 avec les passkeys iCloud Keychain (dérivation de clé via Face ID -> chiffrement E2E côté client). Extension largeBlob depuis iOS 17 / Safari 17.
- Safari ne supporte PAS l'ImageCapture API (ni takePhoto ni grabFrame) sur macOS ni iOS. Fallback obligatoire : <video> caché + canvas.drawImage.
- getUserMedia sur iOS ne délivre généralement qu'une piste 720p même avec des contraintes de résolution supérieures ; la permission caméra n'est pas persistée en mode PWA standalone.
- Safari 17+ convertit automatiquement les images à l'upload : si `accept` n'autorise pas image/heic, le HEIC est converti vers le premier format convertible listé dans `accept`. Donner `accept="image/jpeg"` force un JPEG exploitable par canvas.
- Upload par ReadableStream + `duplex: 'half'` : seulement « initial support » dans Safari Technology Preview 250 — PAS utilisable en production en 2026. Utiliser XHR + xhr.upload.onprogress.
- tus-js-client 4.3.1 ; @uppy/tus 5.1.1.
- Versions libs : zod 4.6.5, @tanstack/react-query 5.103.1, @tanstack/react-router 1.170.38, comlink 4.4.2, dexie 4.4.6, idb 8.0.3, laravel-echo 2.5.0, jscanify 1.4.3.
- Laravel 13 est sorti le 17 mars 2026 ; PHP 8.3 minimum, supporte PHP 8.3/8.4/8.5 ; annoncé sans breaking change depuis Laravel 12. Bugfix jusqu'au Q3 2027, sécurité jusqu'au Q1 2028.
- Laravel Reverb : serveur WS first-party, protocole Pusher, compatible Laravel Echo, scaling horizontal via Redis pub/sub, ~1000 connexions concurrentes par cœur CPU. Setup : `php artisan install:broadcasting --reverb`.
- jscanify utilise OpenCV.js : le binaire WASM pèse ~8 Mo et bloque le chargement de page — le charger en `async` et gater l'init sur `onRuntimeInitialized`.
- React 19.3 supporte nativement la Trusted Types API (objets TrustedHTML/TrustedScript/TrustedScriptURL passés directement aux API DOM) — utile pour durcir la CSP d'une app qui manipule du contenu extrait de documents.

## Pièges / ce qui ne marche PAS

- Background Sync ET Periodic Background Sync ne sont TOUJOURS pas implémentés dans WebKit/iOS en 2026. Le BackgroundSyncPlugin de Workbox dégrade en « rejeu au démarrage du SW », ce qui exige que la page contrôlante tourne -> inefficace. Il FAUT une file IndexedDB maison rejouée sur les événements `online`, `visibilitychange` et au démarrage.
- Isolation de stockage standalone vs Safari : cookies, localStorage, IndexedDB, sessions ET instance de service worker ne sont PAS partagés entre l'onglet Safari et la PWA installée. Conséquence : tout flux OAuth qui sort de la PWA revient dans Safari et la session n'est jamais vue par l'app -> boucle de login. Ne pas faire d'OAuth par redirection ; utiliser un login natif in-app (formulaire ou magic link deep-linkant vers start_url).
- La PWA est GELÉE dès qu'elle passe en arrière-plan sur iOS : WebSocket et SSE sont coupés, aucun JS ne tourne. Aucun moyen de maintenir une connexion. Seul Web Push traverse ce gel. Bug WebKit historique #211018 : les PWA utilisant un service worker peuvent même rester figées au retour au premier plan.
- Safari ne supporte pas l'ImageCapture API -> impossible d'obtenir la pleine résolution du capteur via getUserMedia. Et getUserMedia plafonne en pratique à 720p sur iOS. Utiliser <input type="file" capture="environment"> pour la capture réelle.
- La permission caméra n'est pas persistée pour les PWA iOS : l'utilisateur est re-sollicité à chaque lancement si on passe par getUserMedia. Raison de plus de préférer l'input file.
- Piège HEIC : si `accept` contient image/heic, Safari renvoie un HEIC brut que canvas ne sait pas décoder de façon fiable. Inversement Safari 17+ peut convertir un PNG en HEIC dans certains cas. Toujours forcer `accept="image/jpeg"`.
- Upload par fetch + ReadableStream + duplex:'half' : support seulement « initial » dans Safari Technology Preview 250 -> ne pas l'utiliser en prod. De plus, même là où ça marche, fetch ne donne pas de progression d'upload : utiliser XHR (`xhr.upload.onprogress`).
- pdf-lib 1.17.1 : dernière publication le 7 octobre 2021, projet non maintenu depuis ~5 ans. Pour un projet neuf en 2026, ne PAS en faire une dépendance critique — assembler les PDF côté Laravel (PHP) plutôt que dans le navigateur. Si le PDF côté client est indispensable, évaluer un fork maintenu.
- Sans `viewport-fit=cover` dans le meta viewport, TOUS les env(safe-area-inset-*) valent 0px et Safari letterboxe en paysage.
- 100dvh a un bug de cold-start connu en standalone (valeur fausse au tout premier paint). Toujours doubler d'un fallback (`height:100vh; height:100dvh;` + variable --vh recalculée au resize).
- Régression iOS 26.1 : safe-area-inset-top renvoyait 20pt en paysage (corrigé en 26.2). Retester sur iOS 27.
- Haptics : impossible. WebKit n'expose aucune API de vibration. Le hack du <input type="checkbox" switch> caché (iOS 17.4+) a été bloqué par Apple en iOS 26.5. Considérer les haptics comme indisponibles.
- Pas de beforeinstallprompt sur iOS : il faut une UI d'onboarding manuelle expliquant Partager -> Sur l'écran d'accueil. Sans installation, PAS de Web Push du tout.
- Les `shortcuts` du manifest sont historiquement ignorés par iOS. Ne jamais en dépendre pour un parcours critique.
- En mode `injectManifest`, l'option `workbox.runtimeCaching` de vite-plugin-pwa est IGNORÉE : tout le runtime caching doit être écrit à la main dans le service worker avec workbox-routing/workbox-strategies.
- Le WASM OpenCV.js pèse ~8 Mo et bloque le chargement s'il est chargé en synchrone. Ne jamais le mettre dans le precache (re-téléchargé à chaque déploiement) : cache runtime CacheFirst dédié + chargement async + gate sur onRuntimeInitialized.
- Ne jamais stocker les images en base64 dans IndexedDB : +33 % de taille et coût CPU/mémoire majeur sur iPhone. Stocker des Blob, et dans un object store SÉPARÉ des métadonnées (sinon chaque lecture de liste désérialise des dizaines de Mo).
- localStorage pour les tokens : à proscrire (lisible par tout XSS, API synchrone bloquante). IndexedDB n'est pas non plus une protection contre le XSS, seulement une friction — la vraie défense est une CSP stricte (+ Trusted Types, supporté par React 19.3).
- L'extension WebAuthn PRF n'est pas transmise aux authentificateurs roaming externes sur iOS/iPadOS — elle ne fonctionne qu'avec les passkeys iCloud Keychain. Et une passkey peut disparaître (suppression iCloud Keychain) : toujours prévoir un repli code PIN.
- Éviction du stockage : si navigator.storage.persist() n'est pas accordé, laisser l'app 7+ jours sans l'ouvrir peut détruire la file offline ET les caches. L'appeler explicitement et vérifier le retour.
- La Navigation API de Safari n'implémente pas encore `precommitHandler` -> ne pas baser la logique de navigation dessus, prévoir un chemin de repli.
- Dans l'UE, Apple avait retiré le support PWA standalone en iOS 17.4 sous le DMA (PWA ouvertes en onglet Safari, sans push). Vérifier l'état actuel pour une cible européenne — c'est un risque réglementaire direct sur le cœur du produit.
- @vite-pwa/assets-generator est en 2.0.0 alors que vite-plugin-pwa 1.3.0 déclare le peer optionnel `@vite-pwa/assets-generator ^1.0.0` -> conflit de peer possible. L'utiliser en CLI standalone (bunx pwa-assets-generator) plutôt qu'en intégration plugin, ou forcer la résolution.

## Extraits de code de référence

### Extrait 1

// vite.config.ts — Vite 8.3 + React 19.3 + PWA injectManifest + Laravel
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'          // 6.1.1
import laravel from 'laravel-vite-plugin'        // 3.2.0
import { VitePWA } from 'vite-plugin-pwa'        // 1.3.0

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/main.tsx'], refresh: true }),
    react({ babel: { plugins: [['babel-plugin-react-compiler', {}]] } }),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'resources/js/sw',
      filename: 'sw.ts',
      registerType: 'prompt',            // on veut un prompt de rechargement, pas autoUpdate
      injectRegister: false,             // on enregistre nous-mêmes
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,woff2,svg}'],
        // NE PAS precacher le wasm OpenCV (~8 Mo) :
        globIgnores: ['**/opencv*.{js,wasm}'],
        maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
      },
      devOptions: { enabled: true, type: 'module', navigateFallback: 'index.html' },
      manifest: false,                   // manifest servi statiquement (cf. snippet dédié)
    }),
  ],
  build: { target: 'safari16' },
})

### Extrait 2

// resources/js/sw/sw.ts — service worker custom (Workbox 7.4.1)
/// <reference lib="webworker" />
import { precacheAndRoute, cleanupOutdatedCaches, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { NetworkFirst, CacheFirst, StaleWhileRevalidate } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'
import { clientsClaim } from 'workbox-core'

declare const self: ServiceWorkerGlobalScope

cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)
clientsClaim()

// 1) App shell (SPA) — exclure /api/
registerRoute(new NavigationRoute(createHandlerBoundToURL('/index.html'), {
  denylist: [/^\/api\//, /^\/storage\//, /^\/broadcasting\//],
}))

// 2) WASM OpenCV : cache dédié, immuable, jamais precaché
registerRoute(
  ({ url }) => /\/opencv.*\.(js|wasm)$/.test(url.pathname),
  new CacheFirst({
    cacheName: 'opencv-wasm-v1',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 4, maxAgeSeconds: 365 * 24 * 3600 }),
    ],
  })
)

// 3) Miniatures de documents
registerRoute(
  ({ url, request }) => request.destination === 'image' && url.pathname.startsWith('/api/documents/'),
  new CacheFirst({
    cacheName: 'doc-thumbs-v1',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 300, maxAgeSeconds: 30 * 24 * 3600, purgeOnQuotaError: true }),
    ],
  })
)

// 4) API JSON en lecture
registerRoute(
  ({ url, request }) => url.pathname.startsWith('/api/') && request.method === 'GET',
  new NetworkFirst({
    cacheName: 'api-v1',
    networkTimeoutSeconds: 4,
    plugins: [new ExpirationPlugin({ maxEntries: 200, maxAgeSeconds: 7 * 24 * 3600 })],
  })
)

// 5) Prompt de mise à jour piloté par le client
self.addEventListener('message', (e) => {
  if (e.data?.type === 'SKIP_WAITING') self.skipWaiting()
})

// 6) Web Push (fallback non-déclaratif : iOS < 18.4 ou payload classique)
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {}
  event.waitUntil(self.registration.showNotification(data.title ?? 'Document analysé', {
    body: data.body,
    icon: '/icons/pwa-192x192.png',
    badge: '/icons/badge-96x96.png',
    data: { url: data.url ?? '/' },
    tag: data.tag,
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.url ?? '/'
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const existing = all.find(c => c.url.includes(self.registration.scope))
    if (existing) { await existing.focus(); existing.postMessage({ type: 'NAVIGATE', url }); return }
    await self.clients.openWindow(url)
  })())
})

### Extrait 3

// resources/js/pwa/register.ts — enregistrement + prompt de rechargement
import { registerSW } from 'virtual:pwa-register'

const intervalMS = 60 * 60 * 1000

export function setupPWA(onNeedRefresh: (reload: () => void) => void) {
  const updateSW = registerSW({
    immediate: true,
    onNeedRefresh() {
      // afficher un toast « Nouvelle version disponible »
      onNeedRefresh(() => updateSW(true))
    },
    onRegisteredSW(swUrl, r) {
      if (!r) return
      // check périodique robuste (réseau coupé / serveur down)
      setInterval(async () => {
        if (r.installing || !navigator) return
        if ('connection' in navigator && !navigator.onLine) return
        const resp = await fetch(swUrl, {
          cache: 'no-store',
          headers: { cache: 'no-store', 'cache-control': 'no-cache' },
        })
        if (resp?.status === 200) await r.update()
      }, intervalMS)

      // iOS : l'app est gelée en arrière-plan -> vérifier au retour
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') r.update().catch(() => {})
      })
    },
  })
}

### Extrait 4

// resources/js/offline/outbox.ts — file persistante (Dexie 4.4.6)
import Dexie, { type EntityTable } from 'dexie'

interface OutboxItem {
  id: string
  kind: 'document.upload' | 'todo.create' | 'todo.patch'
  status: 'pending' | 'inflight' | 'failed' | 'done'
  attempts: number
  nextAttemptAt: number
  createdAt: number
  meta: Record<string, unknown>   // PETIT : pas de blob ici
  lastError?: string
}
interface BlobRow { id: string; itemId: string; page: number; bytes: number; blob: Blob }
interface UploadSession { itemId: string; uploadUrl: string; offset: number; totalSize: number }

const db = new Dexie('papers') as Dexie & {
  outbox: EntityTable<OutboxItem, 'id'>
  blobs: EntityTable<BlobRow, 'id'>
  sessions: EntityTable<UploadSession, 'itemId'>
}
db.version(1).stores({
  outbox: 'id, status, nextAttemptAt, createdAt',
  blobs: 'id, itemId, [itemId+page]',
  sessions: 'itemId',
})

// --- Persistance : indispensable contre l'éviction LRU/ITP d'iOS
export async function ensurePersisted() {
  if (!navigator.storage?.persist) return false
  if (await navigator.storage.persisted()) return true
  return navigator.storage.persist()   // WebKit l'accorde par heuristique (Home Screen Web App)
}

export async function guardQuota(incomingBytes: number) {
  const { usage = 0, quota = 0 } = (await navigator.storage?.estimate?.()) ?? {}
  if (quota && usage + incomingBytes > quota * 0.6) {
    await caches.delete('doc-thumbs-v1')   // purge la 1re ligne de défense
    const again = await navigator.storage.estimate()
    if ((again.usage ?? 0) + incomingBytes > (again.quota ?? 0) * 0.8) {
      throw new DOMException('Espace insuffisant', 'QuotaExceededError')
    }
  }
}

let running = false
export async function drain() {
  if (running || !navigator.onLine) return
  running = true
  try {
    const now = Date.now()
    const batch = await db.outbox
      .where('status').anyOf('pending', 'failed')
      .filter(i => i.nextAttemptAt <= now)
      .sortBy('createdAt')

    for (const item of batch) {
      await db.outbox.update(item.id, { status: 'inflight' })
      try {
        await sendItem(item)
        await db.transaction('rw', db.outbox, db.blobs, db.sessions, async () => {
          await db.blobs.where('itemId').equals(item.id).delete()
          await db.sessions.delete(item.id)
          await db.outbox.delete(item.id)
        })
      } catch (e) {
        const attempts = item.attempts + 1
        // backoff exponentiel plafonné à 5 min
        const delay = Math.min(1000 * 2 ** attempts, 5 * 60_000)
        await db.outbox.update(item.id, {
          status: 'failed', attempts,
          nextAttemptAt: Date.now() + delay,
          lastError: String(e),
        })
      }
    }
  } finally { running = false }
}

// --- Déclencheurs : sur iOS il faut TOUS les cumuler (pas de Background Sync)
export function installDrainTriggers() {
  addEventListener('online', () => void drain())
  addEventListener('pageshow', () => void drain())
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void drain()   // <- le plus fiable sur iOS
  })
  setInterval(() => void drain(), 30_000)
  void drain()
}

### Extrait 5

// resources/js/offline/chunkedUpload.ts — upload reprenable, XHR (progression fiable sur iOS)
const CHUNK = 2 * 1024 * 1024 // 2 Mo : compromis 4G / nb d'aller-retours

export async function uploadResumable(
  blob: Blob, sessionUrl: string, token: string,
  onProgress: (sent: number, total: number) => void,
  signal?: AbortSignal,
) {
  // 1) où en est le serveur ?
  const head = await fetch(sessionUrl, { method: 'HEAD', headers: { Authorization: `Bearer ${token}` } })
  let offset = Number(head.headers.get('Upload-Offset') ?? 0)

  while (offset < blob.size) {
    const end = Math.min(offset + CHUNK, blob.size)
    const chunk = blob.slice(offset, end)
    const sentBefore = offset

    await new Promise<void>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('PATCH', sessionUrl, true)
      xhr.setRequestHeader('Authorization', `Bearer ${token}`)
      xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream')
      xhr.setRequestHeader('Upload-Offset', String(offset))
      xhr.timeout = 60_000
      // fetch() ne donne PAS la progression d'upload -> XHR obligatoire
      xhr.upload.onprogress = (e) => onProgress(sentBefore + e.loaded, blob.size)
      xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
        ? resolve()
        : reject(new Error(`HTTP ${xhr.status}`))
      xhr.onerror = () => reject(new Error('network'))
      xhr.ontimeout = () => reject(new Error('timeout'))
      signal?.addEventListener('abort', () => xhr.abort(), { once: true })
      xhr.send(chunk)
    })

    offset = end
    // persister l'offset : iOS gèle l'app dès qu'on quitte
    await persistOffset(sessionUrl, offset)
  }
}
declare function persistOffset(url: string, offset: number): Promise<void>

### Extrait 6

<!-- index.html — head iOS complet (iPhone 14 Plus) -->
<meta name="viewport"
      content="width=device-width, initial-scale=1, viewport-fit=cover">
<!-- PAS de user-scalable=no : a11y. Le double-tap se tue via touch-action: manipulation -->

<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Papers">

<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f7f7f8">
<meta name="theme-color" media="(prefers-color-scheme: dark)"  content="#101013">

<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon-180x180.png" sizes="180x180">

<!-- === SPLASH iPhone 14 Plus : 428x926 CSS @3x = 1284x2778 === -->
<link rel="apple-touch-startup-image"
  media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"
  href="/splash/iphone14plus_portrait_1284x2778.png">
<link rel="apple-touch-startup-image"
  media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)"
  href="/splash/iphone14plus_landscape_2778x1284.png">

<!-- Voisins utiles : iPhone 14/13/12 (390x844 @3x = 1170x2532),
     iPhone 15/16 Plus & Pro Max (430x932 @3x = 1290x2796) -->
<link rel="apple-touch-startup-image"
  media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"
  href="/splash/1170x2532.png">
<link rel="apple-touch-startup-image"
  media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"
  href="/splash/1290x2796.png">

### Extrait 7

// public/manifest.webmanifest
{
  "id": "/?source=pwa",
  "name": "Papers — Scanner & Analyse",
  "short_name": "Papers",
  "description": "Scanne, trie et analyse vos documents papier.",
  "start_url": "/?source=pwa",
  "scope": "/",
  "display": "standalone",
  "display_override": ["standalone", "minimal-ui"],
  "orientation": "portrait",
  "lang": "fr-FR",
  "dir": "ltr",
  "theme_color": "#101013",
  "background_color": "#f7f7f8",
  "categories": ["productivity", "business", "utilities"],
  "prefer_related_applications": false,
  "icons": [
    { "src": "/icons/pwa-64x64.png",  "sizes": "64x64",  "type": "image/png" },
    { "src": "/icons/pwa-192x192.png","sizes": "192x192","type": "image/png" },
    { "src": "/icons/pwa-512x512.png","sizes": "512x512","type": "image/png", "purpose": "any" },
    { "src": "/icons/maskable-icon-512x512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ],
  "shortcuts": [
    { "name": "Scanner un document", "short_name": "Scanner", "url": "/scan?source=shortcut",
      "icons": [{ "src": "/icons/shortcut-scan-96.png", "sizes": "96x96" }] },
    { "name": "Mes tâches", "short_name": "Tâches", "url": "/todo?source=shortcut",
      "icons": [{ "src": "/icons/shortcut-todo-96.png", "sizes": "96x96" }] }
  ],
  "share_target": {
    "action": "/share-target",
    "method": "POST",
    "enctype": "multipart/form-data",
    "params": { "files": [{ "name": "documents", "accept": ["image/*", "application/pdf"] }] }
  }
}

### Extrait 8

/* app.css — socle iOS */
:root {
  color-scheme: light dark;
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
  --safe-l: env(safe-area-inset-left, 0px);
  --safe-r: env(safe-area-inset-right, 0px);
  --app-h: 100vh;            /* fallback */
}
@supports (height: 100dvh) { :root { --app-h: 100dvh; } }

html, body {
  height: var(--app-h);
  margin: 0;
  overscroll-behavior-y: none;      /* coupe pull-to-refresh + rubber-band */
  -webkit-text-size-adjust: 100%;
  text-size-adjust: 100%;
}

.app-shell {
  min-height: var(--app-h);
  padding-top: var(--safe-t);
  padding-bottom: calc(var(--safe-b) + 8px);
  padding-left: max(16px, var(--safe-l));
  padding-right: max(16px, var(--safe-r));
}

/* barre d'onglets collée en bas, au-dessus du home indicator */
.tabbar { position: fixed; inset: auto 0 0 0; padding-bottom: var(--safe-b); }

/* tue le zoom double-tap SANS casser le pinch-zoom (a11y) */
button, a, [role="button"], .tappable { touch-action: manipulation; }

/* zone de scan : pas de menu contextuel ni de sélection */
.scan-surface {
  -webkit-touch-callout: none;
  -webkit-user-select: none; user-select: none;
  touch-action: none;               /* on gère nous-mêmes le drag des coins */
}
/* mais le texte extrait DOIT rester sélectionnable */
.extracted-text { -webkit-user-select: text; user-select: text; -webkit-touch-callout: default; }

.scroller { overflow-y: auto; overscroll-behavior: contain; }  /* momentum natif depuis iOS 13 */

@media (prefers-reduced-motion: reduce) {
  *, ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) {
    animation-duration: .01ms !important; transition-duration: .01ms !important;
  }
}

### Extrait 9

// React 19.3 — View Transitions (API STABLE) pour liste -> détail document
import { ViewTransition, startTransition, addTransitionType, unstable_addTransitionType } from 'react'
import { useNavigate } from '@tanstack/react-router'

function DocumentCard({ doc }: { doc: Doc }) {
  const navigate = useNavigate()
  return (
    <ViewTransition name={`doc-${doc.id}`}>
      <button className="tappable" onClick={() => {
        startTransition(() => {
          addTransitionType('push')
          navigate({ to: '/documents/$id', params: { id: doc.id } })
        })
      }}>
        <img src={doc.thumbUrl} alt="" />
        <span>{doc.title}</span>
      </button>
    </ViewTransition>
  )
}

// Transitions directionnelles (feuilletage multipage)
function Pager({ page, setPage }: { page: number; setPage: (n: number) => void }) {
  const go = (delta: number) => startTransition(() => {
    addTransitionType(delta > 0 ? 'next' : 'previous')
    setPage(page + delta)
  })
  return (
    <ViewTransition enter={{ next: 'from-right', previous: 'from-left' }}
                    exit={{ next: 'to-left', previous: 'to-right' }}>
      <PageView key={page} index={page} />
    </ViewTransition>
  )
}

// Respecter le geste de retour déjà animé par iOS (Navigation API, Safari 26.2+)
if ('navigation' in window) {
  navigation.addEventListener('navigate', (e: any) => {
    if (e.hasUAVisualTransition) return  // iOS a déjà animé -> ne pas doubler
    // ... sinon animer
  })
}

### Extrait 10

// Capture : input file (pleine résolution) + traitement dans un Worker via Comlink 4.4.2
// --- ui/ScanInput.tsx
export function ScanInput({ onPages }: { onPages: (p: Blob[]) => void }) {
  return (
    <input
      type="file"
      /* PAS de image/heic : force Safari 17+ à convertir en JPEG exploitable par canvas */
      accept="image/jpeg"
      capture="environment"   /* ouvre l'appareil photo natif -> 12 MP, pas le 720p de getUserMedia */
      multiple
      onChange={async (e) => {
        const files = [...(e.target.files ?? [])]
        const out: Blob[] = []
        for (const f of files) {
          const bitmap = await createImageBitmap(f)
          out.push(await scanner.process(bitmap))   // transfert zéro copie vers le worker
        }
        onPages(out)
      }}
    />
  )
}

// --- workers/scanner.worker.ts
import * as Comlink from 'comlink'

let cvReady: Promise<void>
function loadOpenCV() {
  // ~8 Mo de WASM : chargé à la demande, servi par un cache SW CacheFirst dédié
  cvReady ??= new Promise<void>((resolve) => {
    ;(self as any).Module = { onRuntimeInitialized: () => resolve() }
    importScripts('/vendor/opencv.js')
  })
  return cvReady
}

const api = {
  async process(bitmap: ImageBitmap): Promise<Blob> {
    await loadOpenCV()
    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height)
    const ctx = canvas.getContext('2d')!
    ctx.drawImage(bitmap, 0, 0)
    // jscanify 1.4.3 : highlightPaper() -> getCornerPoints() -> extractPaper() (warpPerspective)
    // puis adaptiveThreshold pour la binarisation
    // ...
    return canvas.convertToBlob({ type: 'image/jpeg', quality: 0.82 })
  },
}
Comlink.expose(api)

// --- côté app
import * as Comlink from 'comlink'
const scanner = Comlink.wrap<typeof api>(
  new Worker(new URL('./workers/scanner.worker.ts', import.meta.url), { type: 'module' })
)

### Extrait 11

// Auth : token en IndexedDB + refresh mutexé + déverrouillage Face ID (WebAuthn)
import { get, set, del } from 'idb-keyval'   // ou dexie

let refreshing: Promise<string> | null = null

async function getFreshToken(): Promise<string> {
  const t = await get<{ access: string; exp: number }>('auth')
  if (t && t.exp - 30_000 > Date.now()) return t.access
  // mutex : une seule requête de refresh concurrente
  refreshing ??= (async () => {
    try {
      // refresh token en cookie httpOnly SameSite=Lax (jamais lisible par JS)
      const r = await fetch('/api/auth/refresh', { method: 'POST', credentials: 'include' })
      if (!r.ok) throw new Error('refresh_failed')
      const { access_token, expires_in } = await r.json()
      await set('auth', { access: access_token, exp: Date.now() + expires_in * 1000 })
      return access_token as string
    } finally { refreshing = null }
  })()
  return refreshing
}

export async function logout() {
  await fetch('/api/auth/logout', { method: 'POST', credentials: 'include' })
  await del('auth')
  const reg = await navigator.serviceWorker.getRegistration()
  const sub = await reg?.pushManager.getSubscription()
  await sub?.unsubscribe()                       // sinon les push continuent d'arriver
  await Promise.all((await caches.keys()).filter(k => k.startsWith('api-')).map(k => caches.delete(k)))
}

// --- Déverrouillage biométrique (Face ID) via passkey : iOS 16+
export async function unlockWithFaceID(challengeB64: string, credentialIdB64: string) {
  const assertion = await navigator.credentials.get({
    publicKey: {
      challenge: b64ToBuf(challengeB64),
      rpId: location.hostname,
      allowCredentials: [{ type: 'public-key', id: b64ToBuf(credentialIdB64) }],
      userVerification: 'required',       // <- déclenche Face ID / Touch ID
      timeout: 60_000,
      // extension PRF : iOS 18+ / Safari 18+, passkeys iCloud Keychain uniquement
      extensions: { prf: { eval: { first: new TextEncoder().encode('papers-doc-encryption-v1') } } },
    },
  }) as PublicKeyCredential
  const prf = (assertion.getClientExtensionResults() as any).prf?.results?.first
  // prf -> HKDF -> clé AES-GCM pour chiffrer les documents en IndexedDB
  return { assertion, prf }
}
declare function b64ToBuf(s: string): ArrayBuffer

### Extrait 12

// Temps réel : Reverb au premier plan + Web Push en arrière-plan + polling de secours
import Echo from 'laravel-echo'          // 2.5.0
import Pusher from 'pusher-js'
import { useQuery, useQueryClient } from '@tanstack/react-query'  // 5.103.1

window.Pusher = Pusher
const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: 443, wssPort: 443, forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/broadcasting/auth',
})

export function useDocumentStatus(userId: number, docId: string) {
  const qc = useQueryClient()

  useEffect(() => {
    const ch = echo.private(`user.${userId}`)
      .listen('.document.analyzed', (e: { id: string }) => {
        qc.invalidateQueries({ queryKey: ['document', e.id] })
        qc.invalidateQueries({ queryKey: ['todos'] })
      })
    // iOS gèle le JS en arrière-plan -> WS coupé. On resynchronise au retour.
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return
      echo.connector.pusher.connect()
      qc.invalidateQueries()
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => { ch.stopListening('.document.analyzed'); document.removeEventListener('visibilitychange', onVisible) }
  }, [userId, qc])

  // filet de sécurité : polling tant que l'analyse est en cours
  return useQuery({
    queryKey: ['document', docId],
    queryFn: () => fetch(`/api/documents/${docId}`).then(r => r.json()),
    refetchInterval: (q) => q.state.data?.status === 'analyzing' ? 3000 : false,
    refetchOnWindowFocus: true,
  })
}

// --- Abonnement Web Push : UNIQUEMENT en standalone sur iOS, sur geste utilisateur
export async function subscribePush(vapidPublicKey: string) {
  const standalone = window.matchMedia('(display-mode: standalone)').matches
    || (navigator as any).standalone === true
  if (!standalone) throw new Error('Ajoutez l’app à l’écran d’accueil pour activer les notifications.')
  if (Notification.permission === 'denied') throw new Error('Notifications refusées')
  const perm = await Notification.requestPermission()   // DOIT être dans un handler de clic
  if (perm !== 'granted') return null
  const reg = await navigator.serviceWorker.ready
  return reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: b64ToBuf(vapidPublicKey),
  })
}

### Extrait 13

// Payload Declarative Web Push (Safari 18.4+ / iOS 18.4+) — envoyé par Laravel
// Content-Type: application/notification+json  (chiffré aes128gcm comme un push classique)
{
  "web_push": 8030,
  "notification": {
    "title": "Facture EDF analysée",
    "body": "3 échéances détectées — ajoutées à vos tâches",
    "navigate": "https://papers.example.fr/documents/01J9X?source=push",
    "lang": "fr-FR",
    "dir": "ltr",
    "silent": false,
    "app_badge": 3
  },
  "mutable": true   // permet au handler `push` du SW de modifier la notif s'il tourne
}
// Avantage iOS : la notification s'affiche même si le service worker ne démarre pas.
// Le VAPID JWT DOIT contenir un `sub` (mailto: ou URL) sinon Apple renvoie BadJwtToken.


## Incertitudes

- Version exacte de Safari sur iOS 27 au 17/09/2026 : la page de release notes Apple n'a pas pu être lue (contenu rendu en JS). Les fonctionnalités Safari 27 listées viennent du billet WebKit « News from WWDC26: WebKit in Safari 27 beta » — la version finale peut différer marginalement de la beta.
- Le statut exact de `<ViewTransition>` de React sur WebKit iOS 27 n'a pas été testé : React fait un fallback sans animation si l'API navigateur manque, mais les combinaisons `addTransitionType` + `::view-transition-*` n'ont pas été vérifiées sur Safari 27 réel.
- Le comportement de la Service Worker static routing API de Safari 27 (syntaxe exacte de `event.addRoutes()`, conditions supportées) n'a pas été vérifié en détail — potentiellement très utile pour bypasser le SW sur les routes d'upload, mais à valider.
- Existence et maturité d'un serveur tus first-party ou communautaire bien maintenu pour Laravel 13 : non vérifié. Le chunked upload maison est le pari sûr.
- Statut réglementaire DMA/UE : Apple avait retiré le mode standalone des PWA en iOS 17.4 dans l'UE puis fait marche arrière. L'état exact en iOS 27 (septembre 2026) n'a PAS été confirmé par une source Apple officielle. C'est un risque produit majeur pour une cible française — à vérifier directement sur un iPhone 14 Plus UE avant de valider l'architecture.
- Conflit de peer dependency entre @vite-pwa/assets-generator 2.0.0 et le peer optionnel ^1.0.0 déclaré par vite-plugin-pwa 1.3.0 : déduit des métadonnées npm, non testé à l'installation.
- Le blocage par Apple du hack haptique (input checkbox switch) en iOS 26.5 provient d'une source tierce (site de la lib @haptics/*), pas d'une note de release Apple.
- Quota exact appliqué à une Home Screen Web App sur iPhone : WebKit annonce 15 % par origine / 20 % global pour les « apps non-navigateur » et dit que la Home Screen Web App a le même quota qu'en navigateur — les deux formulations sont ambiguës entre elles. Mesurer avec navigator.storage.estimate() sur l'appareil cible.
- Résolution maximale réellement délivrée par <input type="file" capture="environment"> sur iPhone 14 Plus sous iOS 27 : non mesurée. À vérifier sur appareil (attendu ~12 MP / 4032x3024).
- Support de l'extension WebAuthn PRF spécifiquement en mode PWA standalone sur iOS 27 (par opposition à Safari onglet) : non vérifié directement.
- Versions de @tanstack/react-query et react-router : il existe peut-être des versions v6 / v2 en préversion non listées par le tag `latest`.
- SvelteKit 3 pourrait être passé en stable entre les résultats de recherche (RC en septembre 2026) et aujourd'hui — à revérifier si Svelte est retenu.

## Sources

- https://react.dev/versions
- https://react.dev/blog/2026/09/09/react-19-3
- https://registry.npmjs.org/vite/latest
- https://vite.dev/blog/announcing-vite8
- https://registry.npmjs.org/vite-plugin-pwa/latest
- https://registry.npmjs.org/@vite-pwa/assets-generator/latest
- https://registry.npmjs.org/workbox-window/latest
- https://registry.npmjs.org/@vitejs/plugin-react/latest
- https://registry.npmjs.org/laravel-vite-plugin/latest
- https://registry.npmjs.org/bun/latest
- https://registry.npmjs.org/svelte/latest
- https://registry.npmjs.org/zod/latest
- https://registry.npmjs.org/@tanstack/react-query/latest
- https://registry.npmjs.org/@tanstack/react-router/latest
- https://registry.npmjs.org/comlink/latest
- https://registry.npmjs.org/dexie/latest
- https://registry.npmjs.org/idb/latest
- https://registry.npmjs.org/pdf-lib/latest
- https://registry.npmjs.org/tus-js-client/latest
- https://registry.npmjs.org/jscanify/latest
- https://registry.npmjs.org/laravel-echo/latest
- https://vite-pwa-org.netlify.app/guide/inject-manifest
- https://vite-pwa-org.netlify.app/guide/periodic-sw-updates.html
- https://vite-pwa-org.netlify.app/assets-generator/
- https://vite-pwa-org.netlify.app/guide/service-worker-strategies-and-behaviors
- https://webkit.org/blog/17967/news-from-wwdc26-webkit-in-safari-27-beta/
- https://webkit.org/blog/14403/updates-to-storage-policy/
- https://webkit.org/blog/16535/meet-declarative-web-push/
- https://developer.apple.com/documentation/safari-release-notes/safari-27-release-notes
- https://developer.apple.com/videos/play/wwdc2026/204/
- https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide
- https://www.mobiloud.com/blog/progressive-web-apps-ios/
- https://web.dev/blog/baseline-navigation-api
- https://www.infoq.com/news/2026/05/navigation-api-browser/
- https://developer.mozilla.org/en-US/docs/Web/API/NavigateEvent/hasUAVisualTransition
- https://developer.mozilla.org/en-US/docs/Web/API/Request/duplex
- https://webkit.org/blog/18191/release-notes-for-safari-technology-preview-250/
- https://developer.chrome.com/docs/workbox/modules/workbox-background-sync
- https://developer.chrome.com/docs/workbox/retrying-requests-when-back-online
- https://www.corbado.com/blog/passkeys-prf-webauthn
- https://passkeys.dev/docs/reference/ios/
- https://developers.yubico.com/WebAuthn/Concepts/PRF_Extension/Developers_Guide_to_PRF.html
- https://www.testmuai.com/learning-hub/image-capture-api-browser-support/
- https://kb.strich.io/article/29-camera-access-issues-in-ios-pwa
- https://developer.apple.com/forums/thread/113532
- https://zenn.dev/kou_pg_0131/articles/safari-input-file-heic
- https://github.com/puffinsoft/jscanify
- https://opencv.org/blog/smart-document-scanning-with-live-ocr-using-opencv-js/
- https://scanbot.io/techblog/js-camera-document-scanner-tutorial/
- https://www.netguru.com/blog/how-to-share-session-cookie-or-state-between-pwa-in-standalone-mode-and-safari-on-ios
- https://github.com/PWA-POLICE/pwa-bugs
- https://bugs.webkit.org/show_bug.cgi?id=211018
- https://haptics-web.vercel.app/
- https://laravel.com/docs/13.x/broadcasting
- https://laravel.com/docs/13.x/sanctum
- https://laravel-news.com/laravel-13
- https://reverb.laravel.com/
- https://yesviz.com/devices/iphone-14-plus/
- https://blisk.io/devices/details/iphone-14-plus
- https://pwa.spomky-labs.com/favicons/startup-images
- https://bun.com/guides/ecosystem/vite
- https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria
- https://rxdb.info/rx-storage-opfs.html
- https://www.npmjs.com/package/@uppy/tus
- https://github.com/tus/tus-js-client


---

# research:openai

## Synthèse

## OpenAI API — septembre 2026 — OCR + extraction structurée de documents

Tout ce qui suit est vérifié sur `developers.openai.com` (l'ancien `platform.openai.com/docs` redirige en 301 vers `developers.openai.com/api/docs`). **Astuce majeure : toutes les pages de doc sont disponibles en Markdown brut en ajoutant `.md` à l'URL** (ex. `https://developers.openai.com/api/docs/guides/structured-outputs.md`), et l'index complet est sur `/llms.txt`. Très utile pour re-vérifier sans scraper du HTML.

---

### 1. Lineup de modèles (identifiants EXACTS)

Le lineup 2026 a abandonné le schéma `gpt-5-mini/nano` pour des noms de code. Les 4 modèles actuels :

| Model ID | Rôle | Contexte | Max input | Max output | Cutoff | Input $/1M | Cached $/1M | Output $/1M |
|---|---|---|---|---|---|---|---|---|
| `gpt-6-astra` | Flagship | 1 050 000 | — | — | 30 avr. 2026 | $10 | $1 | $50 |
| `gpt-5.6-sol` | Haut de gamme | 1 050 000 | 922 000 | 128 000 | 16 fév. 2026 | $4 | $0.40 | $20 |
| **`gpt-5.6-terra`** | **Équilibre (= ex-"mini")** | 1 050 000 | 922 000 | 128 000 | 16 fév. 2026 | **$2** | **$0.20** | **$12** |
| `gpt-5.6-luna` | Volume / coût | 1 050 000 | 922 000 | 128 000 | 16 fév. 2026 | $0.20 | $0.02 | $1.20 |

Anciens modèles toujours dispo (contexte 400K, cutoff 31 août 2025) : `gpt-5.4-mini` ($0.75/$4.50), `gpt-5.4-nano` ($0.20/$1.25), `gpt-5.5`, `gpt-5.4`, `gpt-5.2`, `gpt-5.1`, `gpt-5`, `gpt-4.1`, `gpt-4o`…

**Tous les modèles récents acceptent texte + image en entrée.** Aucun modèle n'accepte le PDF "en tant que modalité" — le PDF est géré au niveau de l'**API** (`input_file`), qui extrait texte + images de pages et les injecte dans le modèle vision.

**Recommandation pour ton scanner de documents :**
- **Défaut : `gpt-5.6-terra` avec `reasoning.effort: "low"`** — meilleur rapport qualité/prix pour de l'extraction documentaire (facture, courrier, contrat, relevé). Le tier "mini" 2026 est nettement plus solide que gpt-5-mini sur l'OCR de scans dégradés.
- **`gpt-5.6-luna`** (10× moins cher) pour les docs simples/répétitifs une fois ton pipeline stabilisé, ou pour un premier passage de classification/tri.
- **`gpt-6-astra`** uniquement en escalade sur documents manuscrits, tableaux denses, ou quand la validation métier échoue.
- **`reasoning.effort`** : `terra` et `luna` acceptent `none, low, medium (défaut), high, xhigh, max`. `none` = pas de reasoning tokens = beaucoup moins cher et plus rapide. `gpt-6-astra` **ne supporte PAS `none`**.

**Ordre de grandeur de coût réel (1 page A4 scannée, 1654×2339 px, `detail: original`)** : 52×74 = 3 848 patches × 1.2 = **4 618 tokens image**. Sur `terra` = **~0,0092 $/page** en input ; sur `luna` = **~0,00092 $/page**. Avec le résumé + JSON en sortie (~800 tokens) : ≈ **0,02 $/page sur terra**, **0,002 $/page sur luna**. En Batch : moitié prix.

---

### 2. Responses API vs Chat Completions

**Responses est l'API recommandée pour tout nouveau projet en 2026.** Chat Completions n'est pas dépréciée mais n'évolue plus. Pour `gpt-6-astra`, **le tool calling nécessite Responses**.

| | Chat Completions | Responses |
|---|---|---|
| Endpoint | `/v1/chat/completions` | `/v1/responses` |
| Entrée | `messages[]` | `input` (string **ou** tableau d'items) |
| System prompt | message `role: system` | paramètre top-level `instructions` |
| Structured Outputs | `response_format: {type:"json_schema", json_schema:{name, strict, schema}}` | `text: {format: {type:"json_schema", name, schema, strict}}` ← **`name`/`strict` sont frères de `type`, pas imbriqués** |
| Sortie | `choices[0].message` | `output[]` typé (items `message`, `reasoning`, `function_call`…) + raccourci `output_text` |
| Tools | wrapper `{"type":"function","function":{...}}` | à plat `{"type":"function","name":...,"parameters":...}` |
| Fichiers | PDF seulement, `file_data`/`file_id` | tous types, `file_data`/`file_id`/**`file_url`** |
| État | manuel | `previous_response_id`, Conversations API |
| Cache | — | +40 à +80 % de réutilisation du cache |

---

### 3. Entrée image (le cœur de ton scanner)

Format exact dans `input[].content[]` :

```json
{ "type": "input_image", "image_url": "data:image/jpeg;base64,<B64>", "detail": "original" }
```

Trois sources possibles : URL publique, **data URI base64** (`image_url`), ou `file_id` (Files API avec `purpose: "vision"`).

**Limites** : PNG / JPEG / WEBP / GIF non animé · **512 MB de payload total par requête** · **jusqu'à 1 500 images par requête** (parfait pour du multipage) · **max 30 000 patches par image** après resize — au-delà la requête est **rejetée, pas redimensionnée**.

**Paramètre `detail`** — `low` | `high` | `original` | `auto` (défaut `auto`). Comportement `gpt-5.6-*` :
- `low` → tient dans 512×512
- `high` → tient dans 2048×2048 **et** 2 500 patches
- `original` → dimensions préservées (downscale seulement au-delà de 65 535 px de côté)
- `auto` → identique à `original`

> **La doc recommande explicitement `"detail": "original"` pour l'OCR** et la détection de petits objets. C'est le bon choix pour tes scans : `high` écraserait une A4 à 2048² et ferait perdre le petit texte (mentions légales, numéros de facture, IBAN).

**Calcul du coût en tokens (tokenisation par patches de 32×32 px)** :
```
patch_count = ceil(w/32) × ceil(h/32)
si patch_count > patch_budget du couple (modèle, detail) :
  shrink = sqrt((32² × patch_budget) / (w×h))  → puis recompute
billable_tokens = ceil(patch_count_final × multiplier)
```
Multiplicateur = **1.2** pour `gpt-6-astra`, `gpt-5.6-sol`, `gpt-5.6-terra`, `gpt-5.6-luna`, `gpt-5.5`, `gpt-5.4*`, `gpt-5.2`. (1.62 pour `gpt-4.1-mini`, 2.46 pour `gpt-4.1-nano`.)

**Résolution optimale à viser côté PWA** : après détection de bords + correction de perspective, exporte en **~1600×2200 px, JPEG qualité 0.85**. Ça donne ≈ 3 500 patches (bien sous les 30 000), ~4 200 tokens, et conserve la lisibilité du corps de texte. Inutile de monter à 300 dpi pleine résolution : tu paies linéairement en tokens sans gain OCR au-delà de ~200 dpi.

---

### 4. Entrée PDF native — OUI, supportée

Format :
```json
{ "type": "input_file", "filename": "facture.pdf",
  "file_data": "data:application/pdf;base64,<B64>", "detail": "high" }
```
ou `{"type":"input_file","file_id":"file-abc"}` ou `{"type":"input_file","file_url":"https://..."}` (file_url = Responses uniquement).

- **Purpose Files API à utiliser : `user_data`** (et non `assistants`).
- **L'API extrait le texte ET les images de pages**, et envoie les deux au modèle → consommation de tokens élevée.
- `detail` (`auto` défaut / `low` / `high`) **n'affecte que les images de pages**, pas le texte extrait.
- **Limites : 50 MB par fichier, 50 MB cumulés par requête.** La doc actuelle **ne publie plus de limite de pages** (l'ancienne règle "100 pages / 32 MB" n'apparaît plus) — le vrai plafond est le contexte du modèle.
- Nécessite un modèle vision (gpt-4o et ultérieurs).
- Au-delà de gros volumes, la doc renvoie vers l'outil **File Search** plutôt que `input_file` direct.

**Pour ton cas** : puisque tu fais un vrai scan côté client (bords + perspective + binarisation), **envoie des `input_image` page par page plutôt qu'un PDF**. Tu contrôles la résolution, tu évites la double facturation texte+image du parsing PDF, et tu peux paralléliser. Réserve `input_file` aux PDF natifs importés par l'utilisateur.

---

### 5. Structured Outputs — syntaxe et contraintes exactes

Responses API :
```json
"text": { "format": { "type": "json_schema", "name": "document_extraction",
                      "strict": true, "schema": { ... } } }
```

**Contraintes du sous-ensemble JSON Schema (valeurs officielles à jour) :**
- Types supportés : `string`, `number`, `boolean`, `integer`, `object`, `array`, `enum`, `anyOf`
- **La racine doit être un `object` et ne peut pas être un `anyOf`** (piège classique avec les unions discriminées Zod/Pydantic)
- **`additionalProperties: false` obligatoire sur CHAQUE objet**
- **Tous les champs doivent figurer dans `required`** — un champ optionnel s'émule avec `"type": ["string","null"]`
- **Jusqu'à 5 000 propriétés d'objet au total, 10 niveaux d'imbrication**
- **Longueur totale des noms de propriétés/définitions/enum/const ≤ 120 000 caractères**
- **≤ 1 000 valeurs d'enum au total** ; pour un enum > 250 valeurs, ≤ 15 000 caractères cumulés
- `$defs` et **schémas récursifs supportés** (`$ref: "#"` ou `#/$defs/x`)
- Non supporté : `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`/`then`/`else`
- **L'ordre des clés de sortie suit l'ordre du schéma**
- Schéma invalide + `strict:true` → **erreur immédiate** (400)

**🔥 GROS CHANGEMENT vs 2024-2025 — les contraintes de valeur sont maintenant SUPPORTÉES** (sur modèles de base ; **pas** sur modèles fine-tunés) :
- strings : **`pattern`** (regex) et **`format`** ∈ `date-time`, `time`, **`date`**, `duration`, `email`, `hostname`, `ipv4`, `ipv6`, `uuid`
- numbers : `multipleOf`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`
- arrays : `minItems`, `maxItems`

**C'est LE levier anti-hallucination pour ton app** : `"format": "date"` force un ISO-8601 valide pour toutes tes échéances, et `"pattern"` verrouille les IBAN, SIRET, numéros de facture. Utilise-le systématiquement.

**Refus (`refusal`)** : le modèle peut refuser pour raisons de sécurité, et le refus **ne respecte pas ton schéma**. En Responses, ça arrive comme un content part `{"type":"refusal","refusal":"..."}` dans `output[].content[]`. Il faut aussi gérer `status != "completed"` (`incomplete_details` quand `max_output_tokens` est atteint). Ne fais jamais `json_decode(outputText)` sans ces deux vérifications.

---

### 6. Files API

- `POST /v1/files`, **512 MB par fichier**, **2,5 TB de stockage par projet**, **1 000 requêtes/min**
- `purpose` : `assistants`, `assistants_output`, `batch`, `batch_output`, `fine-tune`, `fine-tune-results`, **`vision`** (images), **`user_data`** (inputs modèle, dont PDF)
- **Expiration automatique** : `expires_after[anchor]=created_at` + `expires_after[seconds]=N` à l'upload → à utiliser absolument
- `GET /v1/files`, `GET /v1/files/{id}`, `GET /v1/files/{id}/content`, `DELETE /v1/files/{id}`
- ⚠️ `/v1/files` **n'est PAS éligible Zero Data Retention** et conserve l'état applicatif **jusqu'à suppression**. Pour des documents personnels, **préfère le base64 inline** (`/v1/responses` est ZDR-éligible, rétention `None`), ou purge explicitement.

---

### 7. Batch API — très pertinent chez toi

- Fichier `.jsonl`, une requête par ligne : `{"custom_id", "method":"POST", "url", "body"}`
- **`/v1/responses` est supporté** (ainsi que chat/completions, embeddings, moderations, images, video)
- **`completion_window` : `"24h"` uniquement**
- **−50 % sur input ET output**, pool de rate limits séparé (quota "Batch queue limit" par tier)
- Limites : **50 000 requêtes/batch, 200 MB de fichier d'entrée, 2 000 créations de batch/heure**
- Un fichier d'entrée = **un seul modèle**
- Résultats via `output_file_id` / erreurs via `error_file_id`, **rapprochement par `custom_id`** (l'ordre n'est pas garanti)
- Expiration : `{"error":{"code":"batch_expired"}}` pour les requêtes non traitées ; les traitées sont facturées

**Alternative souvent meilleure pour toi : `service_tier: "flex"`** — **tarif Batch exact, mais en synchrone** (plus lent, indisponibilité occasionnelle, beta). Idéal pour ton worker Laravel : tu gardes ton flux queue/job classique et tu paies moitié prix, sans la latence de 24 h. Mets un timeout HTTP à 15 min.

Stratégie recommandée : **Standard** quand l'utilisateur attend devant son écran (scan → résultat), **Flex** pour le ré-traitement/enrichissement en background, **Batch** pour les imports massifs.

---

### 8. Rate limits & 429

Tiers : Free / T1 ($5 payés) / T2 ($50) / T3 ($100) / T4 ($250) / T5 ($1 000). Exemple `gpt-5.6-terra` :

| Tier | RPM | TPM | Batch queue |
|---|---|---|---|
| 1 | 500 | 500 000 | 1 500 000 |
| 2 | 5 000 | 1 000 000 | 3 000 000 |
| 3 | 5 000 | 2 000 000 | 100 000 000 |
| 4 | 10 000 | 4 000 000 | 200 000 000 |
| 5 | 15 000 | 40 000 000 | 15 000 000 000 |

(`gpt-5.6-luna` monte à 30 000 RPM / 180M TPM en T5.)

**⚠️ Changement de sémantique 2026 — les handlers d'erreur pré-2026 sont faux :**
- **`429` + `rate_limit_error` + code `slow_down`** = montée en charge trop rapide
- **`503` + `service_unavailable_error` + code `server_is_overloaded`** = modèle saturé
- Avant, `503` + `slow_down` couvrait les deux cas. **Il faut gérer 429 ET 503.**

Headers : `x-ratelimit-remaining-requests`, `x-ratelimit-remaining-tokens`, `x-ratelimit-reset-*`, **`Retry-After`** (présent sur 429 *et* 503 temporaires).

Règle : **respecter `Retry-After` comme un minimum + jitter aléatoire** ; s'il est absent, backoff exponentiel + jitter. Ne jamais retry les erreurs de quota/facturation. Ramp-up : au-delà de 1M TPM, n'augmente pas de plus de **+50 % toutes les 15 min**.

---

### 9. Client PHP pour Laravel

**`openai-php/laravel` v0.21.0 (sortie le 17 sept. 2026)** — wrapper officiel-communautaire de `openai-php/client ^0.21.0`, maintenu par Nuno Maduro & Sandro Gehri, MIT.

```bash
composer require openai-php/laravel:^0.21.0
php artisan openai:install
```

Contraintes : `php ^8.2.0` (✅ compatible PHP 8.5), `laravel/framework ^11.29|^12.12|^13.0`, `guzzlehttp/guzzle ^7.9.3`.

- ✅ **Responses API supportée** : `OpenAI::responses()->create()/createStreamed()/retrieve()/cancel()/delete()/list()`
- ✅ **Structured Outputs** : le client est un **passe-plat de tableaux** vers le JSON de l'API → `'text' => ['format' => [...]]` fonctionne tel quel. Pas de helper typé façon Pydantic/Zod : **la validation côté PHP est à ta charge** (justinrainbow/json-schema ou opis/json-schema).
- ✅ `files()->upload()`, `batches()->create()/retrieve()/cancel()/list()`, `embeddings()->create()`
- ✅ `OpenAI::fake([...])` + `OpenAI::assertSent()` pour les tests
- ⚠️ **Timeout par défaut = 30 s**, beaucoup trop court pour un scan multipage avec reasoning → mettre `OPENAI_REQUEST_TIMEOUT=180` (et 900 pour Flex)
- ✅ `OPENAI_BASE_URL` permet de pointer `https://eu.api.openai.com/v1` pour la résidence de données EU

---

### 10. Prompt engineering pour l'extraction documentaire

**Architecture de prompt recommandée (2 passes) :**
1. **Passe A — classification/tri** (`gpt-5.6-luna`, `reasoning.effort: none`, `detail: low`) : type de document, langue, nombre de pages, qualité du scan, orientation. Très peu cher, permet de router.
2. **Passe B — extraction** (`gpt-5.6-terra`, `reasoning.effort: low/medium`, `detail: original`) avec un **schéma JSON spécifique au type détecté** (facture ≠ contrat ≠ courrier administratif). Un schéma spécialisé extrait bien mieux qu'un schéma générique fourre-tout.

**Règles anti-hallucination (à mettre dans `instructions`) :**
- « **Transcris, n'interprète pas.** Toute valeur doit être littéralement lisible sur l'image. »
- « Si un champ n'est pas visible ou est illisible, mets `null`. **N'infère jamais** une date, un montant ou une référence absente. »
- « Pour chaque montant et chaque date, reporte dans `source_text` **la chaîne exacte telle qu'imprimée**, avant normalisation. » ← **le levier le plus efficace** : forcer la citation brute à côté de la valeur normalisée rend la falsification bien plus difficile et te donne un moyen de vérification automatique (regex de cohérence entre `source_text` et la valeur).
- « Les dates ambiguës (`03/04/2026`) : utilise la convention **JJ/MM/AAAA** (documents francophones) et signale l'ambiguïté dans `confidence`. »
- « **La date du jour est {{today}}** » — indispensable pour calculer les échéances relatives (« sous 30 jours », « avant fin du mois »). Ne jamais laisser le modèle deviner la date courante.
- Ajouter un champ `confidence` (`high|medium|low`) **par groupe de champs**, pas global → permet de router vers une validation humaine ciblée.
- Ajouter `page_number` sur chaque entité extraite pour le multipage.
- Contraindre par schéma : `format: "date"` sur toutes les dates, `pattern` sur IBAN/SIRET/TVA, `minimum: 0` sur les montants, enum fermé sur les devises et les types de documents.

**Vérifications déterministes côté Laravel (à faire, pas négociable) :**
- recalcul `HT + TVA = TTC` (tolérance 0,02 €) ; si faux → re-passe sur `gpt-6-astra`
- validation IBAN (mod 97), SIRET (Luhn), TVA intracom
- `date_echeance >= date_emission`, et toute échéance passée → flag au lieu d'un rappel calendrier
- `source_text` doit contenir les chiffres de la valeur normalisée

**Tâches / échéances** : demande des **dates absolues ISO-8601 uniquement** (jamais « dans 2 semaines »), plus un champ `deadline_basis` (`explicit` | `derived_from_terms` | `assumed`) pour ne créer d'événement iCloud automatique que sur `explicit`/`derived_from_terms`.

**Prompt caching** : minimum **1 024 tokens** de préfixe visible sur GPT-5.6+, TTL `30m` (seule valeur), remise jusqu'à **−90 %** sur l'input mis en cache, écriture facturée **1,25×**. Mets tes `instructions` + ton schéma **en tête et à l'identique** pour tous les documents d'un même type → gros gain (ton bloc d'instructions fera facilement 1 500-2 500 tokens). Sur GPT-5.6+, le routing est automatique, `prompt_cache_key` n'est plus nécessaire (utile seulement pour séparer la comptabilité de cache par utilisateur).

---

### 11. Embeddings (pgvector)

⚠️ **`text-embedding-4` N'EXISTE PAS** — l'URL `/api/docs/models/text-embedding-4` renvoie **404**, et le catalogue officiel ne liste que trois modèles d'embedding. Des agrégateurs tiers l'annoncent : c'est faux.

| Modèle | Dimensions | Max tokens | $/1M | MTEB |
|---|---|---|---|---|
| **`text-embedding-3-small`** | **1536** | 8 192 | **$0.02** | 62.3 % |
| `text-embedding-3-large` | 3072 | 8 192 | $0.13 | 64.6 % |
| `text-embedding-ada-002` | 1536 | 8 192 | $0.10 | 61.0 % (legacy) |

- Le paramètre **`dimensions`** permet de réduire la taille sans perte sémantique majeure (Matryoshka) → `text-embedding-3-large` réduit à **1024** est un excellent compromis pour pgvector (index HNSW plus léger).
- Vecteurs **normalisés** → la similarité cosinus se calcule par simple produit scalaire ; avec pgvector utilise `vector_cosine_ops` ou `<#>` (inner product).
- `/v1/embeddings` est **ZDR-éligible** et ne stocke **aucun** état applicatif.
- Disponible en Batch (−50 %), max 50 000 inputs par batch.

**Recommandation** : `text-embedding-3-small` (1536) pour démarrer — $0.02/1M, quasi gratuit sur des résumés de documents. Passe à `3-large` réduit à 1024 si la recherche sémantique déçoit.

---

### 12. Rétention des données / RGPD

- **Aucune donnée API n'est utilisée pour l'entraînement** (depuis le 1er mars 2023).
- **Logs d'abus : 30 jours par défaut** sur tous les endpoints.
- **Modified Abuse Monitoring** : exclut ton contenu des logs d'abus, garde toutes les capacités. **Sur approbation préalable.**
- **Zero Data Retention (ZDR)** : idem + **le paramètre `store` de `/v1/responses` et `/v1/chat/completions` est forcé à `false`**, même si tu envoies `true`. **Sur approbation préalable.**
- **Éligibilité ZDR par endpoint** :
  - ✅ `/v1/responses`, `/v1/chat/completions`, `/v1/embeddings`, `/v1/moderations`, `/v1/audio/speech` → rétention d'état applicatif **None**
  - ❌ **`/v1/files`, `/v1/batches`, `/v1/vector_stores`, `/v1/assistants`, `/v1/agents` → NON éligibles ZDR, conservation "jusqu'à suppression"**
- **Conséquence directe pour ton app** : si tu vises le RGPD strict sur des documents personnels, **passe les images en base64 inline sur `/v1/responses` avec `store: false`** et **évite Files API et Batch API**, ou purge-les agressivement (`expires_after` + `DELETE` explicite).
- **Résidence des données** : régions US, **EU**, UAE, Australie, Canada, Japon, Inde, Singapour, Corée du Sud, UK. Configuration **par projet**, + préfixe de domaine par requête (`https://eu.api.openai.com/v1`). TLS terminé dans la région via Cloudflare Regional Services. **Toute région hors US exige l'approbation des contrôles d'abus + un avenant Modified Retention.** Surcoût **+10 %** sur les modèles sortis après le 5 mars 2026. Le schéma de Structured Outputs est classé "system data" et **peut sortir de la région** — n'y mets pas de données personnelles dans les `description`.
- **Eyes Off** (pas de revue humaine) et **Safety Retention** existent comme options entreprise / BAA.

## Faits clés vérifiés

- Les docs OpenAI sont accessibles en Markdown brut en ajoutant .md a l'URL (ex: https://developers.openai.com/api/docs/guides/structured-outputs.md); index complet sur https://developers.openai.com/llms.txt. platform.openai.com/docs redirige en 301 vers developers.openai.com/api/docs.
- Modeles actuels (sept. 2026) et IDs exacts: gpt-6-astra, gpt-5.6-sol, gpt-5.6-terra, gpt-5.6-luna. Tous: contexte 1 050 000 tokens, max input 922 000, max output 128 000, entree texte+image, sortie texte.
- Prix /1M tokens (input / cached / output): gpt-6-astra $10/$1/$50 ; gpt-5.6-sol $4/$0.40/$20 ; gpt-5.6-terra $2/$0.20/$12 ; gpt-5.6-luna $0.20/$0.02/$1.20.
- Tarification long-contexte: les prompts de plus de 272K tokens d'input sont factures 2x input et 1.5x output SUR LA REQUETE ENTIERE. Les cache writes sont factures 1.25x le tarif input non-cache.
- Knowledge cutoff: gpt-6-astra = 30 avril 2026 ; famille gpt-5.6 = 16 fevrier 2026 ; famille gpt-5.4 = 31 aout 2025.
- reasoning.effort sur gpt-5.6-terra et gpt-5.6-luna accepte: none, low, medium (defaut), high, xhigh, max. gpt-6-astra NE supporte PAS 'none'. gpt-5.4-mini/nano: none (defaut), low, medium, high, xhigh.
- Responses API (/v1/responses) est l'API recommandee pour tout nouveau projet. Chat Completions reste supportee mais n'evolue plus. Pour gpt-6-astra, le tool calling exige Responses.
- Structured Outputs en Responses: text.format = {type:'json_schema', name:'...', schema:{...}, strict:true} -- name/schema/strict sont FRERES de type. En Chat Completions: response_format = {type:'json_schema', json_schema:{name, strict, schema}} (imbrique).
- Entree image Responses: {"type":"input_image", "image_url":"data:image/jpeg;base64,..." | URL, "detail":"low|high|original|auto"} ou bien "file_id":"file-..." (Files API avec purpose 'vision').
- Limites image: PNG/JPEG/WEBP/GIF non anime ; 512 MB de payload total par requete ; jusqu'a 1 500 images par requete ; max 30 000 patches par image (au-dela la requete est REJETEE, pas redimensionnee).
- La doc OpenAI recommande explicitement detail:'original' pour l'OCR et les taches sensibles aux coordonnees. auto == original pour la famille gpt-5.6 et gpt-6-astra.
- Comportement de resize gpt-5.6-sol/terra/luna: low tient dans 512x512 ; high tient dans 2048x2048 ET 2 500 patches ; original preserve les dimensions (downscale seulement au-dela de 65 535 px de cote).
- Tokenisation image par patches 32x32: patch_count = ceil(w/32)*ceil(h/32), puis shrink_factor = sqrt((32^2 * patch_budget)/(w*h)) si depassement, puis billable_tokens = ceil(patch_count * multiplier). Multiplicateur = 1.2 pour gpt-6-astra, gpt-5.6-sol/terra/luna, gpt-5.5, gpt-5.4*, gpt-5.2.
- Exemples officiels gpt-6-astra detail:high -- 1024x1024 => 1024 patches => 1229 tokens ; 2048x2048 => reduit a 1600x1600 => 2500 patches => 3000 tokens ; 4096x512 => 2048 patches => 2458 tokens.
- PDF natif supporte via {"type":"input_file", "filename":..., "file_data":"data:application/pdf;base64,...", "detail":"auto|low|high"}, ou file_id, ou file_url (file_url = Responses uniquement, pas Chat Completions).
- Limites fichiers en requete: chaque fichier < 50 MB, et 50 MB cumules sur l'ensemble des fichiers de la requete. La doc actuelle ne publie PLUS de limite en nombre de pages PDF.
- Pour les PDF l'API extrait a la fois le texte ET les images de pages et envoie les deux au modele -> consommation de tokens elevee. Le parametre detail n'affecte QUE les images de pages.
- Files API purpose a utiliser: 'user_data' pour les inputs modele (PDF), 'vision' pour les images. Autres valeurs: assistants, assistants_output, batch, batch_output, fine-tune, fine-tune-results.
- Files API: 512 MB par fichier, 2,5 TB de stockage par projet, 1 000 uploads/min. Expiration automatique via expires_after[anchor]=created_at et expires_after[seconds]=N.
- Contraintes Structured Outputs: racine obligatoirement un object et jamais anyOf ; additionalProperties:false sur CHAQUE objet ; TOUS les champs dans required (optionnel = type:['string','null']) ; jusqu'a 5 000 proprietes et 10 niveaux d'imbrication ; 120 000 caracteres cumules pour noms/enums/const ; max 1 000 valeurs d'enum au total.
- NOUVEAU vs 2024-2025: Structured Outputs supporte desormais pattern et format (date-time, time, date, duration, email, hostname, ipv4, ipv6, uuid) pour les strings ; multipleOf/minimum/maximum/exclusiveMinimum/exclusiveMaximum pour les nombres ; minItems/maxItems pour les tableaux. MAIS PAS sur les modeles fine-tunes.
- Non supporte par Structured Outputs: allOf, not, dependentRequired, dependentSchemas, if/then/else. $defs et schemas recursifs ($ref:'#' ou #/$defs/x) SONT supportes.
- L'ordre des cles en sortie suit exactement l'ordre des cles du schema fourni.
- Refus: en Responses API le refus arrive comme content part {"type":"refusal","refusal":"..."} dans output[].content[] et ne respecte PAS le schema. Verifier aussi response.status != 'completed' et incomplete_details (max_output_tokens atteint).
- Batch API: JSONL {custom_id, method, url, body} ; /v1/responses est supporte ; completion_window='24h' UNIQUEMENT ; -50% input et output ; max 50 000 requetes/batch ; fichier d'entree max 200 MB ; 2 000 creations de batch/heure ; un seul modele par fichier d'entree.
- service_tier:'flex' = tarif Batch EXACT mais en synchrone (plus lent, indisponibilite occasionnelle, en beta). Souvent superieur au Batch pour un worker Laravel: moitie prix sans attendre 24h. Prevoir un timeout de 15 min.
- service_tier:'priority' a ete renomme 'fast' le 30 juillet 2026 ; les deux valeurs restent acceptees. Fast mode double les tarifs standard.
- Rate limits gpt-5.6-terra: T1 500 RPM / 500K TPM ; T2 5 000 / 1M ; T3 5 000 / 2M ; T4 10 000 / 4M ; T5 15 000 / 40M. gpt-5.6-luna monte a 30 000 RPM / 180M TPM en T5.
- CHANGEMENT 2026 des codes d'erreur: 429 + rate_limit_error + code 'slow_down' = montee en charge trop rapide ; 503 + service_unavailable_error + code 'server_is_overloaded' = modele sature. Avant, 503 + slow_down couvrait les deux. Il faut gerer 429 ET 503.
- Retry-After peut etre present sur les 429 ET sur les 503 temporaires ; le traiter comme un MINIMUM et ajouter un jitter aleatoire. S'il est absent, backoff exponentiel + jitter.
- Ramp-rate: au-dela de 1M tokens input/minute, ne pas augmenter le trafic de plus de +50% toutes les 15 minutes.
- openai-php/laravel v0.21.0 publiee le 17 septembre 2026. Requiert php ^8.2.0 (compatible PHP 8.5), laravel/framework ^11.29|^12.12|^13.0, guzzlehttp/guzzle ^7.9.3, openai-php/client ^0.21.0. MIT, 11,5M+ installations.
- openai-php/client supporte la Responses API: responses()->create(), createStreamed(), retrieve(), retrieveStreamed(), cancel(), delete(), list(). Egalement files()->upload()/download(), batches()->create()/retrieve()/cancel()/list(), embeddings()->create().
- Le client PHP est un passe-plat de tableaux vers le JSON de l'API: 'text' => ['format' => [...]] fonctionne tel quel pour les Structured Outputs. Il n'existe AUCUN helper typé equivalent a Pydantic/Zod -- la validation JSON Schema cote PHP est a la charge du developpeur.
- Timeout par defaut du client Laravel = 30 secondes (config openai.request_timeout / env OPENAI_REQUEST_TIMEOUT). Trop court pour un scan multipage avec reasoning.
- OPENAI_BASE_URL dans la config Laravel permet de pointer https://eu.api.openai.com/v1 pour la residence de donnees UE.
- Prompt caching GPT-5.6+: prefixe minimum 1 024 tokens visibles ; remise jusqu'a -90% sur l'input mis en cache ; cache writes factures 1.25x ; TTL controle par prompt_cache_options.ttl, seule valeur supportee '30m' (aussi le defaut) ; routing automatique, prompt_cache_key n'est plus necessaire (utile seulement pour separer la comptabilite par utilisateur).
- Embeddings: SEULS text-embedding-3-small (1536 dims, $0.02/1M, MTEB 62.3%), text-embedding-3-large (3072 dims, $0.13/1M, MTEB 64.6%) et text-embedding-ada-002 (legacy, $0.10/1M) existent. Max 8 192 tokens. Le parametre 'dimensions' permet la reduction Matryoshka. Vecteurs normalises -> cosinus = produit scalaire.
- Retention: aucune donnee API utilisee pour l'entrainement depuis le 1er mars 2023. Logs d'abus conserves 30 jours par defaut sur tous les endpoints.
- Zero Data Retention force le parametre 'store' de /v1/responses et /v1/chat/completions a false, meme si la requete demande true. ZDR et Modified Abuse Monitoring exigent une approbation prealable d'OpenAI.
- Eligibilite ZDR: /v1/responses, /v1/chat/completions, /v1/embeddings, /v1/moderations, /v1/audio/speech = OUI (retention d'etat applicatif 'None'). /v1/files, /v1/batches, /v1/vector_stores, /v1/assistants, /v1/agents = NON eligibles ZDR, conservation 'jusqu'a suppression'.
- Residence des donnees: US, EU, UAE, Australie, Canada, Japon, Inde, Singapour, Coree du Sud, UK. Config par projet + prefixe de domaine par requete (eu.api.openai.com). Toute region hors US exige l'approbation des controles d'abus + avenant Modified Retention. Surcout +10% pour les modeles sortis apres le 5 mars 2026.
- Le schema de Structured Outputs est classe 'system data' et peut etre traite/stocke HORS de la region de residence choisie -- ne pas y mettre de donnees personnelles dans les descriptions.

## Pièges / ce qui ne marche PAS

- text-embedding-4 N'EXISTE PAS. L'URL developers.openai.com/api/docs/models/text-embedding-4 renvoie un 404 et le catalogue officiel ne liste que 3 modeles d'embedding. Plusieurs agregateurs de prix tiers (CloudPrice, etc.) l'annoncent a tort avec un contexte de 2K et $0.1/1M -- information fausse.
- Ne pas confondre les limites Azure OpenAI et OpenAI direct. La doc Microsoft Learn annonce '100 proprietes max, 5 niveaux d'imbrication' et declare minLength/maxLength/pattern/format/minimum/maximum NON supportes -- ce sont des limites Azure-specifiques et une doc en retard. OpenAI direct: 5 000 proprietes, 10 niveaux, et pattern/format/minimum/maximum SONT supportes sur les modeles de base.
- pattern, format, minimum, maximum, multipleOf, minItems, maxItems ne sont PAS supportes sur les MODELES FINE-TUNES, seulement sur les modeles de base. Si tu fine-tunes plus tard, ton schema cassera.
- La racine du schema ne peut jamais etre un anyOf. Piege classique: une union discriminee (Zod discriminatedUnion, Pydantic Union) genere un anyOf racine et provoque une erreur 400. Il faut envelopper dans un objet.
- Tous les champs doivent etre dans 'required' -- il n'y a pas de champ optionnel. Emuler avec type:['string','null']. Oublier cela = erreur 400 immediate avec strict:true.
- additionalProperties:false doit etre present sur CHAQUE objet imbrique, pas seulement a la racine.
- detail:'high' n'est PAS le bon choix pour l'OCR sur la famille gpt-5.6: il ecrase l'image dans 2048x2048 ET 2 500 patches, ce qui detruit le petit texte d'une A4. Utiliser detail:'original' (ou auto, qui est equivalent).
- Une image depassant 30 000 patches apres resize est REJETEE par l'API, elle n'est pas redimensionnee automatiquement. Il faut redimensionner cote client avant l'envoi.
- detail:'low' n'utilise pas toujours moins de tokens que 'high'. Sur gpt-5.4/5.4-mini/5.4-nano, 'low' utilise un budget de 6 144 patches contre 2 500 pour 'high' -- donc low coute PLUS cher que high sur ces modeles.
- Les handlers d'erreur ecrits avant 2026 sont casses: une montee en charge trop rapide renvoie desormais 429 + code 'slow_down' alors qu'elle renvoyait auparavant 503 + 'slow_down'. Il faut gerer les deux codes HTTP et inspecter le corps de l'erreur.
- Les SDK officiels retentent automatiquement les 429 et 503. Si tu ajoutes ta propre couche de retry sans desactiver celle du SDK, les boucles se multiplient. Le client PHP openai-php ne fait PAS de retry automatique -- il faut l'implementer soi-meme.
- Le timeout par defaut d'openai-php/laravel est de 30 secondes. Un scan multipage avec reasoning.effort medium/high depasse systematiquement ce delai. Symptome: des timeouts apparemment aleatoires en production.
- /v1/files et /v1/batches ne sont PAS eligibles au Zero Data Retention et conservent l'etat applicatif 'jusqu'a suppression'. Utiliser la Files API ou le Batch API pour des documents personnels annule l'essentiel du benefice RGPD du ZDR.
- Le schema JSON de Structured Outputs est classe comme 'system data' et peut sortir de la region de residence des donnees. Ne jamais mettre de donnees personnelles dans les champs 'description' du schema.
- La resolution de la residence UE exige une approbation commerciale prealable + un avenant Modified Retention, et coute +10% sur les modeles posterieurs au 5 mars 2026. Ce n'est pas un simple flag a activer.
- gpt-6-astra ne supporte pas reasoning.effort:'none'. Un code qui passe 'none' de facon generique cassera lors d'une escalade vers astra. La doc recommande de partir de 'low' en migrant depuis none/minimal.
- Le depassement de 272K tokens d'input facture 2x l'input ET 1.5x l'output SUR LA REQUETE ENTIERE, pas seulement sur le surplus. A surveiller si tu accumules beaucoup de pages dans un meme appel.
- Un fichier d'entree Batch ne peut cibler qu'UN SEUL modele. Melanger terra et luna dans le meme JSONL echoue.
- Les resultats du Batch ne sont PAS renvoyes dans l'ordre des requetes. Il faut imperativement faire le rapprochement via custom_id.
- openai-php n'offre aucun equivalent a responses.parse() / text_format (Pydantic, Zod). Il n'y a ni parsing ni validation automatique du JSON retourne: il faut valider soi-meme contre le schema et gerer json_decode.
- Un refus de securite ne respecte pas le schema JSON: il arrive comme un content part de type 'refusal'. Faire json_decode($response->outputText) sans verifier ni le refus ni response.status=='completed' produit un null silencieux ou une exception.
- Structured Outputs est incompatible avec les appels de fonctions paralleles: mettre parallel_tool_calls a false si tu combines les deux.
- L'ancienne limite documentee '100 pages / 32 MB' pour les PDF ne figure plus dans la documentation actuelle (qui indique 50 MB par fichier et 50 MB cumules). Des sources secondaires la citent encore.
- Le Flex processing est en beta avec une disponibilite limitee des modeles et des indisponibilites ponctuelles de ressources -- prevoir un repli sur le tier standard.
- platform.openai.com/docs/* renvoie des 301 vers developers.openai.com/api/docs/*. Les liens et scripts codes en dur sur l'ancien domaine doivent suivre la redirection.

## Extraits de code de référence

### Extrait 1

// composer.json — stack Laravel 13 / PHP 8.5
{
  "require": {
    "php": "^8.5",
    "laravel/framework": "^13.0",
    "openai-php/laravel": "^0.21.0",
    "opis/json-schema": "^2.3"
  }
}

# .env
OPENAI_API_KEY=sk-...
OPENAI_REQUEST_TIMEOUT=180        # 30s par defaut = beaucoup trop court
# OPENAI_BASE_URL=https://eu.api.openai.com/v1   # residence UE (sur approbation)

# php artisan openai:install

### Extrait 2

// JSON brut envoye a POST /v1/responses — scan multipage + extraction structuree
{
  "model": "gpt-5.6-terra",
  "store": false,
  "reasoning": { "effort": "low" },
  "max_output_tokens": 4000,
  "instructions": "Tu es un moteur d'extraction documentaire. Regles absolues:\n1. TRANSCRIS, N'INTERPRETE PAS. Toute valeur doit etre litteralement lisible sur l'image.\n2. Si un champ n'est pas visible ou est illisible, mets null. N'INFERE JAMAIS une date, un montant ou une reference absente.\n3. Pour chaque date et chaque montant, reporte dans source_text la chaine EXACTE telle qu'imprimee, avant normalisation.\n4. Dates ambigues (03/04/2026): convention JJ/MM/AAAA (documents francophones). Signale l'ambiguite via confidence.\n5. La date du jour est 2026-09-17. Utilise-la pour resoudre les echeances relatives.\n6. Les pages sont fournies dans l'ordre. Indique page_number pour chaque entite.",
  "input": [{
    "role": "user",
    "content": [
      { "type": "input_text", "text": "Document scanne, 2 pages. Extrais selon le schema." },
      { "type": "input_image", "detail": "original", "image_url": "data:image/jpeg;base64,<PAGE_1_B64>" },
      { "type": "input_image", "detail": "original", "image_url": "data:image/jpeg;base64,<PAGE_2_B64>" }
    ]
  }],
  "text": {
    "format": {
      "type": "json_schema",
      "name": "document_extraction",
      "strict": true,
      "schema": {
        "type": "object",
        "additionalProperties": false,
        "required": ["doc_type","langue","emetteur","destinataire","dates","montants","references","resume","taches","confidence"],
        "properties": {
          "doc_type": { "type": "string",
            "enum": ["facture","devis","contrat","courrier_administratif","releve_bancaire","bulletin_paie","avis_imposition","ordonnance","attestation","autre"] },
          "langue": { "type": "string", "enum": ["fr","en","de","es","it","autre"] },
          "emetteur": {
            "type": "object", "additionalProperties": false,
            "required": ["nom","adresse","siret","tva_intracom"],
            "properties": {
              "nom": { "type": ["string","null"] },
              "adresse": { "type": ["string","null"] },
              "siret": { "type": ["string","null"], "pattern": "^[0-9]{14}$" },
              "tva_intracom": { "type": ["string","null"], "pattern": "^[A-Z]{2}[0-9A-Z]{2,13}$" }
            }
          },
          "destinataire": {
            "type": "object", "additionalProperties": false,
            "required": ["nom","adresse"],
            "properties": {
              "nom": { "type": ["string","null"] },
              "adresse": { "type": ["string","null"] }
            }
          },
          "dates": {
            "type": "array", "maxItems": 20,
            "items": {
              "type": "object", "additionalProperties": false,
              "required": ["role","valeur","source_text","page_number"],
              "properties": {
                "role": { "type": "string", "enum": ["emission","echeance","periode_debut","periode_fin","signature","autre"] },
                "valeur": { "type": "string", "format": "date" },
                "source_text": { "type": "string" },
                "page_number": { "type": "integer", "minimum": 1 }
              }
            }
          },
          "montants": {
            "type": "array", "maxItems": 30,
            "items": {
              "type": "object", "additionalProperties": false,
              "required": ["role","valeur","devise","source_text","page_number"],
              "properties": {
                "role": { "type": "string", "enum": ["total_ht","tva","total_ttc","acompte","reste_a_payer","ligne","autre"] },
                "valeur": { "type": "number", "minimum": 0 },
                "devise": { "type": "string", "enum": ["EUR","USD","GBP","CHF"] },
                "source_text": { "type": "string" },
                "page_number": { "type": "integer", "minimum": 1 }
              }
            }
          },
          "references": {
            "type": "array", "maxItems": 20,
            "items": {
              "type": "object", "additionalProperties": false,
              "required": ["type","valeur"],
              "properties": {
                "type": { "type": "string", "enum": ["numero_facture","numero_client","numero_commande","iban","bic","numero_dossier","autre"] },
                "valeur": { "type": "string" }
              }
            }
          },
          "resume": { "type": "string", "description": "3 a 5 phrases, factuel, sans interpretation." },
          "taches": {
            "type": "array", "maxItems": 10,
            "items": {
              "type": "object", "additionalProperties": false,
              "required": ["intitule","echeance","deadline_basis","priorite","source_text"],
              "properties": {
                "intitule": { "type": "string" },
                "echeance": { "type": ["string","null"], "format": "date" },
                "deadline_basis": { "type": "string", "enum": ["explicit","derived_from_terms","assumed"] },
                "priorite": { "type": "string", "enum": ["haute","moyenne","basse"] },
                "source_text": { "type": ["string","null"] }
              }
            }
          },
          "confidence": {
            "type": "object", "additionalProperties": false,
            "required": ["global","dates","montants","qualite_scan"],
            "properties": {
              "global": { "type": "string", "enum": ["high","medium","low"] },
              "dates": { "type": "string", "enum": ["high","medium","low"] },
              "montants": { "type": "string", "enum": ["high","medium","low"] },
              "qualite_scan": { "type": "string", "enum": ["bonne","moyenne","mauvaise"] }
            }
          }
        }
      }
    }
  }
}

### Extrait 3

<?php
// app/Services/DocumentExtractor.php
namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

final class DocumentExtractor
{
    public function __construct(private readonly DocumentSchema $schema) {}

    /** @param string[] $pageJpegPaths chemins des pages deja redressees/binarisees cote client */
    public function extract(array $pageJpegPaths, string $model = 'gpt-5.6-terra'): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => sprintf('Document scanne, %d page(s). Extrais selon le schema.', count($pageJpegPaths)),
        ]];

        foreach ($pageJpegPaths as $path) {
            $content[] = [
                'type'      => 'input_image',
                // 'original' = recommande par la doc pour l'OCR. 'high' ecraserait a 2048x2048.
                'detail'    => 'original',
                'image_url' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents($path)),
            ];
        }

        $response = OpenAI::responses()->create([
            'model'             => $model,
            'store'             => false,                 // rien ne persiste cote OpenAI
            'reasoning'         => ['effort' => 'low'],    // none|low|medium|high|xhigh|max
            'max_output_tokens' => 4000,
            'instructions'      => $this->schema->instructions(now()->toDateString()),
            'input'             => [['role' => 'user', 'content' => $content]],
            'text'              => ['format' => [
                'type'   => 'json_schema',
                'name'   => 'document_extraction',
                'strict' => true,
                'schema' => $this->schema->jsonSchema(),
            ]],
        ]);

        return $this->decode($response->toArray());
    }

    /** Gere refus + reponse incomplete AVANT de tenter json_decode. */
    private function decode(array $raw): array
    {
        if (($raw['status'] ?? null) !== 'completed') {
            $reason = $raw['incomplete_details']['reason'] ?? ($raw['error']['message'] ?? 'unknown');
            throw new ExtractionFailed("Reponse incomplete: {$reason}");
        }

        foreach ($raw['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw new ExtractionRefused($part['refusal']);
                }
            }
        }

        $text = '';
        foreach ($raw['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'output_text') {
                    $text .= $part['text'];
                }
            }
        }

        $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

        // usage: $raw['usage']['input_tokens'] / ['output_tokens'] / ['input_tokens_details']['cached_tokens']
        return ['data' => $data, 'usage' => $raw['usage'] ?? []];
    }
}

### Extrait 4

<?php
// Backoff: le client openai-php ne retente PAS automatiquement.
// Semantique 2026: 429 rate_limit_error/slow_down ET 503 service_unavailable_error/server_is_overloaded.
namespace App\Support;

use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;

final class OpenAiRetry
{
    public static function call(callable $fn, int $maxAttempts = 5): mixed
    {
        $attempt = 0;

        beginning:
        $attempt++;

        try {
            return $fn();
        } catch (ErrorException|TransporterException $e) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null;

            // Ne JAMAIS retenter quota/facturation/schema invalide.
            if (! in_array($status, [429, 500, 502, 503, 504], true) || $attempt >= $maxAttempts) {
                throw $e;
            }

            // Retry-After est un MINIMUM (present sur 429 et sur les 503 temporaires).
            $retryAfter = self::retryAfterSeconds($e);
            $backoff    = $retryAfter ?? min(2 ** $attempt, 60);
            $jitter     = random_int(0, 1000) / 1000;

            usleep((int) (($backoff + $jitter) * 1_000_000));
            goto beginning;
        }
    }

    private static function retryAfterSeconds(\Throwable $e): ?float
    {
        // Selon la version du client, lire l'en-tete Retry-After de la reponse sous-jacente.
        $headers = method_exists($e, 'getResponseHeaders') ? $e->getResponseHeaders() : [];
        $value   = $headers['retry-after'][0] ?? $headers['Retry-After'][0] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}

### Extrait 5

<?php
// Verifications deterministes APRES extraction — le garde-fou anti-hallucination le plus important.
final class ExtractionValidator
{
    /** @return string[] liste des anomalies; non vide => escalade vers gpt-6-astra ou revue humaine */
    public function check(array $d): array
    {
        $issues = [];
        $byRole = [];
        foreach ($d['montants'] as $m) { $byRole[$m['role']][] = $m; }

        // 1. Coherence HT + TVA = TTC (tolerance 2 centimes)
        $ht  = $byRole['total_ht'][0]['valeur']  ?? null;
        $tva = $byRole['tva'][0]['valeur']       ?? null;
        $ttc = $byRole['total_ttc'][0]['valeur'] ?? null;
        if ($ht !== null && $tva !== null && $ttc !== null && abs(($ht + $tva) - $ttc) > 0.02) {
            $issues[] = "incoherence_totaux: {$ht} + {$tva} != {$ttc}";
        }

        // 2. Chaque montant doit se retrouver dans son source_text (anti-invention)
        foreach ($d['montants'] as $m) {
            $digits = preg_replace('/\\D/', '', (string) $m['valeur']);
            $src    = preg_replace('/\\D/', '', $m['source_text']);
            if ($digits !== '' && ! str_contains($src, $digits)) {
                $issues[] = "montant_non_trace: {$m['role']}={$m['valeur']} absent de \"{$m['source_text']}\"";
            }
        }

        // 3. Chronologie
        $emission = $this->dateFor($d['dates'], 'emission');
        $echeance = $this->dateFor($d['dates'], 'echeance');
        if ($emission && $echeance && $echeance < $emission) {
            $issues[] = 'echeance_anterieure_a_emission';
        }

        // 4. IBAN mod 97
        foreach ($d['references'] as $r) {
            if ($r['type'] === 'iban' && ! $this->validIban($r['valeur'])) {
                $issues[] = "iban_invalide: {$r['valeur']}";
            }
        }

        // 5. Ne creer un evenement calendrier QUE sur une echeance explicite
        foreach ($d['taches'] as $t) {
            if ($t['echeance'] !== null && $t['deadline_basis'] === 'assumed') {
                $issues[] = "echeance_supposee: {$t['intitule']}";
            }
        }

        return $issues;
    }

    private function dateFor(array $dates, string $role): ?string
    {
        foreach ($dates as $x) { if ($x['role'] === $role) { return $x['valeur']; } }
        return null;
    }

    private function validIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\\s+/', '', $iban));
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) { return false; }
        $r = substr($iban, 4).substr($iban, 0, 4);
        $n = '';
        foreach (str_split($r) as $c) { $n .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c; }
        $rem = 0;
        foreach (str_split($n, 7) as $chunk) { $rem = (int) (($rem.$chunk) % 97); }
        return $rem === 1;
    }
}

### Extrait 6

<?php
// Flex: tarif Batch (-50%) en synchrone. Ideal pour un job Laravel en background.
$response = OpenAI::responses()->create([
    'model'        => 'gpt-5.6-terra',
    'service_tier' => 'flex',      // 'fast' (ex-'priority') = 2x le prix, faible latence
    'store'        => false,
    'reasoning'    => ['effort' => 'low'],
    'input'        => $input,
    'text'         => ['format' => $format],
]);
// /!\ passer OPENAI_REQUEST_TIMEOUT=900 pour le flex

### Extrait 7

// Batch API — JSONL, une ligne par document, url = /v1/responses
// ATTENTION: un seul modele par fichier d'entree ; resultats NON ordonnes -> rapprocher par custom_id
{"custom_id":"doc_9f2a","method":"POST","url":"/v1/responses","body":{"model":"gpt-5.6-terra","store":false,"reasoning":{"effort":"low"},"instructions":"...","input":[{"role":"user","content":[{"type":"input_image","detail":"original","image_url":"data:image/jpeg;base64,..."}]}],"text":{"format":{"type":"json_schema","name":"document_extraction","strict":true,"schema":{}}}}}
{"custom_id":"doc_7b1c","method":"POST","url":"/v1/responses","body":{"model":"gpt-5.6-terra","...":"..."}}

### Extrait 8

<?php
// Batch en PHP
$file = OpenAI::files()->upload([
    'purpose' => 'batch',
    'file'    => fopen(storage_path('app/batch/in.jsonl'), 'r'),
]);

$batch = OpenAI::batches()->create([
    'input_file_id'     => $file->id,
    'endpoint'         => '/v1/responses',
    'completion_window' => '24h',   // seule valeur supportee
    'metadata'          => ['tenant_id' => (string) $tenantId],
]);

// plus tard
$batch = OpenAI::batches()->retrieve($batch->id);
if ($batch->status === 'completed') {
    $jsonl = OpenAI::files()->download($batch->outputFileId);
    foreach (explode("\n", trim($jsonl)) as $line) {
        $row = json_decode($line, true);
        $documentId = $row['custom_id'];              // rapprochement obligatoire
        $body       = $row['response']['body'] ?? null; // objet Response complet
        // $row['error']['code'] === 'batch_expired' si non traite dans les 24h
    }
    // erreurs: $batch->errorFileId
}

### Extrait 9

<?php
// Embeddings -> pgvector. text-embedding-3-small: 1536 dims, $0.02/1M.
// text-embedding-3-large reduit a 1024 via 'dimensions' si besoin de plus de qualite.
$emb = OpenAI::embeddings()->create([
    'model' => 'text-embedding-3-small',
    'input' => [$resume, $texteIntegral],
    // 'dimensions' => 1024,   // uniquement sur les modeles -3-*
]);

foreach ($emb->embeddings as $item) {
    DB::statement(
        'INSERT INTO document_chunks (document_id, chunk_index, embedding) VALUES (?, ?, ?)',
        [$documentId, $item->index, '['.implode(',', $item->embedding).']']
    );
}

### Extrait 10

-- Postgres / pgvector : les embeddings OpenAI sont normalises,
-- donc cosinus == produit scalaire. Index HNSW sur le cosinus.
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE document_chunks (
    id           bigserial PRIMARY KEY,
    user_id      bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    document_id  bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    chunk_index  int    NOT NULL,
    content      text   NOT NULL,
    embedding    vector(1536) NOT NULL
);

CREATE INDEX document_chunks_embedding_idx
    ON document_chunks USING hnsw (embedding vector_cosine_ops)
    WITH (m = 16, ef_construction = 64);

-- multi-utilisateurs: TOUJOURS filtrer par user_id
CREATE INDEX ON document_chunks (user_id);

-- recherche
SELECT document_id, content, 1 - (embedding <=> $1::vector) AS score
FROM document_chunks
WHERE user_id = $2
ORDER BY embedding <=> $1::vector
LIMIT 10;

### Extrait 11

<?php
// Upload d'un PDF natif importe par l'utilisateur, avec expiration automatique (30 jours).
// /!\ /v1/files N'EST PAS eligible ZDR: rétention 'jusqu'a suppression'. Purger explicitement.
$file = OpenAI::files()->upload([
    'purpose'                 => 'user_data',   // 'vision' pour les images
    'file'                    => fopen($pdfPath, 'r'),
    'expires_after' => ['anchor' => 'created_at', 'seconds' => 2592000],
]);

$response = OpenAI::responses()->create([
    'model' => 'gpt-5.6-terra',
    'store' => false,
    'input' => [['role' => 'user', 'content' => [
        ['type' => 'input_text', 'text' => 'Extrais selon le schema.'],
        ['type' => 'input_file', 'file_id' => $file->id, 'detail' => 'high'],
    ]]],
    'text' => ['format' => $format],
]);

OpenAI::files()->delete($file->id);   // purge immediate apres traitement

### Extrait 12

<?php
// Tests sans appel reseau (openai-php/laravel)
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Resources\Responses;

OpenAI::fake([
    CreateResponse::fake([
        'status' => 'completed',
        'output' => [[
            'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => json_encode($expected)]],
        ]],
    ]),
]);

$result = app(DocumentExtractor::class)->extract([$fixturePath]);

OpenAI::assertSent(Responses::class, function (string $method, array $params): bool {
    return $method === 'create'
        && $params['model'] === 'gpt-5.6-terra'
        && $params['store'] === false
        && $params['text']['format']['strict'] === true
        && $params['input'][0]['content'][1]['detail'] === 'original';
});


## Incertitudes

- Le nombre maximum de PAGES d'un PDF passe en input_file n'est plus documente nulle part. Seules les limites de 50 MB/fichier et 50 MB/requete sont publiees. La contrainte effective est probablement le contexte du modele (922K tokens d'input), mais ce n'est pas confirme explicitement.
- Les prix exacts de gpt-6-astra en cache input et cache writes n'ont pas ete lus directement sur sa page modele dediee (deduits de la table Batch: $0.50 batch => $1.00 standard pour le cached input). A reverifier sur /api/docs/models/gpt-6-astra.md avant de construire un calculateur de couts.
- Le tarif promotionnel de GPT-5.6 Sol est garanti 'au moins jusqu'au 21 novembre 2026' -- le prix de $4/$20 pourrait augmenter apres cette date.
- Je n'ai pas verifie si openai-php/client v0.21.0 expose des accesseurs typés pour les nouveaux champs de la Responses API 2026 (incomplete_details, prompt_cache_options, reasoning, service_tier, refusal content parts). Passer par ->toArray() est le contournement sûr, mais l'etendue exacte du typage reste a confirmer en lisant src/Responses/Responses/CreateResponse.php.
- Je n'ai pas verifie si openai-php/client gere correctement le multipart pour les uploads d'images binaires volumineux ni s'il impose une limite de taille de corps de requete cote Guzzle.
- Le comportement exact de 'reasoning.effort: none' sur la qualite de l'extraction OCR n'est pas documente -- a benchmarker empiriquement sur un echantillon de documents reels avant de choisir entre none, low et medium.
- La disponibilite du service_tier 'flex' pour gpt-5.6-terra specifiquement n'a pas ete confirmee sur la page de filtrage /api/docs/pricing?latest-pricing=flex ; la table Flex listait bien terra mais la doc precise que la disponibilite des modeles est 'limitee' et en beta.
- Le benchmark comparatif des modeles 2026 sur des taches d'OCR/document understanding (DocVQA, OCRBench) n'a pas ete trouve -- le choix de gpt-5.6-terra comme meilleur rapport qualite/prix est un raisonnement sur les prix et le positionnement declare, pas une mesure.
- Je n'ai pas verifie s'il existe un guide OpenAI dedie a l'extraction documentaire / OCR (type cookbook) publie en 2026 qui donnerait des prompts de reference officiels.
- La liste exacte des modeles eligibles a la residence de donnees UE n'a pas ete extraite (section 'Which models and features are eligible for data residency' de guides/your-data.md non lue en detail). A verifier que gpt-5.6-terra en fait partie avant de s'engager sur une architecture UE.
- Les limites de rate limit specifiques a gpt-6-astra et gpt-5.6-sol n'ont pas ete relevees (seules celles de terra et luna l'ont ete).
- L'existence et le format exact du champ 'prompt_cache_breakpoint' / 'prompt_cache_options.mode' dans le client PHP n'ont pas ete verifies -- ces parametres GPT-5.6+ devront probablement etre passes en tableau brut.

## Sources

- https://developers.openai.com/api/docs/models
- https://developers.openai.com/api/docs/models.md
- https://developers.openai.com/api/docs/models/gpt-5.6-terra.md
- https://developers.openai.com/api/docs/models/gpt-5.6-luna.md
- https://developers.openai.com/api/docs/models/gpt-5.4-mini.md
- https://developers.openai.com/api/docs/models/gpt-5.4-nano.md
- https://developers.openai.com/api/docs/pricing
- https://developers.openai.com/api/docs/pricing.md
- https://developers.openai.com/api/docs/guides/structured-outputs.md
- https://developers.openai.com/api/docs/guides/images-vision.md
- https://developers.openai.com/api/docs/guides/image-cost-calculator
- https://developers.openai.com/api/docs/guides/file-inputs.md
- https://developers.openai.com/api/docs/guides/pdf-files
- https://developers.openai.com/api/docs/guides/batch.md
- https://developers.openai.com/api/docs/guides/flex-processing.md
- https://developers.openai.com/api/docs/guides/rate-limits.md
- https://developers.openai.com/api/docs/guides/prompt-caching.md
- https://developers.openai.com/api/docs/guides/embeddings.md
- https://developers.openai.com/api/docs/guides/your-data.md
- https://developers.openai.com/api/docs/guides/latest-model.md
- https://developers.openai.com/api/docs/guides/migrate-to-responses
- https://developers.openai.com/api/docs/api-reference/files
- https://developers.openai.com/api/reference/resources/files/methods/create.md
- https://developers.openai.com/api/docs/api-reference/responses/create
- https://packagist.org/packages/openai-php/laravel
- https://github.com/openai-php/laravel
- https://raw.githubusercontent.com/openai-php/laravel/main/composer.json
- https://raw.githubusercontent.com/openai-php/laravel/main/config/openai.php
- https://github.com/openai-php/client
- https://raw.githubusercontent.com/openai-php/client/main/README.md
- https://raw.githubusercontent.com/openai-php/client/main/composer.json
- https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/structured-outputs
- https://www.morphllm.com/openai-api-pricing
- https://developers.openai.com/llms.txt


---

# research:scanning

## Synthèse

# Pipeline de VRAI scan documentaire côté navigateur sur iPhone 14 Plus (septembre 2026)

## 0. Contexte matériel / navigateur vérifié

- iPhone 14 Plus = A15 Bionic, 6 Go RAM, caméra grand-angle 12 MP (4032×3024). Il tourne aujourd'hui sous **iOS 26.x / Safari 26.x** (Apple a aligné les numéros de version sur l'année à la WWDC25). Dernière release documentée : **Safari 26.6, 27 juillet 2026**.
- Une PWA installée sur iOS utilise **WKWebView + WebKit**, identique à Safari. Aucune alternative moteur.

---

## 1. OpenCV.js en 2026

### 1.1 Versions

| Version | Date | Remarque |
|---|---|---|
| OpenCV **5.0.0** | 2026-06-06 | Nouveau moteur DNN, types FP16/BF16. Le build JS est fait avec **Emscripten 4.0.20 / C++17** |
| OpenCV **4.14.0** | 2026-07-19 | Dernière branche 4.x — **c'est celle que je recommande pour du WASM navigateur** |
| OpenCV 4.13.0 | 2025-12-31 | |
| OpenCV 4.12.0 | 2025-07-02 | |

**Attention** : aucune release GitHub OpenCV ne publie d'artefact `opencv.js`. Il faut soit passer par `docs.opencv.org/<version>/opencv.js`, soit par le package npm communautaire, soit **builder soi-même** (recommandé ici).

### 1.2 Tailles réelles (mesurées)

- `@techstark/opencv-js@5.0.0-release.1` → `dist/opencv.js` = **13 298 869 octets (13,3 Mo)**. C'est un build **SINGLE_FILE**, donc le `.wasm` est encodé en base64 *dans* le JS (+33 % de volume, et surtout **impossible d'utiliser `WebAssembly.instantiateStreaming`**). Inacceptable tel quel sur mobile.
- `jscanify@1.4.3` embarque son propre `src/opencv.js` de **8 980 607 octets (8,98 Mo)**.
- Build officiel complet ≈ 8,1 Mo (wasm) / 9,0 Mo (threads+SIMD).
- Un build custom restreint à `core` + une trentaine de fonctions `imgproc` descend typiquement à **1,5–2,5 Mo** (fichier `.wasm` séparé), soit **~500–800 Ko après Brotli**.

### 1.3 Build custom : commandes exactes

`build_js.py` accepte bien `--config` pour fournir votre propre whitelist (vérifié dans le source 4.x).

```bash
git clone --depth 1 --branch 4.14.0 https://github.com/opencv/opencv.git
cd opencv

docker run --rm -v "$PWD":/src -u $(id -u):$(id -g) emscripten/emsdk:4.0.20 \
  emcmake python3 ./platforms/js/build_js.py build_wasm \
    --build_wasm \
    --disable_single_file \
    --simd \
    --config /src/doc_scan.config.py \
    --build_flags="-Oz -flto"
# sortie : build_wasm/bin/opencv.js + build_wasm/bin/opencv_js.wasm
```

Flags disponibles (vérifiés dans `platforms/js/build_js.py`) : `--build_wasm`, `--disable_single_file`, `--simd`, `--threads`, `--config`, `--build_flags`, `--cmake_option`, `--enable_exception`, `--clean_build_dir`.

`doc_scan.config.py` :

```python
core = {
    '': ['absdiff','add','addWeighted','bitwise_not','convertScaleAbs','copyMakeBorder',
         'countNonZero','divide','flip','LUT','max','mean','meanStdDev','merge','min',
         'minMaxLoc','multiply','normalize','perspectiveTransform','rotate','split',
         'subtract','transpose'],
    'Algorithm': [],
}
imgproc = {
    '': ['adaptiveThreshold','approxPolyDP','arcLength','bilateralFilter','blur',
         'boundingRect','Canny','contourArea','convexHull','createCLAHE','cvtColor',
         'dilate','drawContours','erode','filter2D','findContours','GaussianBlur',
         'getPerspectiveTransform','getRotationMatrix2D','getStructuringElement',
         'HoughLinesP','isContourConvex','Laplacian','medianBlur','minAreaRect',
         'morphologyEx','pyrDown','resize','Sobel','threshold','warpAffine',
         'warpPerspective'],
    'CLAHE': ['apply','collectGarbage','setClipLimit','setTilesGridSize'],
}
white_list = makeWhiteList([core, imgproc])
```

Toutes ces fonctions sont **confirmées présentes dans la whitelist officielle** `platforms/js/opencv_js.config.py` de la branche 4.x (j'ai lu le fichier).

**Piège majeur vérifié** : le module `photo` d'OpenCV.js **n'expose PAS `fastNlMeansDenoising`** (il n'expose que HDR/`inpaint`). Pour le débruitage, il faut se rabattre sur `bilateralFilter`, `medianBlur` ou `morphologyEx`.

### 1.4 Chargement : le module est MODULARIZE

Vérifié dans `modules/js/CMakeLists.txt` :
```
-s TOTAL_MEMORY=128MB -s WASM_MEM_MAX=1GB -s ALLOW_MEMORY_GROWTH=1
-s MODULARIZE=1 -s EXPORT_NAME='cv'
```
Donc `cv` est une **factory qui renvoie une Promise**, pas un objet prêt à l'emploi.

```js
// loader robuste (couvre builds MODULARIZE et builds legacy onRuntimeInitialized)
export async function loadCv(jsUrl = '/wasm/opencv.js', wasmUrl = '/wasm/opencv_js.wasm') {
  if (globalThis.__cv) return globalThis.__cv;
  importScripts?.(jsUrl) ?? await import(/* @vite-ignore */ jsUrl);

  const factory = globalThis.cv;
  let cv;
  if (typeof factory === 'function') {
    cv = await factory({ locateFile: () => wasmUrl });     // MODULARIZE=1
  } else {
    cv = factory;
    if (!cv.Mat) await new Promise(r => { cv.onRuntimeInitialized = r; });
  }
  globalThis.__cv = cv;
  return cv;
}
```

Servez `opencv_js.wasm` avec `Content-Type: application/wasm` + `Content-Encoding: br`, et un `Cache-Control: public, max-age=31536000, immutable`. Ordre de grandeur sur iPhone 14 Plus, build custom ~2 Mo / ~700 Ko Brotli : **~300–600 ms en réseau chaud (cache HTTP), ~150–250 ms de compilation/instanciation WASM**. Le build complet 8–13 Mo monte facilement à **3–6 s au premier chargement** : inacceptable, d'où le build custom obligatoire.

### 1.5 SIMD / threads sur iOS

- **WASM SIMD** : supporté par WebKit depuis Safari 16.4. `--simd` est donc utilisable, mais le binaire SIMD **crashe sur les moteurs sans SIMD** → servez deux builds et faites une détection de capacité (`wasm-feature-detect`).
- **WASM threads** : nécessitent `SharedArrayBuffer`, donc **cross-origin isolation** (`Cross-Origin-Opener-Policy: same-origin` + `Cross-Origin-Embedder-Policy: require-corp|credentialless`). **À éviter pour ce projet** : COEP `require-corp` casserait vos chargements d'images tierces/CDN et complique la PWA. Testez toujours `self.crossOriginIsolated` avant de supposer que ça marche. Restez mono-thread + Web Worker.

### 1.6 Alternatives à OpenCV.js

| Solution | Verdict |
|---|---|
| **Implémentation manuelle en JS/WASM maison** (Rust+wasm-bindgen, ~150–300 Ko) | Meilleur ratio taille/perf si vous n'utilisez que 15 opérateurs. Coût de dev élevé. |
| **`@techstark/opencv-js`** 5.0.0-release.1, Apache-2.0, 13,3 Mo | Excellentes définitions TypeScript, mais SINGLE_FILE. Utilisez-le **uniquement pour les types** (`import type`) et chargez votre propre WASM. |
| **Scanbot Web SDK** (`scanbot-web-sdk` v9.0.0) | Commercial, sous licence payante. Très bonne qualité, mais coût récurrent + trial key. |
| **Dynamsoft Document Viewer / DDV** | Commercial également. |
| WebGL/WebGPU maison pour le warp | WebGPU est dispo sur iOS 26. Intéressant pour `warpPerspective` plein format, mais complexité importante. |

---

## 2. Bibliothèques JS « prêtes à l'emploi » — comparaison honnête

### 2.1 jscanify — **NE PAS UTILISER TEL QUEL**

- npm `jscanify@1.4.3`, publié **2026-07-20**, licence **MIT**, dépôt `puffinsoft/jscanify` (ex-`ColonelParrot`).
- Package npm de **30,4 Mo décompressés**, `main` = `src/jscanify-node.js`, **dépendances `canvas@^3.2.3` et `jsdom@^29.1.1`** (elles polluent tout bundle navigateur ; importez `jscanify/src/jscanify.js` en direct ou copiez le fichier).
- Le fichier navigateur fait **7 585 octets**, et son en-tête dit encore `jscanify v1.4.0` dans la 1.4.3.

**J'ai lu le code source intégral. Défauts algorithmiques rédhibitoires** :

1. `findPaperContour` applique **`cv.Canny` sur l'image RGBA d'origine**, *puis* un `GaussianBlur` sur la carte d'arêtes, *puis* un `threshold(…, THRESH_OTSU)` sur ce flou. L'ordre est inversé : flou → Canny est la séquence correcte. Le blur post-Canny ne fait qu'épaissir le bruit.
2. **Aucun `approxPolyDP`**, aucun test de convexité, aucun test de ratio d'aire : il prend bêtement le **plus grand contour par aire**. Sur un bureau en bois ou un fond texturé, il sélectionne le cadre de l'image entière.
3. `getCornerPoints` prend le centre du `minAreaRect` et, dans chacun des 4 quadrants, le point **le plus éloigné du centre**. Ça casse dès que le document est incliné de plus de ~20°, ou pas centré, ou dépasse d'un bord.
4. Pas de downscale : il travaille à la résolution native → inutilisable en temps réel.
5. Dépend de la **variable globale `cv`**, aucune injection d'instance → incompatible avec un Web Worker propre ou un bundler moderne.
6. Pas de suppression de `contours`/`hierarchy` en cas d'early return dans `extractPaper` → **fuites mémoire WASM**.

Les mentions « glare suppression / multi-colored paper support » du README ne correspondent à **aucun code** dans `src/jscanify.js` 1.4.3.

**Utilité réelle** : lisez-le comme squelette de 200 lignes, réécrivez tout.

### 2.2 `opencv-document-scanner` (tony-xlh / xulihang) — v1.2.2, 2025-09-11, MIT, 15,6 Ko

J'ai aussi lu son source : **exactement le même algorithme que jscanify** (même heuristique quadrant/minAreaRect), avec deux ajouts utiles :
- un downscale à **720 px de hauteur** avant détection, puis remise à l'échelle des coins (`i = height/720`) → bonne idée, à reprendre ;
- `crop()` **estime la taille de sortie** à partir des distances entre coins (`max(dist(p0,p1), dist(p2,p3))`) au lieu d'imposer un width/height fixe → nettement mieux que jscanify.

Mais il est couplé au viewer **commercial** Dynamsoft DDV (`OpenCVDocumentDetectHandler extends Dynamsoft.DDV.DocumentDetect`). Prenez seulement la logique.

### 2.3 Les autres

| Lib | État réel |
|---|---|
| **`scanner-js`** | Rien à voir : ce n'est pas de la vision par ordinateur. Ne l'utilisez pas. |
| **`docscan`** | Package fantôme/abandonné, aucun écosystème. |
| **`cropperjs` v2.2.0** (2026-08-23, MIT, 473 Ko) | **Ne fait AUCUNE détection ni correction de perspective.** C'est un recadrage rectangulaire à base de Web Components. Utile uniquement comme **UI de retouche manuelle après échec de détection** — et encore, il ne gère pas les quadrilatères libres. Pour l'ajustement manuel des 4 coins, écrivez ~150 lignes de Pointer Events sur un `<canvas>` overlay : c'est plus simple et plus juste. |
| **`opencv-tools`** | Pas de package sérieux/maintenu correspondant sur npm. |
| **Asprise ScannerJS** | Scanners TWAIN physiques via applet — hors sujet total pour une PWA iPhone. |

**Conclusion : écrivez votre propre pipeline.** Les gains sont massifs et le code fait ~400 lignes.

---

## 3. Pipeline algorithmique complet (code réel)

### 3.1 Détection du quadrilatère (robuste)

```js
// detect.js — s'exécute dans le Worker, sur une image DOWNSCALÉE (short side ~480-640 px)
/**
 * @param {any} cv       instance OpenCV.js
 * @param {cv.Mat} src   RGBA, déjà downscalé
 * @returns {{quad: {x,y}[], score: number} | null}  coins en coords de src
 */
export function detectQuad(cv, src) {
  const W = src.cols, H = src.rows, area = W * H;
  const gray = new cv.Mat(), work = new cv.Mat(), edges = new cv.Mat();
  const contours = new cv.MatVector(), hierarchy = new cv.Mat();
  const trash = [gray, work, edges, contours, hierarchy];

  try {
    cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);

    // 1) Débruitage préservant les bords. d=5 pour rester temps réel ;
    //    d=9 sur la passe haute résolution seulement.
    cv.bilateralFilter(gray, work, 5, 40, 40, cv.BORDER_DEFAULT);

    // 2) Fermeture morphologique : bouche le texte pour ne laisser que
    //    la silhouette de la page (clé pour éviter que Canny suive les lignes de texte)
    const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(7, 7));
    cv.morphologyEx(work, work, cv.MORPH_CLOSE, k);
    k.delete();

    // 3) Canny à seuils auto (médiane d'Otsu) — bien plus stable que 50/200 en dur
    const otsuTmp = new cv.Mat();
    const high = cv.threshold(work, otsuTmp, 0, 255, cv.THRESH_BINARY + cv.THRESH_OTSU);
    otsuTmp.delete();
    cv.Canny(work, edges, high * 0.5, high, 3, false);

    // 4) Dilatation 1px : referme les arêtes discontinues (coins arrondis, ombre douce)
    const k2 = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3));
    cv.dilate(edges, edges, k2);
    k2.delete();

    cv.findContours(edges, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE);

    let best = null, bestScore = 0;
    for (let i = 0; i < contours.size(); i++) {
      const c = contours.get(i);
      const cArea = cv.contourArea(c);
      // 5) Filtre d'aire : entre 12 % et 98 % du cadre
      if (cArea < area * 0.12 || cArea > area * 0.98) { c.delete(); continue; }

      const peri = cv.arcLength(c, true);
      const approx = new cv.Mat();
      cv.approxPolyDP(c, approx, 0.02 * peri, true);   // 2 % : valeur éprouvée

      if (approx.rows === 4 && cv.isContourConvex(approx)) {
        const pts = matToPoints(approx);
        const s = quadScore(pts, cArea, area);
        if (s > bestScore) {
          bestScore = s;
          best = pts;
        }
      }
      approx.delete(); c.delete();
    }

    // 6) Repli : enveloppe convexe du plus grand contour + approx plus permissive
    if (!best) best = fallbackHull(cv, contours, area);

    return best ? { quad: orderCorners(best), score: bestScore } : null;
  } finally {
    trash.forEach(m => m.delete());
  }
}

function matToPoints(approx) {
  const d = approx.data32S, out = [];
  for (let i = 0; i < d.length; i += 2) out.push({ x: d[i], y: d[i + 1] });
  return out;
}

/** Score = aire relative × « rectangularité » (angles proches de 90°) × cohérence des côtés opposés */
function quadScore(p, cArea, frameArea) {
  const areaRatio = cArea / frameArea;

  // angles
  let angleCost = 0;
  for (let i = 0; i < 4; i++) {
    const a = p[(i + 3) % 4], b = p[i], c = p[(i + 1) % 4];
    const v1 = { x: a.x - b.x, y: a.y - b.y }, v2 = { x: c.x - b.x, y: c.y - b.y };
    const cos = (v1.x * v2.x + v1.y * v2.y) /
                (Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y) + 1e-6);
    angleCost += Math.abs(cos);            // 0 = angle droit parfait
  }
  const rect = Math.max(0, 1 - angleCost / 4 / 0.5);   // cos > 0.5 (=60°) => rejet

  // côtés opposés de longueur comparable
  const d = (i, j) => Math.hypot(p[i].x - p[j].x, p[i].y - p[j].y);
  const r1 = Math.min(d(0,1), d(2,3)) / (Math.max(d(0,1), d(2,3)) + 1e-6);
  const r2 = Math.min(d(1,2), d(3,0)) / (Math.max(d(1,2), d(3,0)) + 1e-6);

  return areaRatio * rect * r1 * r2;
}

function fallbackHull(cv, contours, frameArea) {
  let bi = -1, ba = 0;
  for (let i = 0; i < contours.size(); i++) {
    const a = cv.contourArea(contours.get(i));
    if (a > ba && a < frameArea * 0.98) { ba = a; bi = i; }
  }
  if (bi < 0 || ba < frameArea * 0.12) return null;
  const hull = new cv.Mat(), approx = new cv.Mat();
  cv.convexHull(contours.get(bi), hull, false, true);
  const peri = cv.arcLength(hull, true);
  // on relâche epsilon jusqu'à obtenir 4 sommets
  for (const eps of [0.02, 0.03, 0.05, 0.08]) {
    cv.approxPolyDP(hull, approx, eps * peri, true);
    if (approx.rows === 4) {
      const pts = matToPoints(approx);
      hull.delete(); approx.delete();
      return pts;
    }
  }
  hull.delete(); approx.delete();
  return null;
}

/** Ordonne en TL, TR, BR, BL — méthode somme/différence, robuste à la rotation */
export function orderCorners(p) {
  const bySum  = [...p].sort((a, b) => (a.x + a.y) - (b.x + b.y));
  const byDiff = [...p].sort((a, b) => (a.y - a.x) - (b.y - b.x));
  return [bySum[0], byDiff[0], bySum[3], byDiff[3]];   // TL, TR, BR, BL
}
```

> Note : `orderCorners` par somme/différence est correct tant que la rotation reste < 45°. Au-delà, préférez le tri par angle autour du centroïde (`Math.atan2`) puis rotation du tableau pour que le premier point soit celui de plus petite somme.

### 3.2 Estimation du ratio réel + warp pleine résolution

Ne warpez **jamais** vers une taille arbitraire (erreur de jscanify). Déduisez les dimensions des côtés :

```js
export function warpToFullRes(cv, srcFull, quadSmall, scale, { maxSide = 2600 } = {}) {
  // quadSmall est en coords downscalées → remise à l'échelle
  const q = quadSmall.map(p => ({ x: p.x * scale, y: p.y * scale }));
  const [tl, tr, br, bl] = q;
  const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  let w = Math.round(Math.max(d(tl, tr), d(bl, br)));
  let h = Math.round(Math.max(d(tl, bl), d(tr, br)));

  // plafonne (mémoire canvas iOS) en conservant le ratio
  const f = Math.min(1, maxSide / Math.max(w, h));
  w = Math.max(64, Math.round(w * f));
  h = Math.max(64, Math.round(h * f));

  const srcTri = cv.matFromArray(4, 1, cv.CV_32FC2,
    [tl.x, tl.y, tr.x, tr.y, br.x, br.y, bl.x, bl.y]);
  const dstTri = cv.matFromArray(4, 1, cv.CV_32FC2,
    [0, 0, w, 0, w, h, 0, h]);

  const M = cv.getPerspectiveTransform(srcTri, dstTri);
  const out = new cv.Mat();
  // INTER_CUBIC : +15 % de temps, nette amélioration sur les petits caractères
  cv.warpPerspective(srcFull, out, M, new cv.Size(w, h),
    cv.INTER_CUBIC, cv.BORDER_REPLICATE, new cv.Scalar());

  srcTri.delete(); dstTri.delete(); M.delete();
  return out;   // RGBA, à supprimer par l'appelant
}
```

> Pour un A4 détecté, vous pouvez « snapper » le ratio : si `|w/h − 210/297| < 0.06`, forcez `h = Math.round(w * 297/210)`. Idem pour US Letter (8.5/11) et carte d'identité ID-1 (85.6/53.98).

### 3.3 Amélioration : correction d'ombre → CLAHE → binarisation

L'ordre compte. **Correction d'illumination AVANT toute binarisation**, sinon un adaptiveThreshold sur une zone d'ombre large produit du bruit poivre-et-sel.

```js
/**
 * Correction d'ombre par division par le fond estimé.
 * Fond = dilate + medianBlur (noyau large) => enlève le texte, garde l'éclairage.
 */
export function flattenIllumination(cv, gray) {
  const bg = new cv.Mat(), out = new cv.Mat();
  const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(15, 15));
  cv.dilate(gray, bg, k);
  k.delete();
  // medianBlur exige un ksize impair ; >5 impose du CV_8U (OK ici)
  cv.medianBlur(bg, bg, 21);

  // out = gray / bg * 255, saturé — équivalent de cv2.divide(gray, bg, scale=255)
  const g32 = new cv.Mat(), b32 = new cv.Mat(), d32 = new cv.Mat();
  gray.convertTo(g32, cv.CV_32F);
  bg.convertTo(b32, cv.CV_32F);
  cv.add(b32, new cv.Mat(b32.rows, b32.cols, cv.CV_32F, new cv.Scalar(1)), b32); // évite /0
  cv.divide(g32, b32, d32, 255.0, cv.CV_32F);
  d32.convertTo(out, cv.CV_8U);

  g32.delete(); b32.delete(); d32.delete(); bg.delete();
  return out;
}

export function enhanceDocument(cv, rgbaMat, mode = 'color') {
  const gray = new cv.Mat();
  cv.cvtColor(rgbaMat, gray, cv.COLOR_RGBA2GRAY);

  const flat = flattenIllumination(cv, gray);
  gray.delete();

  // CLAHE : contraste local. clipLimit 2.0, tiles 8x8 = valeurs éprouvées sur du document.
  const clahe = new cv.CLAHE();
  clahe.setClipLimit(2.0);
  clahe.setTilesGridSize(new cv.Size(8, 8));
  const eq = new cv.Mat();
  clahe.apply(flat, eq);
  clahe.delete(); flat.delete();

  if (mode === 'gray') return eq;                 // niveaux de gris « propre »

  if (mode === 'bw') {
    const bw = new cv.Mat();
    // blockSize impair ~ 1/40 du petit côté, borné ; C=10 typique
    let bs = Math.max(15, Math.round(Math.min(eq.cols, eq.rows) / 40));
    if (bs % 2 === 0) bs++;
    cv.adaptiveThreshold(eq, bw, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C,
                         cv.THRESH_BINARY, bs, 10);
    // ouverture 2x2 : supprime le poivre résiduel sans manger les jambages
    const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(2, 2));
    cv.morphologyEx(bw, bw, cv.MORPH_OPEN, k);
    k.delete(); eq.delete();
    return bw;
  }

  // mode 'color' : on applique la correction de luminance en conservant la chroma.
  // Conversion RGBA -> YCrCb, remplacement du Y par `eq`, retour RGBA.
  const rgb = new cv.Mat(), ycc = new cv.Mat();
  cv.cvtColor(rgbaMat, rgb, cv.COLOR_RGBA2RGB);
  cv.cvtColor(rgb, ycc, cv.COLOR_RGB2YCrCb);
  const ch = new cv.MatVector();
  cv.split(ycc, ch);
  eq.copyTo(ch.get(0));
  cv.merge(ch, ycc);
  cv.cvtColor(ycc, rgb, cv.COLOR_YCrCb2RGB);
  const out = new cv.Mat();
  cv.cvtColor(rgb, out, cv.COLOR_RGB2RGBA);
  ch.delete(); ycc.delete(); rgb.delete(); eq.delete();
  return out;
}
```

**Sauvola** : OpenCV.js ne l'expose pas (ce serait `ximgproc.niBlackThreshold`, module contrib absent du build JS). Deux options :
1. **`adaptiveThreshold` GAUSSIAN_C** après `flattenIllumination` : suffisant dans 95 % des cas, et bien plus rapide.
2. Implémenter Sauvola en JS avec `cv.integral` / `cv.integral2` (tous deux **dans la whitelist**) : `T(x,y) = m(x,y) · [1 + k·(s(x,y)/R − 1)]` avec `k=0.2`, `R=128`, fenêtre 25–35 px. Les images intégrales rendent ça O(n), ~40 ms sur 2000×2800 en JS pur. Faites-le seulement en mode « document difficile ».

### 3.4 Deskew par Hough (redressement du texte résiduel)

À appliquer **après** le warp perspectif : il corrige l'inclinaison de 0,5–5° qui subsiste quand les bords du papier ne sont pas parfaitement parallèles aux lignes de texte.

```js
export function deskew(cv, grayOrBw, { maxAngleDeg = 12 } = {}) {
  const edges = new cv.Mat(), lines = new cv.Mat();
  cv.Canny(grayOrBw, edges, 50, 150, 3, false);

  // minLineLength = 30 % de la largeur : on ne veut que les longues lignes de texte
  const minLen = Math.round(grayOrBw.cols * 0.3);
  cv.HoughLinesP(edges, lines, 1, Math.PI / 180, 100, minLen, 20);

  const angles = [];
  for (let i = 0; i < lines.rows; i++) {
    const [x1, y1, x2, y2] = [
      lines.data32S[i * 4], lines.data32S[i * 4 + 1],
      lines.data32S[i * 4 + 2], lines.data32S[i * 4 + 3],
    ];
    let a = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    if (a > 90) a -= 180;
    if (a < -90) a += 180;
    if (Math.abs(a) <= maxAngleDeg) angles.push(a);   // on ignore les verticales
  }
  edges.delete(); lines.delete();
  if (angles.length < 8) return null;                 // pas assez de preuve → on ne touche à rien

  angles.sort((a, b) => a - b);
  const median = angles[angles.length >> 1];
  if (Math.abs(median) < 0.25) return null;           // déjà droit

  return median;   // degrés, à passer à rotateBy()
}

export function rotateBy(cv, src, angleDeg) {
  const c = new cv.Point(src.cols / 2, src.rows / 2);
  const M = cv.getRotationMatrix2D(c, angleDeg, 1);
  const out = new cv.Mat();
  cv.warpAffine(src, out, M, new cv.Size(src.cols, src.rows),
    cv.INTER_CUBIC, cv.BORDER_REPLICATE, new cv.Scalar());
  M.delete();
  return out;
}
```

`HoughLines` et `HoughLinesP` sont tous deux **confirmés dans la whitelist** OpenCV.js. Variante souvent plus fiable sur du texte : `minAreaRect` sur les pixels non nuls d'une image binarisée + dilatée horizontalement (fusionne les mots en lignes) — moins coûteux que Hough.

---

## 4. Temps réel sur le flux vidéo

### 4.1 `requestVideoFrameCallback` — **disponible sur iOS**

Supporté dans Safari iOS **depuis 15.4 (mars 2022)**. Donc totalement acquis sur iOS 26.

```js
function frameLoop(video, onFrame) {
  let stop = false;
  const useRVFC = 'requestVideoFrameCallback' in HTMLVideoElement.prototype;
  const tick = (now, meta) => {
    if (stop) return;
    onFrame(now, meta);
    useRVFC ? video.requestVideoFrameCallback(tick) : requestAnimationFrame(t => tick(t, null));
  };
  useRVFC ? video.requestVideoFrameCallback(tick) : requestAnimationFrame(t => tick(t, null));
  return () => { stop = true; };
}
```
**Piège connu** : `requestVideoFrameCallback` est cassé sous Safari quand du contenu DRM est en lecture — non pertinent ici, mais gardez le fallback `requestAnimationFrame`.

### 4.2 Architecture Worker (obligatoire)

- **OffscreenCanvas** : supporté Safari 16.4+ (macOS et iOS) → OK sur iOS 26.
- **ImageBitmap** transférable : OK.
- **`MediaStreamTrackProcessor`** (Insertable Streams) : **non disponible sur WebKit** en septembre 2026. Ne comptez pas dessus.
- **WebCodecs** : `VideoFrame`/`VideoEncoder`/`VideoDecoder` depuis Safari 16.4 ; `ImageDecoder` et l'audio **seulement depuis Safari 26.0**. `ImageDecoder` peut servir à décoder rapidement un JPEG capturé en `VideoFrame` sans passer par `<img>`.

Boucle recommandée :

```js
// main thread
const worker = new Worker('/scan-worker.js', { type: 'module' });
const DETECT_SHORT = 480;             // côté court de l'aperçu envoyé au worker
let busy = false;

const stopLoop = frameLoop(video, async () => {
  if (busy) return;                    // back-pressure : on saute les frames
  busy = true;
  const scale = DETECT_SHORT / Math.min(video.videoWidth, video.videoHeight);
  const bmp = await createImageBitmap(video, {
    resizeWidth:  Math.round(video.videoWidth  * scale),
    resizeHeight: Math.round(video.videoHeight * scale),
    resizeQuality: 'low',
  });
  worker.postMessage({ type: 'detect', bmp, scale: 1 / scale }, [bmp]);  // transferable
});

worker.onmessage = (e) => {
  busy = false;
  if (e.data.type === 'quad') drawOverlay(e.data.quad, e.data.stable);
};
```

`createImageBitmap(video, {resizeWidth…})` fait le downscale **dans le compositeur**, sans repasser par un `drawImage` CPU : c'est le point clé de la perf sur iPhone.

### 4.3 Budget de performance mesurable

Sur A15 (iPhone 14 Plus), pipeline de détection à **480×640** :

| Étape | ms (ordre de grandeur) |
|---|---|
| `createImageBitmap` + resize | 2–4 |
| `cv.imread`/`matFromImageData` | 1–2 |
| `cvtColor` + `bilateralFilter(d=5)` | 6–12 |
| `morphologyEx` CLOSE 7×7 | 3–5 |
| `Canny` + `dilate` | 4–7 |
| `findContours` + scoring | 3–8 |
| **Total** | **~20–40 ms → 25–45 fps de détection** |

Règles d'or :
- **Ne dépassez jamais 640 px de côté court** pour la détection live (le coût est quadratique).
- Réutilisez les `cv.Mat` entre frames (allouez une fois, `Mat.create()` si la taille change) : l'allocation WASM est le 2ᵉ coût caché après le `bilateralFilter`.
- **Supprimez systématiquement les Mat** : la heap WASM est en `ALLOW_MEMORY_GROWTH=1`, elle ne redescend jamais. Une fuite = crash onglet en 60 s.
- Cadencez la détection à **~15 fps** (`if (now - last < 66) return;`) : c'est suffisant pour l'œil et ça libère le GPU pour l'aperçu.

### 4.4 Capture auto quand c'est stable

```js
// dans le worker ou le main thread
const hist = [];
const STABLE_N = 6;          // ~0.4 s à 15 fps
const MOVE_TOL_PX = 6;       // en coords de détection (480px) => ~1.3 %

function isStable(quad) {
  hist.push(quad);
  if (hist.length > STABLE_N) hist.shift();
  if (hist.length < STABLE_N) return false;
  const ref = hist[0];
  return hist.every(q => q.every((p, i) =>
    Math.hypot(p.x - ref[i].x, p.y - ref[i].y) < MOVE_TOL_PX));
}

// lissage de l'overlay (EMA) pour éviter le tremblement visuel
let smooth = null;
function smoothQuad(q, a = 0.35) {
  if (!smooth) return (smooth = q);
  smooth = smooth.map((p, i) => ({
    x: p.x + a * (q[i].x - p.x),
    y: p.y + a * (q[i].y - p.y),
  }));
  return smooth;
}
```

Déclenchez la capture haute résolution quand `isStable() && blurScore > seuil && lightingOK`.

---

## 5. Capture haute résolution sur iPhone 14 Plus

### 5.1 `ImageCapture.takePhoto()` : **NON supporté sur iOS**

L'API MediaStream Image Capture n'est implémentée que dans les navigateurs Chromium. Sur iOS — Safari **et** tous les navigateurs tiers, forcés d'utiliser WKWebView — `window.ImageCapture` est `undefined`. De même, les contraintes `torch`, `zoom`, `focusMode`, `iso` ne sont **pas** exposées via `track.getCapabilities()` sur iOS.

### 5.2 Ce qui marche : trois voies

**(a) `getUserMedia` + `drawImage` (voie principale, flux continu).**
Les tests récents (iOS 18.7 / Safari 26.2 sur iPhone) montrent que Safari iOS négocie **360p, 720p, 1080p et 4K (3840×2160)**. L'ancienne limite « 720p max » citée partout est **périmée**. Sur iPhone 14 Plus, visez :

```js
const stream = await navigator.mediaDevices.getUserMedia({
  audio: false,
  video: {
    facingMode: { ideal: 'environment' },
    width:  { ideal: 3840 },
    height: { ideal: 2160 },
    frameRate: { ideal: 30 },
  },
});
const track = stream.getVideoTracks()[0];
console.log(track.getSettings());   // VÉRIFIEZ TOUJOURS ce que vous avez réellement obtenu
```
Safari **ne lève pas d'erreur** si la contrainte n'est pas satisfaite : il retombe silencieusement sur le mode le plus proche. Lisez `track.getSettings().width/height` — et pas vos contraintes.

Piège iOS 18+ : sur les modèles multi-objectifs, iOS **bascule automatiquement entre les lentilles** (grand-angle / ultra grand-angle) selon la distance, ce qui change le champ de vision en plein cadrage. Utilisez `facingMode: { ideal: 'environment' }` plutôt qu'un `deviceId` exact, et prévenez l'utilisateur de ne pas approcher à moins de ~15 cm.

**Plafond réaliste par cette voie : 3840×2160 = 8,3 MP**, en 16:9 (donc recadré par rapport au capteur 4:3 12 MP). Après warp d'une feuille A4 remplissant ~80 % du cadre : **~2200×3100 px, soit ~265 dpi sur A4**. C'est largement au-dessus du seuil OCR (150–200 dpi).

**(b) `<input type="file" accept capture>` (voie « qualité maximale »).**
Ouvre l'app Appareil photo native → **12 MP pleine résolution (4032×3024)**, autofocus et HDR d'Apple. Idéal pour un bouton « photo haute qualité » en complément du scan live.

```html
<input type="file" accept="image/jpeg,image/png" capture="environment">
```
**Piège HEIC critique** : Safari convertit le HEIC natif en JPEG **uniquement si le MIME cible figure dans `accept`**. Avec `accept="image/*"`, la conversion est faite ; mais si vous incluez `image/heic` ou omettez `accept`, vous pouvez recevoir un `.heic` que `createImageBitmap` **ne décodera pas** dans tous les contextes. Écrivez explicitement `accept="image/jpeg,image/png"`.

**(c) Frame la plus nette parmi N.** Puisque `takePhoto()` n'existe pas, capturez 3–5 frames consécutives au déclenchement, calculez la variance du laplacien sur chacune, gardez la meilleure. C'est ce que font les SDK commerciaux pour compenser l'absence d'API photo.

### 5.3 Limites canvas iOS — **mises à jour en iOS 18**

- Avant iOS 18 : aire max **16 777 216 px** (= 4096×4096), quelle que soit la forme.
- **Depuis iOS 18** : la limite est passée à **8192×8192, soit 67 108 864 px d'aire**.
- **Limite mémoire totale canvas** distincte et toujours active : de l'ordre de **384 Mo** (dépend de l'appareil/version). Un canvas 4032×3024 RGBA = 48,8 Mo. Trois pages ouvertes simultanément → crash.

Règles :
- Libérez chaque canvas hors écran après usage : `c.width = 0; c.height = 0;`
- Plafonnez le warp à **2600 px sur le grand côté** (cf. `maxSide` plus haut) : ~2600×3670 = 9,5 MP, 38 Mo RGBA, confortable.
- Ne gardez jamais plus d'**une** page décompressée en mémoire : stockez chaque page en `Blob` JPEG dans IndexedDB dès qu'elle est traitée.

---

## 6. Contrôle qualité : flou et éclairage

```js
/**
 * Variance du Laplacien — mesure de netteté.
 * IMPORTANT : la valeur dépend de la RÉSOLUTION et du CONTRASTE.
 * Normalisez toujours sur une taille fixe avant de comparer à un seuil.
 */
export function blurScore(cv, rgbaMat, norm = 480) {
  const small = new cv.Mat(), gray = new cv.Mat(), lap = new cv.Mat();
  const s = norm / Math.min(rgbaMat.cols, rgbaMat.rows);
  cv.resize(rgbaMat, small,
    new cv.Size(Math.round(rgbaMat.cols * s), Math.round(rgbaMat.rows * s)),
    0, 0, cv.INTER_AREA);
  cv.cvtColor(small, gray, cv.COLOR_RGBA2GRAY);
  cv.Laplacian(gray, lap, cv.CV_64F, 3, 1, 0, cv.BORDER_DEFAULT);

  const mean = new cv.Mat(), std = new cv.Mat();
  cv.meanStdDev(lap, mean, std);
  const variance = std.doubleAt(0, 0) ** 2;

  small.delete(); gray.delete(); lap.delete(); mean.delete(); std.delete();
  return variance;
}

/** Diagnostic d'éclairage à partir de l'histogramme du gris */
export function lightingScore(cv, rgbaMat) {
  const gray = new cv.Mat(), mean = new cv.Mat(), std = new cv.Mat();
  cv.cvtColor(rgbaMat, gray, cv.COLOR_RGBA2GRAY);
  cv.meanStdDev(gray, mean, std);
  const mu = mean.doubleAt(0, 0), sigma = std.doubleAt(0, 0);

  // % de pixels brûlés (>250) et bouchés (<5)
  const hi = new cv.Mat(), lo = new cv.Mat();
  cv.threshold(gray, hi, 250, 255, cv.THRESH_BINARY);
  cv.threshold(gray, lo, 5, 255, cv.THRESH_BINARY_INV);
  const n = gray.rows * gray.cols;
  const clipHi = cv.countNonZero(hi) / n, clipLo = cv.countNonZero(lo) / n;

  gray.delete(); mean.delete(); std.delete(); hi.delete(); lo.delete();

  let issue = null;
  if (mu < 70)            issue = 'too_dark';
  else if (mu > 215)      issue = 'too_bright';
  else if (sigma < 28)    issue = 'low_contrast';
  else if (clipHi > 0.06) issue = 'glare';        // reflet / flash
  else if (clipLo > 0.15) issue = 'shadow';
  return { mean: mu, std: sigma, clipHi, clipLo, issue };
}
```

**Seuils à calibrer sur vos propres photos** (valeurs de départ, image normalisée à 480 px de côté court) :
- `variance < 60` → « Image floue, tenez le téléphone immobile »
- `60 ≤ variance < 120` → acceptable, signalé en orange
- `variance ≥ 120` → net

Pour un papier **blanc et peu texturé**, la variance est naturellement basse : calculez-la **après le warp, sur la zone de texte uniquement**, pas sur le cadre global. Une alternative plus stable : **énergie de Tenengrad** (somme de `Sobel_x² + Sobel_y²` au-dessus d'un seuil) — moins sensible au bruit du capteur en basse lumière que le laplacien.

Retour utilisateur concret à afficher en overlay :
| Détection | Message |
|---|---|
| `!quad` | « Placez le document sur un fond contrasté » |
| `quad.area < 25 %` | « Rapprochez-vous » |
| `variance < 60` | « Stabilisez — c'est flou » |
| `issue === 'glare'` | « Reflet détecté, inclinez légèrement » |
| `issue === 'too_dark'` | « Trop sombre — plus de lumière » (⚠️ pas de torche accessible en JS sur iOS) |
| stable + net + éclairé | Compte à rebours 3-2-1 puis capture auto |

---

## 7. Multipage → PDF côté client

### 7.1 pdf-lib vs jsPDF — l'état réel en 2026

| | **pdf-lib** `1.17.1` | **@cantoo/pdf-lib** `2.11.1` | **jsPDF** `4.2.1` |
|---|---|---|---|
| Dernière publication | **2021-11-06** (5 ans !) | **2026-09-15** | 2026-03-17 |
| Licence | MIT | MIT | MIT |
| Taille npm | 19,5 Mo | ~ idem | 30,2 Mo |
| API | identique | **identique à pdf-lib** | différente |
| Chiffrement/mots de passe | non | oui (`loadOptions`) | partiel |
| Dépendances | pako, tslib | idem | @babel/runtime, fflate, fast-png |

**`pdf-lib` original est de facto abandonné (aucune release depuis novembre 2021).** Pour un projet neuf en 2026 : utilisez **`@cantoo/pdf-lib`**, fork activement maintenu, API et docs identiques.

**Recommandation pour ce cas précis** : vous n'avez qu'à empiler des images JPEG, une par page. **jsPDF est plus simple et plus léger à l'usage** ; `@cantoo/pdf-lib` devient supérieur dès que vous voulez fusionner/modifier des PDF existants, ou chiffrer.

### 7.2 Code réel — assemblage JPEG multipage

```js
import { PDFDocument } from '@cantoo/pdf-lib';

const A4 = { w: 595.28, h: 841.89 };   // points PDF (72 dpi)

/**
 * @param {Blob[]} jpegBlobs  pages déjà traitées, en JPEG
 * @param {{title?:string, fitToA4?:boolean}} meta
 */
export async function buildPdf(jpegBlobs, meta = {}) {
  const pdf = await PDFDocument.create();
  pdf.setTitle(meta.title ?? 'Scan');
  pdf.setCreator('Papers PWA');
  pdf.setProducer('Papers PWA');
  pdf.setCreationDate(new Date());

  for (const blob of jpegBlobs) {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const img = await pdf.embedJpg(bytes);   // embedJpg = AUCUNE recompression

    let pw, ph;
    if (meta.fitToA4) {
      const s = Math.min(A4.w / img.width, A4.h / img.height);
      pw = A4.w; ph = A4.h;
      const dw = img.width * s, dh = img.height * s;
      const page = pdf.addPage([pw, ph]);
      page.drawImage(img, { x: (pw - dw) / 2, y: (ph - dh) / 2, width: dw, height: dh });
    } else {
      // page à la taille exacte de l'image, à 200 dpi
      const dpi = 200;
      pw = img.width  * 72 / dpi;
      ph = img.height * 72 / dpi;
      const page = pdf.addPage([pw, ph]);
      page.drawImage(img, { x: 0, y: 0, width: pw, height: ph });
    }
  }
  return new Blob([await pdf.save({ useObjectStreams: true })],
                  { type: 'application/pdf' });
}
```

> **Point clé** : `embedJpg` insère le flux JPEG **tel quel** (filtre `DCTDecode`). Aucune recompression, aucune décompression : c'est rapide et la taille du PDF ≈ somme des JPEG + ~2 Ko. `embedPng` au contraire re-zippe en Flate et explose la taille sur des photos. **Toujours JPEG, jamais PNG, sauf mode noir & blanc pur.**

### 7.3 Encodage : JPEG vs WebP sur iOS

**Piège vérifié** : `canvas.toBlob('image/webp')` sur Safari **ne lève aucune erreur et ne renvoie pas `null`** — la spec HTML impose un fallback silencieux vers `image/png`. Safari n'a ajouté l'encodage WebP canvas que tardivement (≥ 16.4). Et de toute façon, **WebP n'est pas embarquable nativement dans un PDF** (il faudrait le décoder puis le réencoder). Détection obligatoire si vous y tenez :

```js
async function encode(canvas, type = 'image/jpeg', quality = 0.82) {
  const blob = await new Promise(r => canvas.toBlob(r, type, quality));
  if (blob.type !== type) console.warn(`Fallback silencieux: ${type} -> ${blob.type}`);
  return blob;
}
```

**Conclusion : JPEG qualité 0.80–0.85 pour tout.** Cibles de taille par page A4 warpée à ~2200×3100 :
- couleur, q=0.82 → **220–400 Ko**
- gris, q=0.85 → **130–250 Ko**
- N&B binarisé → JPEG est mauvais (artefacts de ringing autour du texte). Utilisez **PNG** (~80–150 Ko grâce aux 2 couleurs) ou, idéalement, du **CCITT G4 / JBIG2** — mais aucune lib JS grand public ne les produit. En pratique : gardez le mode « gris » plutôt que « N&B pur » pour le PDF, et réservez le N&B à l'aperçu écran.

Document de 10 pages couleur → **2,5–4 Mo**. Objectif raisonnable : **< 500 Ko/page**.

### 7.4 PDF/A — non, pas côté client

PDF/A exige un profil ICC de sortie embarqué (sRGB IEC61966-2.1, ~3 Ko minimum mais souvent 500 Ko+), des métadonnées **XMP** conformes, un `OutputIntent`, toutes les polices embarquées, et pas de transparence. `@cantoo/pdf-lib` permet techniquement d'injecter un `OutputIntent` et du XMP brut, mais **aucune lib JS ne garantit la conformité** et il n'existe pas de validateur (veraPDF) côté navigateur.

**Faites-le côté serveur** si c'est une exigence légale : `Ghostscript -dPDFA=2 -dPDFACompatibilityPolicy=1` avec un fichier `PDFA_def.ps`, ou `ocrmypdf --output-type pdfa`. C'est une commande à lancer depuis un job Laravel.

Côté client, contentez-vous d'un PDF 1.7 propre avec métadonnées correctes.

---

## 8. Alternative serveur (Laravel / PHP 8.5)

### 8.1 Contexte stack vérifié

- **PHP 8.5.10** est la dernière stable (27 août 2026).
- **Laravel 13** est sorti le **17 mars 2026** et supporte **PHP 8.3, 8.4 et 8.5**. Laravel 12 exige PHP ≥ 8.2. Pour PHP 8.5, ciblez **Laravel 13**.

### 8.2 Comparatif

| | **Client (WASM)** | **Serveur (Imagick)** | **Serveur (php-opencv)** |
|---|---|---|---|
| Latence perçue | **~50 ms**, aperçu live possible | 1–4 s aller-retour + upload | idem |
| Upload | seulement le résultat (300 Ko) | l'original 12 MP (4–6 Mo) | idem |
| Temps réel / overlay | **oui** | impossible | impossible |
| Confidentialité | traitement local | photo brute chez vous | idem |
| Qualité algorithmique | bonne | bonne (`-deskew`, `-lat`) | **excellente** |
| Coût infra | 0 | CPU + bande passante × N utilisateurs | idem |
| Maintenance | build WASM à refaire | `apt install imagemagick` | **extension PECL fragile**, compilation OpenCV, casse à chaque version PHP |
| Déterminisme | variable selon appareil | **identique pour tous** | identique |

**Recommandation : hybride.**
1. **Client** : détection live + warp + amélioration + JPEG → c'est ce que l'utilisateur voit et valide. Obligatoire pour l'UX de scan.
2. **Serveur (Laravel queue job)** : re-traitement « best effort » après upload — deskew fin, normalisation, génération du PDF/A, OCR de secours. Utilisez **Imagick** (extension PHP stable, packagée partout), pas `php-opencv` (extension PECL peu maintenue, compilation d'OpenCV requise, source d'ennuis en production).

```php
// app/Jobs/NormalizeScan.php — Laravel 13 / PHP 8.5
use Imagick;

final class NormalizeScan implements ShouldQueue
{
    public function __construct(private readonly string $path) {}

    public function handle(): void
    {
        $im = new Imagick(Storage::path($this->path));
        $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $im->deskewImage(0.4 * Imagick::getQuantum());   // redressement Radon
        $im->trimImage(0.1 * Imagick::getQuantum());     // recadre les marges noires du warp
        $im->setImagePage(0, 0, 0, 0);
        // égalisation locale — équivalent de -lat 25x25+10%
        $im->adaptiveThresholdImage(25, 25, 0.10 * Imagick::getQuantum());
        $im->setImageCompressionQuality(85);
        $im->setImageFormat('jpeg');
        $im->stripImage();                                // enlève EXIF/GPS (RGPD)
        Storage::put($this->path, $im->getImageBlob());
        $im->clear();
    }
}
```

`deskewImage()` d'ImageMagick utilise une transformée de Radon — c'est le meilleur deskew disponible sans écrire de code. **Attention** : Imagick + PDF exige Ghostscript **et** une policy `/etc/ImageMagick-7/policy.xml` autorisant `PDF` (bloquée par défaut depuis CVE-2018-16509).

---

## 9. Qualité d'image en entrée d'un modèle de vision OpenAI

⚠️ **La tarification vision d'OpenAI a changé en 2026.** Les modèles récents sont **patch-based (32×32 px)**, plus « tile-based 512×512 ».

### 9.1 Modèles et formules (doc officielle `developers.openai.com`, septembre 2026)

**Modèles patch-based** : `gpt-6-astra`, `gpt-5.6-sol`, `gpt-5.6-terra`, `gpt-5.6-luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-4.1-mini`.
**Modèles tile-based (legacy)** : `gpt-5.1`, `gpt-4o`, `gpt-4o-mini`, `gpt-4.1`.

Formule patch-based :
```
patch_count      = ceil(width/32) × ceil(height/32)
shrink_factor    = sqrt((32² × patch_budget) / (width × height))
billable_tokens  = ceil(patch_count_after_resize × model_multiplier)
```
- **multiplier = 1,2** pour tous les modèles ci-dessus **sauf `gpt-4.1-mini` = 1,62**.
- **patch_budget** : `detail:"high"` → **2 500 patches** ; `detail:"original"` → **10 000 patches** (gpt-5.4/5.5).
- **Rejet dur au-delà de 30 000 patches** — l'image n'est PAS redimensionnée, la requête échoue.
- `detail:"low"` → 512×512. **La doc précise explicitement que `low` ne consomme pas toujours moins de tokens que `high`** sur les modèles patch-based. Ne l'utilisez pas pour économiser.

Limites d'entrée : **512 Mo de payload total par requête**, jusqu'à **1 500 images par requête**. Formats : **PNG, JPEG, WebP, GIF non animé**.

### 9.2 Résolution optimale — calcul concret

`patch_budget = 2500` avec `detail:"high"` correspond à une aire de `2500 × 32² = 2 560 000 px`. Pour un ratio A4 (1:1,414) :
```
h/w = 1.414  →  w × 1.414w = 2 560 000  →  w ≈ 1346, h ≈ 1903
```
**→ Toute image A4 envoyée au-delà de ~1350×1900 sera downscalée par OpenAI de toute façon.** L'envoyer en 2600×3670 ne fait que gonfler votre upload et votre latence, **sans un seul token ni un seul pixel de qualité supplémentaire**.

**Faites le downscale vous-même, en local, avec `INTER_AREA`** — le rééchantillonnage d'OpenAI est un simple resize générique, alors que `INTER_AREA` préserve nettement mieux la lisibilité des petits caractères.

### 9.3 Recettes recommandées

| Cas | Résolution à envoyer | Couleur | Format | `detail` | Tokens ≈ |
|---|---|---|---|---|---|
| **Facture / courrier standard** | **1350×1900** (A4) | **gris** ou couleur | JPEG q=0.85 | `high` | ~2 500 × 1,2 = **3 000** |
| Ticket de caisse long | 900×2400 | gris | JPEG q=0.85 | `high` | ~2 550 |
| **Texte dense, petites polices, tableaux** | **2200×3100** | gris | JPEG q=0.90 | **`original`** | ~10 000 × 1,2 = **12 000** |
| Tri / classification seule (quel type de doc ?) | 700×990 | gris | JPEG q=0.75 | `high` | ~830 |
| Carte d'identité / carte de visite | 1200×760 | **couleur** | JPEG q=0.88 | `high` | ~1 150 |

### 9.4 N&B ou couleur ?

**N'envoyez JAMAIS l'image binarisée (N&B 1-bit) au modèle.** Trois raisons :
1. Le modèle est entraîné sur des photos naturelles ; le texte binarisé avec artefacts de seuillage lui pose plus de problèmes qu'un gris propre.
2. Vous perdez toute information de mise en forme (surlignage, tampons, signatures, logos, encre bleue vs noire).
3. Ça ne réduit ni le nombre de patches ni le coût — **le coût dépend uniquement des dimensions, pas du nombre de canaux**.

**Envoyez du niveau de gris après `flattenIllumination` + CLAHE.** Le gris JPEG est ~30 % plus léger que la couleur à qualité égale, et le modèle y lit aussi bien.
**Exception : gardez la couleur** quand la couleur porte du sens — tampons, mentions « PAYÉ » en rouge, surlignage, cartes d'identité, logos pour l'identification d'émetteur.

### 9.5 Compression

- **q = 0.85** est le point d'équilibre. En dessous de 0.70, les artefacts de bloc 8×8 dégradent mesurablement la lecture des caractères < 10 px.
- Ne descendez **jamais** sous q=0.75 pour du texte.
- Encodez en `image/jpeg` : c'est le seul format universellement encodable par `canvas.toBlob` sur iOS **et** accepté par OpenAI.

### 9.6 Envoi et coût

Préférez l'**URL signée** (S3 / `Storage::temporaryUrl`) au base64 : le base64 gonfle le payload de 33 % et vous facture le transfert deux fois (client → Laravel → OpenAI). Faites uploader le client directement vers l'objet store, puis passez l'URL au modèle. Ça évite aussi de saturer PHP-FPM avec des payloads de plusieurs Mo.

**Pour du multipage** : envoyez les pages dans **un seul appel** (jusqu'à 1 500 images par requête). Le modèle corrèle bien mieux les informations réparties sur plusieurs pages (numéro de facture page 1, échéance page 3), et vous économisez le prompt système répété. Attention toutefois au budget : 10 pages × 3 000 tokens = 30 000 tokens d'entrée, à quoi s'ajoute le raisonnement.


## Faits clés vérifiés

- OpenCV 5.0.0 est sorti le 2026-06-06 ; la derniere branche 4.x est 4.14.0 (2026-07-19). Les releases GitHub d'OpenCV ne contiennent AUCUN artefact opencv.js : il faut builder soi-meme ou utiliser docs.opencv.org/<version>/opencv.js.
- Taille mesuree : @techstark/opencv-js@5.0.0-release.1 dist/opencv.js = 13 298 869 octets (13,3 Mo), build SINGLE_FILE avec WASM en base64 -> impossible d'utiliser WebAssembly.instantiateStreaming. Licence Apache-2.0.
- jscanify@1.4.3 embarque son propre src/opencv.js de 8 980 607 octets (8,98 Mo). Le fichier navigateur src/jscanify.js ne fait que 7 585 octets.
- Le build officiel opencv.js utilise -s MODULARIZE=1 -s EXPORT_NAME='cv' -s TOTAL_MEMORY=128MB -s WASM_MEM_MAX=1GB -s ALLOW_MEMORY_GROWTH=1 (verifie dans modules/js/CMakeLists.txt de la branche 4.x). Donc `cv` est une FACTORY qui renvoie une Promise : `const cv = await window.cv({locateFile: () => wasmUrl})`.
- build_js.py accepte : --build_wasm, --disable_single_file, --simd, --threads, --config <fichier.py>, --build_flags, --cmake_option, --enable_exception, --clean_build_dir (verifie dans le source platforms/js/build_js.py branche 4.x).
- La whitelist officielle opencv_js.config.py (4.x) contient bien : adaptiveThreshold, approxPolyDP, arcLength, bilateralFilter, Canny, contourArea, convexHull, createCLAHE, cvtColor, dilate, erode, findContours, GaussianBlur, getPerspectiveTransform, getRotationMatrix2D, getStructuringElement, HoughLines, HoughLinesP, integral, integral2, isContourConvex, Laplacian, medianBlur, minAreaRect, morphologyEx, pyrDown, resize, Sobel, threshold, warpAffine, warpPerspective, et la classe CLAHE avec apply/setClipLimit/setTilesGridSize.
- jscanify : npm 1.4.3 publie le 2026-07-20, licence MIT, depot puffinsoft/jscanify. Package de 30,4 Mo decompresses. main = src/jscanify-node.js. Dependances npm : canvas@^3.2.3 et jsdom@^29.1.1 (polluent tout bundle navigateur).
- requestVideoFrameCallback est supporte dans Safari iOS depuis iOS 15.4 (mars 2022). Acquis sur iOS 26.
- OffscreenCanvas est supporte dans Safari 16.4+ (macOS ET iOS). ImageBitmap est transferable vers un Worker.
- ImageCapture / takePhoto() N'EST PAS supporte sur iOS : ni Safari ni les navigateurs tiers (tous forces sur WKWebView). Les contraintes torch/zoom/focusMode/iso ne sont pas exposees via track.getCapabilities() sur iOS.
- Tests recents (iOS 18.7 / Safari 26.2 sur iPhone) : getUserMedia negocie 360p, 720p, 1080p ET 4K (3840x2160). L'ancienne limite documentee a 720p max est PERIMEE. Safari ne leve pas d'erreur si la contrainte n'est pas satisfaite : il retombe silencieusement -> lire track.getSettings().
- Limite canvas iOS : aire max 16 777 216 px (4096x4096) avant iOS 18 ; depuis iOS 18 la limite est passee a 8192x8192 = 67 108 864 px d'aire. Une limite MEMOIRE totale canvas distincte (~384 Mo) reste active.
- WASM SIMD est supporte par WebKit depuis Safari 16.4. WASM threads exigent SharedArrayBuffer donc cross-origin isolation (COOP: same-origin + COEP: require-corp|credentialless) : a eviter dans une PWA car cela casse les chargements tiers. Tester self.crossOriginIsolated.
- WebCodecs sur Safari : VideoDecoder/VideoEncoder/VideoFrame depuis 16.4 ; ImageDecoder + AudioEncoder/AudioDecoder seulement depuis Safari 26.0. MediaStreamTrackProcessor (Insertable Streams) N'EST PAS disponible sur WebKit.
- pdf-lib original (Hopding) est bloque a la version 1.17.1 publiee le 2021-11-06 : abandonne de facto. Le fork actif est @cantoo/pdf-lib@2.11.1 publie le 2026-09-15, meme API, meme licence MIT, + support des PDF chiffres.
- jsPDF est en 4.2.1 (2026-03-17), MIT, deps @babel/runtime + fflate + fast-png. cropperjs est en 2.2.0 (2026-08-23), MIT, 473 Ko.
- canvas.toBlob('image/webp') sur Safari retombe SILENCIEUSEMENT sur image/png (comportement impose par la spec HTML : pas d'erreur, pas de null). Verifier blob.type apres coup. Le parametre quality est ignore en PNG.
- iOS convertit le HEIC natif en JPEG seulement si le type MIME cible figure dans l'attribut accept du file input (Safari 17+). Utiliser accept="image/jpeg,image/png" explicitement.
- OpenAI 2026 : les modeles vision recents sont PATCH-BASED (32x32 px), plus tile-based. patch_count = ceil(w/32) x ceil(h/32) ; billable_tokens = ceil(patch_count x multiplier). Multiplier = 1,2 pour gpt-6-astra, gpt-5.6-sol/terra/luna, gpt-5.5, gpt-5.4(+mini/nano), gpt-5.2 ; 1,62 pour gpt-4.1-mini.
- OpenAI patch_budget : detail:"high" = 2 500 patches ; detail:"original" = 10 000 patches (gpt-5.4/5.5). Rejet dur de la requete au-dela de 30 000 patches (l'image N'est PAS redimensionnee automatiquement pour rentrer).
- OpenAI limites d'entree : 512 Mo de payload total par requete, jusqu'a 1 500 images par requete, formats PNG/JPEG/WebP/GIF non anime. detail:"original" est explicitement recommande par la doc pour l'OCR et les taches necessitant des coordonnees precises.
- 2 500 patches = 2 560 000 px d'aire. Pour un ratio A4 cela correspond a ~1346x1903 px : envoyer plus grand en detail:"high" est downscale par OpenAI, donc inutile.
- Modeles OpenAI tile-based legacy (ancienne formule 85 tokens de base + 170/tuile 512px) : gpt-5.1, gpt-4o, gpt-4o-mini, gpt-4.1.
- PHP 8.5.10 est la derniere stable (2026-08-27). Laravel 13 est sorti le 2026-03-17 et supporte PHP 8.3 / 8.4 / 8.5. Laravel 12 exige PHP >= 8.2.
- Safari 26.6 publie le 2026-07-27. Apple a aligne les numeros de version sur l'annee a la WWDC25 (iOS 26 / Safari 26). Safari 26.2 apportait 62 nouvelles fonctionnalites dont la Navigation API.
- opencv-document-scanner@1.2.2 (tony-xlh/xulihang, MIT, 2025-09-11, 15,6 Ko) : downscale a 720 px de hauteur avant detection puis remise a l'echelle des coins, et estime la taille de sortie du warp a partir des distances entre coins - deux bonnes idees a reprendre. Mais couple au viewer commercial Dynamsoft DDV.

## Pièges / ce qui ne marche PAS

- jscanify a un pipeline ALGORITHMIQUEMENT FAUX (source lu integralement) : il applique cv.Canny sur l'image RGBA brute, PUIS un GaussianBlur sur la carte d'aretes, PUIS un threshold Otsu sur ce flou. L'ordre correct est blur -> Canny. Ne pas l'utiliser tel quel.
- jscanify ne fait AUCUN approxPolyDP, aucun test de convexite, aucun filtre d'aire : il prend le plus grand contour par aire. Sur un fond texture ou un bureau en bois, il selectionne le cadre de l'image entiere.
- jscanify.getCornerPoints prend le centre du minAreaRect puis, dans chaque quadrant, le point le plus eloigne du centre. Casse des que le document est incline de plus de ~20 deg, decentre, ou depasse d'un bord. opencv-document-scanner reprend exactement le meme bug.
- jscanify extractPaper fait un early return sans supprimer contours/hierarchy -> fuite memoire dans la heap WASM. La heap est en ALLOW_MEMORY_GROWTH=1 : elle ne redescend JAMAIS. Une fuite = crash de l'onglet en ~60 s.
- jscanify depend de la variable GLOBALE `cv` : aucune injection d'instance possible, donc incompatible avec un Web Worker propre ou un bundler moderne. Ses deps npm canvas + jsdom cassent tout bundle navigateur -> importer directement jscanify/src/jscanify.js.
- Le README de jscanify annonce 'glare suppression' et 'multi-colored paper support' depuis la 1.3.0 : AUCUN code correspondant n'existe dans src/jscanify.js de la version 1.4.3. L'en-tete du fichier dit encore 'v1.4.0' dans la 1.4.3.
- cropperjs ne fait AUCUNE detection ni correction de perspective : c'est du recadrage rectangulaire via Web Components. Il ne gere meme pas les quadrilateres libres. Inutile pour l'ajustement manuel des 4 coins.
- scanner-js n'a rien a voir avec la vision par ordinateur. docscan et opencv-tools sont des packages fantomes/abandonnes. Asprise ScannerJS pilote des scanners TWAIN physiques : hors sujet total pour une PWA iPhone.
- OpenCV.js n'expose PAS fastNlMeansDenoising : le module `photo` de la whitelist ne contient que du HDR (createMergeMertens, Tonemap...) et inpaint. Se rabattre sur bilateralFilter / medianBlur / morphologyEx pour le debruitage.
- OpenCV.js n'expose PAS Sauvola / niBlackThreshold (module ximgproc contrib absent du build JS). Soit adaptiveThreshold GAUSSIAN_C apres correction d'illumination, soit implementer Sauvola en JS avec cv.integral / cv.integral2 (tous deux dans la whitelist).
- Le build SINGLE_FILE (celui de @techstark/opencv-js et de la plupart des CDN) encode le .wasm en base64 DANS le JS : +33 % de volume et WebAssembly.instantiateStreaming devient impossible. Toujours builder avec --disable_single_file.
- Un binaire compile avec --simd CRASHE sur un moteur sans SIMD. Servir deux builds et faire une detection de capacite (wasm-feature-detect) avant de choisir.
- Activer les threads WASM force COEP: require-corp, ce qui CASSE les chargements d'images et d'API tierces dans une PWA. Rester mono-thread + Web Worker.
- ImageCapture.takePhoto() est absent sur iOS dans TOUS les navigateurs (WKWebView impose). Pas de torche, pas de zoom, pas de focusMode via JS sur iPhone : impossible d'aider l'utilisateur en basse lumiere autrement qu'avec un message.
- Safari ne leve AUCUNE erreur quand une contrainte getUserMedia n'est pas satisfaite : il retombe silencieusement sur le mode le plus proche. Il faut lire track.getSettings().width/height et non ses propres contraintes.
- Sur iOS 18+, les iPhone multi-objectifs BASCULENT automatiquement entre grand-angle et ultra grand-angle selon la distance, ce qui change le champ de vision en cours de cadrage. Utiliser facingMode:{ideal:'environment'} plutot qu'un deviceId exact et prevenir l'utilisateur de ne pas approcher a moins de ~15 cm.
- La limite MEMOIRE canvas iOS (~384 Mo) est distincte de la limite d'aire et reste active meme apres iOS 18. Un canvas 4032x3024 RGBA = 48,8 Mo : trois pages simultanees suffisent a crasher. Liberer chaque canvas hors ecran avec c.width=0; c.height=0.
- canvas.toBlob('image/webp') sur Safari renvoie un PNG sans aucune erreur ni null. Le parametre quality est alors ignore. Et WebP n'est de toute facon pas embarquable nativement dans un PDF.
- pdf-lib original n'a plus eu de release depuis novembre 2021 : abandonne. Utiliser @cantoo/pdf-lib (meme API).
- embedPng dans un PDF re-zippe l'image en Flate et fait exploser la taille sur des photos. Toujours embedJpg (filtre DCTDecode : le flux JPEG est insere tel quel, sans recompression).
- Le JPEG est mauvais sur une image binarisee N&B (ringing autour du texte). Preferer PNG, ou mieux : garder le mode gris dans le PDF et reserver le N&B a l'apercu ecran.
- PDF/A est INFAISABLE de facon fiable cote client : profil ICC embarque, XMP conforme, OutputIntent, polices embarquees, pas de transparence - et aucun validateur (veraPDF) cote navigateur. Le faire cote serveur avec Ghostscript -dPDFA=2 ou ocrmypdf --output-type pdfa.
- Ne JAMAIS envoyer l'image binarisee 1-bit au modele de vision : le modele est entraine sur des photos naturelles, on perd toute info de mise en forme (tampons, surlignage, encre coloree, signatures), et cela ne reduit PAS le cout (le cout depend uniquement des dimensions, pas du nombre de canaux).
- detail:"low" chez OpenAI ne consomme PAS toujours moins de tokens que "high" sur les modeles patch-based : la doc le precise explicitement. Ne pas l'utiliser pour economiser.
- Au-dela de 30 000 patches, l'API OpenAI REJETTE la requete : l'image n'est pas redimensionnee automatiquement pour rentrer dans la limite.
- accept="image/*" sur un file input iOS declenche la conversion HEIC->JPEG, mais inclure image/heic (ou omettre accept) peut renvoyer un .heic que createImageBitmap ne decodera pas. Ecrire explicitement accept="image/jpeg,image/png".
- La variance du laplacien depend de la RESOLUTION et du CONTRASTE : toujours normaliser sur une taille fixe avant de comparer a un seuil. Sur du papier blanc peu texture elle est naturellement basse : la calculer apres le warp, sur la zone de texte, pas sur le cadre global.
- Imagick + PDF exige Ghostscript ET une policy /etc/ImageMagick-7/policy.xml autorisant PDF (bloquee par defaut depuis CVE-2018-16509).
- php-opencv est une extension PECL peu maintenue qui exige de compiler OpenCV et casse a chaque version de PHP. Preferer Imagick (stable, package partout) pour le traitement serveur.
- orderCorners par somme/difference n'est correct que tant que la rotation reste < 45 deg. Au-dela, trier par angle autour du centroide (Math.atan2) puis faire tourner le tableau.

## Extraits de code de référence

### Extrait 1

// Loader OpenCV.js robuste : le build officiel est MODULARIZE=1 / EXPORT_NAME='cv'
// => `cv` est une FACTORY qui renvoie une Promise, pas un objet pret a l'emploi.
export async function loadCv(jsUrl = '/wasm/opencv.js', wasmUrl = '/wasm/opencv_js.wasm') {
  if (globalThis.__cv) return globalThis.__cv;
  importScripts?.(jsUrl) ?? await import(/* @vite-ignore */ jsUrl);

  const factory = globalThis.cv;
  let cv;
  if (typeof factory === 'function') {
    cv = await factory({ locateFile: () => wasmUrl });     // MODULARIZE=1
  } else {
    cv = factory;
    if (!cv.Mat) await new Promise(r => { cv.onRuntimeInitialized = r; });
  }
  globalThis.__cv = cv;
  return cv;
}

### Extrait 2

# Build custom OpenCV.js reduit (~2 Mo wasm, ~700 Ko brotli) — flags verifies dans build_js.py
git clone --depth 1 --branch 4.14.0 https://github.com/opencv/opencv.git
cd opencv

docker run --rm -v "$PWD":/src -u $(id -u):$(id -g) emscripten/emsdk:4.0.20 \
  emcmake python3 ./platforms/js/build_js.py build_wasm \
    --build_wasm \
    --disable_single_file \
    --simd \
    --config /src/doc_scan.config.py \
    --build_flags="-Oz -flto"
# sortie : build_wasm/bin/opencv.js + build_wasm/bin/opencv_js.wasm

### Extrait 3

# doc_scan.config.py — whitelist minimale pour un scanner de documents
# (toutes ces fonctions sont confirmees presentes dans opencv_js.config.py branche 4.x)
core = {
    '': ['absdiff','add','addWeighted','bitwise_not','convertScaleAbs','copyMakeBorder',
         'countNonZero','divide','flip','LUT','max','mean','meanStdDev','merge','min',
         'minMaxLoc','multiply','normalize','perspectiveTransform','rotate','split',
         'subtract','transpose'],
    'Algorithm': [],
}
imgproc = {
    '': ['adaptiveThreshold','approxPolyDP','arcLength','bilateralFilter','blur',
         'boundingRect','Canny','contourArea','convexHull','createCLAHE','cvtColor',
         'dilate','drawContours','erode','filter2D','findContours','GaussianBlur',
         'getPerspectiveTransform','getRotationMatrix2D','getStructuringElement',
         'HoughLinesP','isContourConvex','Laplacian','medianBlur','minAreaRect',
         'morphologyEx','pyrDown','resize','Sobel','threshold','warpAffine',
         'warpPerspective'],
    'CLAHE': ['apply','collectGarbage','setClipLimit','setTilesGridSize'],
}
white_list = makeWhiteList([core, imgproc])

### Extrait 4

// detect.js — detection robuste du quadrilatere (dans le Worker, sur image downscalee a ~480-640px)
export function detectQuad(cv, src) {
  const W = src.cols, H = src.rows, area = W * H;
  const gray = new cv.Mat(), work = new cv.Mat(), edges = new cv.Mat();
  const contours = new cv.MatVector(), hierarchy = new cv.Mat();
  const trash = [gray, work, edges, contours, hierarchy];

  try {
    cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);

    // 1) debruitage preservant les bords (d=5 pour le temps reel)
    cv.bilateralFilter(gray, work, 5, 40, 40, cv.BORDER_DEFAULT);

    // 2) fermeture morphologique : bouche le texte, ne laisse que la silhouette de page
    const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(7, 7));
    cv.morphologyEx(work, work, cv.MORPH_CLOSE, k);
    k.delete();

    // 3) Canny a seuils AUTO derives d'Otsu (bien plus stable que 50/200 en dur)
    const otsuTmp = new cv.Mat();
    const high = cv.threshold(work, otsuTmp, 0, 255, cv.THRESH_BINARY + cv.THRESH_OTSU);
    otsuTmp.delete();
    cv.Canny(work, edges, high * 0.5, high, 3, false);

    // 4) dilatation 1px : referme les aretes discontinues
    const k2 = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3));
    cv.dilate(edges, edges, k2);
    k2.delete();

    cv.findContours(edges, contours, hierarchy, cv.RETR_LIST, cv.CHAIN_APPROX_SIMPLE);

    let best = null, bestScore = 0;
    for (let i = 0; i < contours.size(); i++) {
      const c = contours.get(i);
      const cArea = cv.contourArea(c);
      if (cArea < area * 0.12 || cArea > area * 0.98) { c.delete(); continue; }

      const peri = cv.arcLength(c, true);
      const approx = new cv.Mat();
      cv.approxPolyDP(c, approx, 0.02 * peri, true);   // 2 % : valeur eprouvee

      if (approx.rows === 4 && cv.isContourConvex(approx)) {
        const pts = matToPoints(approx);
        const s = quadScore(pts, cArea, area);
        if (s > bestScore) { bestScore = s; best = pts; }
      }
      approx.delete(); c.delete();
    }

    if (!best) best = fallbackHull(cv, contours, area);
    return best ? { quad: orderCorners(best), score: bestScore } : null;
  } finally {
    trash.forEach(m => m.delete());
  }
}

function matToPoints(approx) {
  const d = approx.data32S, out = [];
  for (let i = 0; i < d.length; i += 2) out.push({ x: d[i], y: d[i + 1] });
  return out;
}

### Extrait 5

/** Score = aire relative x rectangularite (angles ~90 deg) x coherence des cotes opposes */
function quadScore(p, cArea, frameArea) {
  const areaRatio = cArea / frameArea;

  let angleCost = 0;
  for (let i = 0; i < 4; i++) {
    const a = p[(i + 3) % 4], b = p[i], c = p[(i + 1) % 4];
    const v1 = { x: a.x - b.x, y: a.y - b.y }, v2 = { x: c.x - b.x, y: c.y - b.y };
    const cos = (v1.x * v2.x + v1.y * v2.y) /
                (Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y) + 1e-6);
    angleCost += Math.abs(cos);            // 0 = angle droit parfait
  }
  const rect = Math.max(0, 1 - angleCost / 4 / 0.5);   // cos > 0.5 (=60 deg) => rejet

  const d = (i, j) => Math.hypot(p[i].x - p[j].x, p[i].y - p[j].y);
  const r1 = Math.min(d(0,1), d(2,3)) / (Math.max(d(0,1), d(2,3)) + 1e-6);
  const r2 = Math.min(d(1,2), d(3,0)) / (Math.max(d(1,2), d(3,0)) + 1e-6);

  return areaRatio * rect * r1 * r2;
}

/** Repli : enveloppe convexe du plus grand contour + epsilon progressif */
function fallbackHull(cv, contours, frameArea) {
  let bi = -1, ba = 0;
  for (let i = 0; i < contours.size(); i++) {
    const a = cv.contourArea(contours.get(i));
    if (a > ba && a < frameArea * 0.98) { ba = a; bi = i; }
  }
  if (bi < 0 || ba < frameArea * 0.12) return null;
  const hull = new cv.Mat(), approx = new cv.Mat();
  cv.convexHull(contours.get(bi), hull, false, true);
  const peri = cv.arcLength(hull, true);
  for (const eps of [0.02, 0.03, 0.05, 0.08]) {
    cv.approxPolyDP(hull, approx, eps * peri, true);
    if (approx.rows === 4) {
      const pts = matToPoints(approx);
      hull.delete(); approx.delete();
      return pts;
    }
  }
  hull.delete(); approx.delete();
  return null;
}

/** Ordonne en TL, TR, BR, BL — methode somme/difference (valide jusqu'a ~45 deg de rotation) */
export function orderCorners(p) {
  const bySum  = [...p].sort((a, b) => (a.x + a.y) - (b.x + b.y));
  const byDiff = [...p].sort((a, b) => (a.y - a.x) - (b.y - b.x));
  return [bySum[0], byDiff[0], bySum[3], byDiff[3]];   // TL, TR, BR, BL
}

### Extrait 6

// Warp pleine resolution avec estimation de la taille de sortie a partir des cotes
// (ne JAMAIS warper vers une taille arbitraire comme le fait jscanify)
export function warpToFullRes(cv, srcFull, quadSmall, scale, { maxSide = 2600 } = {}) {
  const q = quadSmall.map(p => ({ x: p.x * scale, y: p.y * scale }));
  const [tl, tr, br, bl] = q;
  const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  let w = Math.round(Math.max(d(tl, tr), d(bl, br)));
  let h = Math.round(Math.max(d(tl, bl), d(tr, br)));

  // plafonne (memoire canvas iOS) en conservant le ratio
  const f = Math.min(1, maxSide / Math.max(w, h));
  w = Math.max(64, Math.round(w * f));
  h = Math.max(64, Math.round(h * f));

  const srcTri = cv.matFromArray(4, 1, cv.CV_32FC2,
    [tl.x, tl.y, tr.x, tr.y, br.x, br.y, bl.x, bl.y]);
  const dstTri = cv.matFromArray(4, 1, cv.CV_32FC2, [0, 0, w, 0, w, h, 0, h]);

  const M = cv.getPerspectiveTransform(srcTri, dstTri);
  const out = new cv.Mat();
  cv.warpPerspective(srcFull, out, M, new cv.Size(w, h),
    cv.INTER_CUBIC, cv.BORDER_REPLICATE, new cv.Scalar());

  srcTri.delete(); dstTri.delete(); M.delete();
  return out;
}

// Snap de ratio : si |w/h - 210/297| < 0.06 -> forcer h = round(w * 297/210) (A4)
// Idem US Letter (8.5/11) et carte ID-1 (85.6/53.98).

### Extrait 7

/**
 * Correction d'ombre par division par le fond estime.
 * Fond = dilate + medianBlur (noyau large) => enleve le texte, garde l'eclairage.
 * A FAIRE AVANT toute binarisation.
 */
export function flattenIllumination(cv, gray) {
  const bg = new cv.Mat(), out = new cv.Mat();
  const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(15, 15));
  cv.dilate(gray, bg, k);
  k.delete();
  cv.medianBlur(bg, bg, 21);   // ksize impair obligatoire

  // out = gray / bg * 255 sature — equivalent de cv2.divide(gray, bg, scale=255)
  const g32 = new cv.Mat(), b32 = new cv.Mat(), d32 = new cv.Mat();
  gray.convertTo(g32, cv.CV_32F);
  bg.convertTo(b32, cv.CV_32F);
  cv.add(b32, new cv.Mat(b32.rows, b32.cols, cv.CV_32F, new cv.Scalar(1)), b32); // evite /0
  cv.divide(g32, b32, d32, 255.0, cv.CV_32F);
  d32.convertTo(out, cv.CV_8U);

  g32.delete(); b32.delete(); d32.delete(); bg.delete();
  return out;
}

### Extrait 8

// Amelioration complete : illumination -> CLAHE -> mode de sortie
export function enhanceDocument(cv, rgbaMat, mode = 'color') {
  const gray = new cv.Mat();
  cv.cvtColor(rgbaMat, gray, cv.COLOR_RGBA2GRAY);

  const flat = flattenIllumination(cv, gray);
  gray.delete();

  const clahe = new cv.CLAHE();
  clahe.setClipLimit(2.0);
  clahe.setTilesGridSize(new cv.Size(8, 8));
  const eq = new cv.Mat();
  clahe.apply(flat, eq);
  clahe.delete(); flat.delete();

  if (mode === 'gray') return eq;

  if (mode === 'bw') {
    const bw = new cv.Mat();
    let bs = Math.max(15, Math.round(Math.min(eq.cols, eq.rows) / 40));
    if (bs % 2 === 0) bs++;
    cv.adaptiveThreshold(eq, bw, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C,
                         cv.THRESH_BINARY, bs, 10);
    const k = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(2, 2));
    cv.morphologyEx(bw, bw, cv.MORPH_OPEN, k);   // supprime le poivre residuel
    k.delete(); eq.delete();
    return bw;
  }

  // mode 'color' : luminance corrigee, chroma preservee (RGBA -> YCrCb -> Y=eq -> RGBA)
  const rgb = new cv.Mat(), ycc = new cv.Mat();
  cv.cvtColor(rgbaMat, rgb, cv.COLOR_RGBA2RGB);
  cv.cvtColor(rgb, ycc, cv.COLOR_RGB2YCrCb);
  const ch = new cv.MatVector();
  cv.split(ycc, ch);
  eq.copyTo(ch.get(0));
  cv.merge(ch, ycc);
  cv.cvtColor(ycc, rgb, cv.COLOR_YCrCb2RGB);
  const out = new cv.Mat();
  cv.cvtColor(rgb, out, cv.COLOR_RGB2RGBA);
  ch.delete(); ycc.delete(); rgb.delete(); eq.delete();
  return out;
}

### Extrait 9

// Deskew par Hough probabiliste — A APPLIQUER APRES le warp perspectif
export function deskew(cv, grayOrBw, { maxAngleDeg = 12 } = {}) {
  const edges = new cv.Mat(), lines = new cv.Mat();
  cv.Canny(grayOrBw, edges, 50, 150, 3, false);

  const minLen = Math.round(grayOrBw.cols * 0.3);   // 30 % de la largeur
  cv.HoughLinesP(edges, lines, 1, Math.PI / 180, 100, minLen, 20);

  const angles = [];
  for (let i = 0; i < lines.rows; i++) {
    const [x1, y1, x2, y2] = [
      lines.data32S[i * 4], lines.data32S[i * 4 + 1],
      lines.data32S[i * 4 + 2], lines.data32S[i * 4 + 3],
    ];
    let a = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    if (a > 90) a -= 180;
    if (a < -90) a += 180;
    if (Math.abs(a) <= maxAngleDeg) angles.push(a);   // on ignore les verticales
  }
  edges.delete(); lines.delete();
  if (angles.length < 8) return null;                 // pas assez de preuve

  angles.sort((a, b) => a - b);
  const median = angles[angles.length >> 1];
  if (Math.abs(median) < 0.25) return null;           // deja droit
  return median;
}

export function rotateBy(cv, src, angleDeg) {
  const c = new cv.Point(src.cols / 2, src.rows / 2);
  const M = cv.getRotationMatrix2D(c, angleDeg, 1);
  const out = new cv.Mat();
  cv.warpAffine(src, out, M, new cv.Size(src.cols, src.rows),
    cv.INTER_CUBIC, cv.BORDER_REPLICATE, new cv.Scalar());
  M.delete();
  return out;
}

### Extrait 10

// Boucle temps reel : requestVideoFrameCallback (iOS 15.4+) avec fallback rAF
function frameLoop(video, onFrame) {
  let stop = false;
  const useRVFC = 'requestVideoFrameCallback' in HTMLVideoElement.prototype;
  const tick = (now, meta) => {
    if (stop) return;
    onFrame(now, meta);
    useRVFC ? video.requestVideoFrameCallback(tick) : requestAnimationFrame(t => tick(t, null));
  };
  useRVFC ? video.requestVideoFrameCallback(tick) : requestAnimationFrame(t => tick(t, null));
  return () => { stop = true; };
}

// Envoi au Worker avec downscale GPU + transferable (pas de drawImage CPU)
const worker = new Worker('/scan-worker.js', { type: 'module' });
const DETECT_SHORT = 480;
let busy = false;

const stopLoop = frameLoop(video, async () => {
  if (busy) return;                    // back-pressure : on saute les frames
  busy = true;
  const scale = DETECT_SHORT / Math.min(video.videoWidth, video.videoHeight);
  const bmp = await createImageBitmap(video, {
    resizeWidth:  Math.round(video.videoWidth  * scale),
    resizeHeight: Math.round(video.videoHeight * scale),
    resizeQuality: 'low',
  });
  worker.postMessage({ type: 'detect', bmp, scale: 1 / scale }, [bmp]);
});

worker.onmessage = (e) => {
  busy = false;
  if (e.data.type === 'quad') drawOverlay(e.data.quad, e.data.stable);
};

### Extrait 11

// Detection de stabilite + lissage de l'overlay pour la capture auto
const hist = [];
const STABLE_N = 6;          // ~0.4 s a 15 fps
const MOVE_TOL_PX = 6;       // en coords de detection (480px) => ~1.3 %

function isStable(quad) {
  hist.push(quad);
  if (hist.length > STABLE_N) hist.shift();
  if (hist.length < STABLE_N) return false;
  const ref = hist[0];
  return hist.every(q => q.every((p, i) =>
    Math.hypot(p.x - ref[i].x, p.y - ref[i].y) < MOVE_TOL_PX));
}

let smooth = null;
function smoothQuad(q, a = 0.35) {   // EMA : evite le tremblement visuel
  if (!smooth) return (smooth = q);
  smooth = smooth.map((p, i) => ({
    x: p.x + a * (q[i].x - p.x),
    y: p.y + a * (q[i].y - p.y),
  }));
  return smooth;
}

### Extrait 12

// Capture haute resolution : ImageCapture.takePhoto() N'EXISTE PAS sur iOS.
// Voie principale : getUserMedia 4K + drawImage. TOUJOURS verifier getSettings().
const stream = await navigator.mediaDevices.getUserMedia({
  audio: false,
  video: {
    facingMode: { ideal: 'environment' },   // PAS deviceId exact (bascule de lentilles iOS 18+)
    width:  { ideal: 3840 },
    height: { ideal: 2160 },
    frameRate: { ideal: 30 },
  },
});
const track = stream.getVideoTracks()[0];
console.log(track.getSettings());   // Safari retombe SILENCIEUSEMENT sur le mode le plus proche

// Voie "qualite maximale" : app Camera native = 12 MP (4032x3024).
// accept explicite pour forcer la conversion HEIC -> JPEG par iOS :
// <input type="file" accept="image/jpeg,image/png" capture="environment">

### Extrait 13

// Detection de flou : variance du Laplacien, NORMALISEE en resolution
export function blurScore(cv, rgbaMat, norm = 480) {
  const small = new cv.Mat(), gray = new cv.Mat(), lap = new cv.Mat();
  const s = norm / Math.min(rgbaMat.cols, rgbaMat.rows);
  cv.resize(rgbaMat, small,
    new cv.Size(Math.round(rgbaMat.cols * s), Math.round(rgbaMat.rows * s)),
    0, 0, cv.INTER_AREA);
  cv.cvtColor(small, gray, cv.COLOR_RGBA2GRAY);
  cv.Laplacian(gray, lap, cv.CV_64F, 3, 1, 0, cv.BORDER_DEFAULT);

  const mean = new cv.Mat(), std = new cv.Mat();
  cv.meanStdDev(lap, mean, std);
  const variance = std.doubleAt(0, 0) ** 2;

  small.delete(); gray.delete(); lap.delete(); mean.delete(); std.delete();
  return variance;   // < 60 flou | 60-120 limite | >= 120 net (a calibrer)
}

### Extrait 14

// Diagnostic d'eclairage a partir de l'histogramme du gris
export function lightingScore(cv, rgbaMat) {
  const gray = new cv.Mat(), mean = new cv.Mat(), std = new cv.Mat();
  cv.cvtColor(rgbaMat, gray, cv.COLOR_RGBA2GRAY);
  cv.meanStdDev(gray, mean, std);
  const mu = mean.doubleAt(0, 0), sigma = std.doubleAt(0, 0);

  const hi = new cv.Mat(), lo = new cv.Mat();
  cv.threshold(gray, hi, 250, 255, cv.THRESH_BINARY);
  cv.threshold(gray, lo, 5, 255, cv.THRESH_BINARY_INV);
  const n = gray.rows * gray.cols;
  const clipHi = cv.countNonZero(hi) / n, clipLo = cv.countNonZero(lo) / n;

  gray.delete(); mean.delete(); std.delete(); hi.delete(); lo.delete();

  let issue = null;
  if (mu < 70)            issue = 'too_dark';
  else if (mu > 215)      issue = 'too_bright';
  else if (sigma < 28)    issue = 'low_contrast';
  else if (clipHi > 0.06) issue = 'glare';        // reflet / flash
  else if (clipLo > 0.15) issue = 'shadow';
  return { mean: mu, std: sigma, clipHi, clipLo, issue };
}

### Extrait 15

// Assemblage PDF multipage — @cantoo/pdf-lib (fork actif ; pdf-lib original abandonne depuis 2021)
import { PDFDocument } from '@cantoo/pdf-lib';

const A4 = { w: 595.28, h: 841.89 };   // points PDF (72 dpi)

export async function buildPdf(jpegBlobs, meta = {}) {
  const pdf = await PDFDocument.create();
  pdf.setTitle(meta.title ?? 'Scan');
  pdf.setCreator('Papers PWA');
  pdf.setProducer('Papers PWA');
  pdf.setCreationDate(new Date());

  for (const blob of jpegBlobs) {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const img = await pdf.embedJpg(bytes);   // DCTDecode : AUCUNE recompression

    if (meta.fitToA4) {
      const s = Math.min(A4.w / img.width, A4.h / img.height);
      const dw = img.width * s, dh = img.height * s;
      const page = pdf.addPage([A4.w, A4.h]);
      page.drawImage(img, { x: (A4.w - dw) / 2, y: (A4.h - dh) / 2, width: dw, height: dh });
    } else {
      const dpi = 200;
      const pw = img.width * 72 / dpi, ph = img.height * 72 / dpi;
      const page = pdf.addPage([pw, ph]);
      page.drawImage(img, { x: 0, y: 0, width: pw, height: ph });
    }
  }
  return new Blob([await pdf.save({ useObjectStreams: true })],
                  { type: 'application/pdf' });
}

### Extrait 16

// canvas.toBlob('image/webp') retombe SILENCIEUSEMENT sur PNG dans Safari (spec HTML).
// Toujours verifier le type reellement obtenu.
async function encode(canvas, type = 'image/jpeg', quality = 0.82) {
  const blob = await new Promise(r => canvas.toBlob(r, type, quality));
  if (blob.type !== type) console.warn(`Fallback silencieux: ${type} -> ${blob.type}`);
  return blob;
}
// Recommandation : JPEG q=0.80-0.85 pour tout. A4 warpe 2200x3100 :
//   couleur q=0.82 -> 220-400 Ko | gris q=0.85 -> 130-250 Ko

### Extrait 17

<?php
// app/Jobs/NormalizeScan.php — Laravel 13 / PHP 8.5, traitement serveur "best effort"
use Imagick;

final class NormalizeScan implements ShouldQueue
{
    public function __construct(private readonly string $path) {}

    public function handle(): void
    {
        $im = new Imagick(Storage::path($this->path));
        $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $im->deskewImage(0.4 * Imagick::getQuantum());   // redressement Radon
        $im->trimImage(0.1 * Imagick::getQuantum());     // recadre les marges noires du warp
        $im->setImagePage(0, 0, 0, 0);
        // egalisation locale — equivalent de -lat 25x25+10%
        $im->adaptiveThresholdImage(25, 25, 0.10 * Imagick::getQuantum());
        $im->setImageCompressionQuality(85);
        $im->setImageFormat('jpeg');
        $im->stripImage();                                // enleve EXIF/GPS (RGPD)
        Storage::put($this->path, $im->getImageBlob());
        $im->clear();
    }
}
// NB : Imagick + PDF exige Ghostscript ET une policy.xml autorisant PDF (bloquee depuis CVE-2018-16509).

### Extrait 18

// Calcul du cout vision OpenAI 2026 (modeles PATCH-BASED 32x32)
// patch_count     = ceil(w/32) x ceil(h/32)
// billable_tokens = ceil(patch_count_apres_resize x multiplier)
// multiplier : 1.2 (gpt-6-astra, gpt-5.6-*, gpt-5.5, gpt-5.4*) | 1.62 (gpt-4.1-mini)
// patch_budget : detail "high" = 2500 | detail "original" = 10000 | rejet dur > 30000

function visionTokens(w, h, { budget = 2500, multiplier = 1.2 } = {}) {
  const shrink = Math.sqrt((32 * 32 * budget) / (w * h));
  const s = Math.min(1, shrink);
  const rw = Math.round(w * s), rh = Math.round(h * s);
  const patches = Math.ceil(rw / 32) * Math.ceil(rh / 32);
  if (patches > 30000) throw new Error('image rejetee par l API');
  return Math.ceil(patches * multiplier);
}

// 2500 patches = 2 560 000 px d'aire. Pour un ratio A4 : ~1346 x 1903 px.
// => Envoyer plus grand en detail:"high" est downscale par OpenAI : inutile.
// => Faire le downscale soi-meme avec cv.INTER_AREA (bien meilleur sur les petits caracteres).
console.log(visionTokens(1350, 1900));   // ~3000 tokens
console.log(visionTokens(2200, 3100, { budget: 10000 }));  // ~12000 tokens (detail:"original")


## Incertitudes

- Les temps de chargement WASM et les budgets de perf par etape sur iPhone 14 Plus (A15) sont des ordres de grandeur derives de l'experience et des tailles de binaire mesurees, PAS des benchmarks que j'ai executes sur l'appareil. A mesurer reellement avec performance.mark() sur un vrai iPhone 14 Plus avant de figer les seuils.
- La taille exacte d'un build custom opencv.js limite a core+imgproc (~1,5-2,5 Mo wasm) est une estimation issue de rapports d'issues GitHub, pas d'un build que j'ai realise. Le chiffre exact depend de la whitelist finale et des flags (-Oz vs -O3, LTO).
- Le support 4K (3840x2160) de getUserMedia sur iPhone 14 Plus specifiquement provient d'un rapport de test sur 'iPhone / iOS 18.7 / Safari 26.2' sans modele precise. Le 14 Plus n'a pas de camera 48 MP (contrairement aux modeles Pro) : il est possible que son plafond reel soit 1920x1080. A VERIFIER en lisant track.getSettings() sur l'appareil cible avant de construire l'UX dessus.
- Les seuils numeriques de variance du Laplacien (60 / 120) et de lightingScore (mu<70, sigma<28, clipHi>0.06) sont des points de depart raisonnables, pas des valeurs validees statistiquement. Ils doivent etre calibres sur un jeu de photos representatif des documents des utilisateurs.
- La limite memoire canvas iOS de ~384 Mo provient de rapports communautaires sur iOS 15 ; Apple ne la documente pas et elle varie probablement par appareil et par version. La valeur sur iOS 26 / iPhone 14 Plus (6 Go RAM) n'est pas verifiee.
- Le patch_budget d'OpenAI pour gpt-6-astra et gpt-5.6-* en mode detail:"original" n'apparait pas dans le tableau que j'ai pu extraire (seuls gpt-5.5 et gpt-5.4* y figurent avec 10 000). A confirmer dans la doc avant de choisir detail:"original" sur ces modeles.
- Le prix exact par million de tokens des modeles cites provient d'agregateurs tiers (morphllm, cloudzero), pas de la page de pricing officielle que je n'ai pas pu lire directement. A reverifier sur developers.openai.com/api/docs/pricing avant tout calcul de cout unitaire.
- Le comportement exact de la conversion HEIC->JPEG selon l'attribut accept a change entre Safari 17 et aujourd'hui ; les sources se contredisent partiellement (l'une dit que la conversion est systematique quel que soit accept). A tester empiriquement sur iOS 26.
- Les tailles de fichier cibles par page (220-400 Ko en couleur q=0.82 pour 2200x3100) sont des estimations basees sur l'experience du JPEG sur du contenu documentaire, pas des mesures sur vos images.
- Je n'ai pas verifie si OpenCV 5.0.0 modifie les flags Emscripten (MODULARIZE / EXPORT_NAME) ou la structure de opencv_js.config.py par rapport a la branche 4.x : j'ai lu ces fichiers sur la branche 4.x uniquement. Si vous ciblez 5.0.0, relisez modules/js/CMakeLists.txt sur cette branche.
- La disponibilite de WebGPU pour accelerer warpPerspective sur iOS 26 est mentionnee par une source secondaire ; je n'ai pas verifie son etat exact ni ses performances reelles sur A15.

## Sources

- https://github.com/puffinsoft/jscanify
- https://www.npmjs.com/package/jscanify
- https://registry.npmjs.org/jscanify
- https://github.com/opencv/opencv/blob/4.x/platforms/js/opencv_js.config.py
- https://raw.githubusercontent.com/opencv/opencv/4.x/platforms/js/opencv_js.config.py
- https://raw.githubusercontent.com/opencv/opencv/4.x/platforms/js/build_js.py
- https://raw.githubusercontent.com/opencv/opencv/4.x/modules/js/CMakeLists.txt
- https://docs.opencv.org/4.x/d4/da1/tutorial_js_setup.html
- https://github.com/TechStark/opencv-js/releases
- https://api.github.com/repos/opencv/opencv/releases
- https://opencv.org/opencv-5/
- https://www.phoronix.com/news/OpenCV-5.0-Released
- https://www.cnx-software.com/2026/06/10/opencv-5-release-new-dnn-engine-with-enhanced-onnx-and-llm-vlm-support-intel-arm-and-risc-v-hardware-optimizations/
- https://data.jsdelivr.com/v1/packages/npm/@techstark/opencv-js@5.0.0-release.1
- https://registry.npmjs.org/@techstark%2fopencv-js
- https://registry.npmjs.org/opencv-document-scanner
- https://github.com/tony-xlh/opencvjs-document-scanner
- https://registry.npmjs.org/pdf-lib
- https://registry.npmjs.org/jspdf
- https://registry.npmjs.org/cropperjs
- https://registry.npmjs.org/@cantoo%2fpdf-lib
- https://www.npmjs.com/package/@cantoo/pdf-lib
- https://github.com/hopding/pdf-lib
- https://www.nutrient.io/blog/javascript-pdf-libraries/
- https://developer.mozilla.org/en-US/docs/Web/API/HTMLVideoElement/requestVideoFrameCallback
- https://caniuse.com/mdn-api_htmlvideoelement_requestvideoframecallback
- https://bugs.webkit.org/show_bug.cgi?id=211945
- https://github.com/videojs/video.js/pull/7854
- https://developer.mozilla.org/en-US/docs/Web/API/OffscreenCanvas
- https://caniuse.com/offscreencanvas
- https://www.testmuai.com/learning-hub/offscreencanvas-browser-support/
- https://developer.mozilla.org/en-US/docs/Web/API/ImageCapture
- https://www.testmuai.com/learning-hub/image-capture-api-browser-support/
- https://w3c.github.io/mediacapture-image/index.html
- https://oberhofer.co/mediastreamtrack-and-its-capabilities/
- https://www.dynamsoft.com/codepool/take-high-resolution-photo-in-the-browser.html
- https://www.dynamsoft.com/codepool/web-document-scanner-with-opencvjs.html
- https://webrtchacks.com/guide-to-safari-webrtc/
- https://developer.apple.com/forums/thread/113532
- https://developer.apple.com/forums/thread/776460
- https://bugs.webkit.org/show_bug.cgi?id=179994
- https://pqina.nl/blog/canvas-area-exceeds-the-maximum-limit/
- https://pqina.nl/blog/total-canvas-memory-use-exceeds-the-maximum-limit/
- https://longviewcoder.com/2024/02/09/konva-canvas-limits-in-safari-ios-explainer/
- https://developer.apple.com/forums/thread/687866
- https://lionpuro.com/posts/canvas-is-finally-usable-on-safari/
- https://caniuse.com/mdn-api_htmlcanvaselement_toblob_type_parameter_webp
- https://developer.mozilla.org/en-US/docs/Web/API/HTMLCanvasElement/toBlob
- https://webkit.org/blog/18178/webkit-features-for-safari-26-6/
- https://webkit.org/blog/17640/webkit-features-for-safari-26-2/
- https://webkit.org/blog/17541/webkit-features-for-safari-26-1/
- https://webkit.org/blog/17333/webkit-features-in-safari-26-0/
- https://webkit.org/blog/16993/news-from-wwdc25-web-technology-coming-this-fall-in-safari-26-beta/
- https://www.digitalsamba.com/blog/webcodecs-api-explained
- https://www.testmuai.com/learning-hub/webcodecs-browser-support/
- https://techbytes.app/posts/webassembly-simd-threads-flags-cheat-sheet-2026/
- https://techbytes.app/posts/wasm-multithreading-sharedarraybuffer-cheat-sheet/
- https://reintech.io/blog/webassembly-browser-support-2026-compatibility-guide
- https://platform.uno/blog/the-state-of-webassembly-2025-2026/
- https://developers.openai.com/api/docs/guides/images-vision
- https://developers.openai.com/api/docs/models
- https://developers.openai.com/api/docs/pricing
- https://www.morphllm.com/openai-api-pricing
- https://www.cloudzero.com/blog/openai-pricing/
- https://openai.com/index/gpt-5-6/
- https://laravel-news.com/laravel-13-released
- https://benjamincrozat.com/laravel-versions
- https://itmarkerz.co.in/blog/laravel-php-compatibility-matrix
- https://jonathanbird.com.au/blog/latest-php-version
- https://zenn.dev/kou_pg_0131/articles/safari-input-file-heic
- https://developer.apple.com/forums/thread/743049
- https://shkspr.mobi/blog/2020/12/coping-with-heic-in-the-browser/
- https://opencv.org/shadow-correction-using-opencv/
- https://learnopencv.com/automatic-document-scanner-using-opencv/
- https://www.pythontutorials.net/blog/how-to-remove-shadow-from-scanned-images-using-opencv/
- https://medium.com/@jinkawin.p/how-to-custom-build-opencv-for-javascript-and-slim-it-down-c9f832c1dcd5
- https://github.com/opencv/opencv/issues/21431
- https://scanbot.io/techblog/js-camera-document-scanner-tutorial/


---

# research:shortcuts-bridge

## Synthèse

วย
# Scanner natif Apple (VisionKit) depuis une PWA via l'app Raccourcis — verdict + recette

## TL;DR

| Question | Réponse vérifiée |
|---|---|
| Action Raccourcis de scan ? | OUI, 3 variantes (Fichiers, Notes iOS 18.5+, app Actions) |
| L'action Apple native rend-elle le fichier exploitable ? | NON pour l'action Fichiers (elle scanne dans un dossier, ne retourne rien). OUI avec l'app **Actions** (gratuite, Sindre Sorhus) |
| Lancer un raccourci depuis une PWA standalone ? | OUI, `shortcuts://run-shortcut?...` — ça quitte la PWA |
| `x-success` vers une URL https rouvre-t-il la PWA ? | **NON. Ça ouvre Safari.** iOS ne sait pas deep-linker une web app installée via https |
| Comment revenir alors ? | Action **« Ouvrir l'app » / « Open App »** en fin de raccourci : elle liste les web apps de l'écran d'accueil depuis iOS 16.4 |
| Upload PDF vers Laravel depuis le raccourci ? | OUI, « Obtenir le contenu de l'URL » → POST → corps **Form** (multipart) + header `Authorization` |
| Générer un .shortcut par code ? | **Non fiable.** Signature AEA obligatoire depuis iOS 15 ; `shortcuts sign` refuse les plists faits main et exige iCloud. Distribution = lien iCloud créé manuellement UNE fois |
| Live Text / VisionKit depuis le web ? | **Impossible.** Aucune API. Shape Detection API cassée sur iOS depuis iOS 18 |

Contexte matériel : **iPhone 14 Plus est compatible iOS 27** (sorti le 14 sept. 2026, mêmes modèles qu'iOS 26 : iPhone 11+). L'app **Actions 4.2.0 exige iOS 26.0+** → OK.

---

## 1. Les actions de scan disponibles dans Raccourcis

### a) Apple — « Scan Document » / FR « Numériser un document » (catégorie Fichiers)
- Ouvre l'UI VisionKit (détection de bords, correction de perspective, filtres Couleur / Niveaux de gris / Noir et blanc / Photo, multipage, auto ou manuel) — mais **ces réglages ne sont PAS des paramètres de l'action**, ils sont choisis par l'utilisateur dans l'UI de scan.
- Le scan est **enregistré dans le dossier Fichiers courant**, puis l'exécution reprend quand tu reviens dans Raccourcis.
- **Limite bloquante** : l'action ne renvoie rien d'exploitable comme variable. Contournement Apple officieux (recette de Matthew Cassinelli) : `Scan Document` → `Wait` → `Get Contents of Folder` → `Filter Files` (tri Date de création desc., limite 1).
- Sortie : PDF nommé par défaut `Scanned Document.pdf` (collisions de noms fréquentes).

### b) Apple — « Scan Documents » / FR « Scanner des documents » (catégorie **Notes**, iOS/iPadOS **18.5+**)
- Nouvelle action listée par Apple dans les nouveautés Raccourcis 18.5 (FR : « Scanner des documents », avec « Renommer le dossier », « Ajouter un élément de liste de pointage », « Ajouter un fichier »).
- Le scan atterrit dans une **note** → récupérer le PDF ensuite est laborieux. À éviter pour ton flux.

### c) **App « Actions » de Sindre Sorhus — « Scan Documents » ← RECOMMANDÉ**
- Gratuite, App Store, **iOS 26.0+**, v4.2.0.
- Description exacte : *« Scans one or more documents using the system document scanner. »*
- **Paramètre : `Use PDF` (booléen)** → PDF multipage ou images.
- **Sortie : fichier, MAIS via le presse-papiers.** Note officielle : *« The resulting images are copied to the clipboard. Add the "Wait to Return" and "Get Clipboard" actions after this one. »*
- C'est la seule voie propre pour récupérer le scan comme variable.

### Identifiants d'actions (plist)
- `is.workflow.actions.downloadurl` = **Get Contents of URL** (vérifié).
- `is.workflow.actions.comment`, `is.workflow.actions.openurl`, `is.workflow.actions.getclipboard`, `is.workflow.actions.setclipboard`, `is.workflow.actions.notification`, `is.workflow.actions.runworkflow`, `is.workflow.actions.text`, `is.workflow.actions.url` (vérifiés).
- ⚠️ **`is.workflow.actions.documentscan` n'a PAS pu être confirmé.** Aucune source fiable ne le documente ; les dumps publics (shortcuts-js, sebj/iOS-Shortcuts-Reference) ne contiennent que `is.workflow.actions.scanbarcode`, `airdropdocument`, `previewdocument`. **Ne code pas dessus** : crée le raccourci à la main, exporte-le, lis le plist. De toute façon l'action de l'app Actions a un identifiant du domaine `com.sindresorhus.Actions.*`, pas `is.workflow.*`.

---

## 2. Schéma d'URL — syntaxe exacte (doc Apple)

```
shortcuts://run-shortcut?name=[nom]&input=[text|clipboard]&text=[texte]
```
- `name` (requis) — nom EXACT du raccourci, URL-encodé (espaces → `%20`).
- `input` (optionnel) — soit la chaîne littérale `text`, soit `clipboard`.
- `text` — uniquement si `input=text` ; ignoré si `input=clipboard`.
- Exemple Apple FR : `shortcuts://run-shortcut?name=Rechercher%20Paris&input=text&text=paris%20est%20formidable`

Variante x-callback-url :
```
shortcuts://x-callback-url/run-shortcut?name=X&input=text&text=Y&x-success=...&x-error=...&x-cancel=...
```
- `x-success` : ouverte en cas de succès, avec `result=<sortie textuelle du raccourci>` ajouté.
- `x-error` : ouverte en cas d'échec, avec `errorMessage=<description>`.
- `x-cancel` : ouverte si l'utilisateur annule.

Autres schémas : `shortcuts://` (ouvre l'app), `shortcuts://open-shortcut?name=[name]` (ouvre l'éditeur), `shortcuts://create-shortcut`, `shortcuts://import-shortcut?url=<url iCloud>&name=<nom>` (non documenté par Apple mais fonctionnel — **uniquement avec des URLs `icloud.com/shortcuts/...`**).

---

## 3. Le point critique : le RETOUR vers la PWA

**iOS ne permet pas de deep-linker une web app installée via une URL https.** Confirmé par le forum développeur Apple (thread 762073, « iOS 18 doesn't seem to address this issue ») et par firt.dev (Link Capturing ❌, « Only a push message can open an installed PWA »). Donc `x-success=https://papers.tondomaine.fr/...` **ouvrira Safari**, pas ta PWA → tu perds la session standalone, le service worker, l'état.

**Solution : l'action « Ouvrir l'app » / « Open App » de Raccourcis liste les web apps de l'écran d'accueil depuis iOS 16.4.** C'est le seul chemin officiel pour rouvrir une PWA installée depuis l'extérieur. Le raccourci se termine par `Open App → Papers`.

Deuxième canal de retour : **Web Push** (iOS 16.4+, VAPID standard, pas d'APNs cert). Sur iOS, taper une notification push ouvre bien la PWA installée, même si elle n'est pas en cours d'exécution. Utilise-le pour notifier la fin de l'analyse OpenAI.

Contexte 2026 utile : iOS 26+ ouvre **par défaut** en web app tout site ajouté à l'écran d'accueil (toggle « Ouvrir en tant que web app »), sans manifest obligatoire. Et le retrait des PWA en UE (iOS 17.4 beta) **a été annulé par Apple le 1er mars 2024** — les home screen web apps + push fonctionnent en France.

---

## 4. Recette du raccourci « Papers Scan » (variante recommandée)

Prérequis : app **Actions** installée (gratuite, iOS 26+).

| # | Action (EN) | Action (FR) | Config |
|---|---|---|---|
| 1 | *(Entrée du raccourci)* | | Variable magique **Shortcut Input / Entrée du raccourci** = le jeton |
| 2 | **Scan Documents** (Actions) | Numériser des documents | `Use PDF` = **On** → l'UI VisionKit s'ouvre |
| 3 | **Wait to Return** | Attendre le retour | met en pause jusqu'au retour dans Raccourcis |
| 4 | **Get Clipboard** | Obtenir le presse-papiers | récupère le PDF |
| 5 | **Get Contents of URL** | Obtenir le contenu de l'URL | voir ci-dessous |
| 6 | **Get Dictionary Value** | Obtenir la valeur du dictionnaire | clé `documentId` |
| 7 | **Open App** | Ouvrir l'app | cible = **Papers** (ta web app écran d'accueil) |

Config de l'action 5 :
- URL : `https://papers.tondomaine.fr/api/v1/scans`
- Méthode : **POST**
- Headers : `Authorization` = `Bearer ` + [Entrée du raccourci]
- **Request Body : Form** (= multipart/form-data)
  - champ `file` → **basculer le type du champ de Text à File** → variable magique **Presse-papiers**
- ⚠️ **Ne mets PAS de header `Content-Type` à la main** : Raccourcis génère le boundary multipart lui-même et un `Content-Type` manuel casse la requête.
- La réponse JSON devient automatiquement un **Dictionnaire** exploitable.

Variante 100 % Apple (sans app tierce, moins fiable) : `Scan Document` (Fichiers) → `Wait` → `Get Contents of Folder` (dossier iCloud Drive dédié) → `Filter Files` (Date de création, décroissant, limite 1) → `Get Contents of URL` → `Open App`. Fragile : l'utilisateur doit enregistrer le scan dans LE bon dossier.

---

## 5. Distribution du raccourci

- **Signature obligatoire depuis iOS 15 / macOS 12** (format **AEA — Apple Encrypted Archive**). Un `.shortcut` non signé **ne s'importe pas** sur iOS 15+.
- CLI macOS : `shortcuts sign --mode <people-who-know-me|anyone> --input in.shortcut --output out.shortcut`. Mode par défaut = `people-who-know-me`.
- **Ce qui échoue en pratique** (retour d'expérience documenté, dev.to 2026) :
  1. plist écrit à la main (XML ou binaire) → `shortcuts sign` répond *« isn't in the correct format »* ; il n'accepte que des fichiers produits par l'app Raccourcis.
  2. signer un vrai fichier → *« In order to do this, you must be signed into iCloud »* même avec iCloud Drive actif.
  3. `shortcuts://import-shortcut?url=...` refuse tout sauf `icloud.com/shortcuts/...` (*« the specified shortcut URL is invalid »*) — y compris un HTTPS gist.
  4. les conteneurs SQLite locaux ne contiennent que des stubs vides.
- **Endpoint iCloud non documenté (lecture seule, sans auth)** : `https://www.icloud.com/shortcuts/api/records/<share-id>` → JSON avec `fields.name.value`, `fields.shortcut.value` (plist lisible non signé), `fields.signedShortcut.value` (blob signé Apple), `fields.shortcut.value.downloadURL` (contient le placeholder `${f}` à remplacer par `file`). Utile pour **lire/diffusser/versionner**, pas pour **publier**.
- **→ Stratégie réaliste** : tu crées le raccourci **une seule fois à la main**, tu fais Partager → **Copier le lien iCloud** (`https://www.icloud.com/shortcuts/<32 hex>`), et ta PWA propose ce lien en onboarding. Les paramètres par utilisateur ne sont **pas** dans le raccourci (ils arrivent par l'input).
- Les libs communautaires (`python-shortcuts`, `shortcuts-toolkit`, Cherri, Jellycuts) génèrent le plist mais **pas la signature** — elles dépendent d'un Mac ou d'un serveur de signature distant (projet `shortcut-signing-server`, service HubSign). Dépendance fragile, je ne la mettrais pas en prod.

### Alternatives de distribution / déclenchement
- **« Ajouter à l'écran d'accueil »** depuis Raccourcis (Détails → Ajouter à l'écran d'accueil, icône + nom personnalisables) : donne une 2e icône à côté de la PWA qui lance directement le scan. Bon raccourci UX, mais **pas d'input dynamique** → pas de jeton à usage unique. Réserve-le à un flux « scan sans session » (le raccourci demanderait alors un login stocké, ce qu'on veut éviter).
- **Automatisations** Raccourcis : aucun déclencheur pertinent ici (pas de trigger « web app ouverte »).
- **Widget** : même mécanique, `shortcuts://run-shortcut?name=...`.

---

## 6. Authentification sans secret en dur — jeton à usage unique

Le raccourci est **partagé entre tous tes utilisateurs**, donc il ne doit contenir **aucun** secret. Le secret voyage dans l'input.

Flux :
1. PWA (session Sanctum/cookie) → `POST /api/v1/scan-sessions` → `{session_id, upload_token}` (token aléatoire 48 car., TTL 15 min, **hash SHA-256 stocké en base**, clair rendu une seule fois).
2. PWA → `shortcuts://run-shortcut?name=Papers%20Scan&input=text&text=<upload_token>`.
3. Raccourci → `Authorization: Bearer <Entrée du raccourci>` sur le POST multipart.
4. Laravel : middleware qui résout le hash → user + session, marque `consumed_at`, refuse tout rejeu.

Durcissements : un seul usage, TTL court, `throttle`, taille max de fichier, `mimetypes` validés (pas `mimes`), et journalisation. Le token ne transite jamais par un log serveur (il est dans un header, pas dans l'URL).

⚠️ Le prompt de confidentialité Raccourcis : **au premier lancement, iOS demande l'autorisation d'envoyer des données à ton domaine**. C'est **par raccourci ET par domaine**, et persistant ensuite. À expliquer dans l'onboarding.

---

## 7. UX réelle du flux complet et frictions

**Onboarding (une fois)** : installer la PWA (Partager → Sur l'écran d'accueil, iOS 26 coche « Ouvrir en tant que web app ») → installer l'app **Actions** → importer le raccourci via lien iCloud → l'exécuter une fois pour accorder l'accès au domaine → autoriser les notifications push.

**Usage courant** : taper « Scanner » dans la PWA → **bascule visible vers Raccourcis** → UI VisionKit → scanner N pages → « Enregistrer » → retour à Raccourcis (bannière) → upload → **bascule vers la PWA** → écran « analyse en cours » → push quand le résumé + tâches sont prêts.

**Frictions à assumer :**
1. **3 apps + 1 import manuel** en onboarding. C'est le vrai coût.
2. **2 changements d'app visibles** par scan (PWA → Raccourcis → PWA). Pas de mode « invisible ».
3. Le nom du raccourci doit correspondre **exactement** à celui codé en dur dans la PWA ; si l'utilisateur le renomme, tout casse (et `x-error` ne remontera pas jusqu'à la PWA).
4. Si Raccourcis a été supprimé, `shortcuts://` échoue **silencieusement** ou affiche une erreur Safari → prévoir un timeout côté PWA + fallback `<input type="file" accept="image/*" capture>` ou scanner JS.
5. **Aucun retour d'erreur exploitable** : `x-error` ne peut pas revenir dans la PWA (https → Safari). Fais donc porter l'état par le **serveur** (statut de la scan session), pas par le callback.
6. Sur iOS, les **Background Sync / Periodic Sync / Background Fetch** n'existent pas : le polling doit se faire au `visibilitychange` quand la PWA revient au premier plan, et le reste passe par Web Push.
7. Le stockage web iOS peut être purgé après ~7 jours sans usage → ne garde jamais l'état critique uniquement en local.
8. Impossible de piloter les réglages de scan (couleur/N&B, auto/manuel) par code : c'est l'utilisateur dans l'UI VisionKit.

---

## 8. Live Text / VisionKit depuis le web — réponse factuelle

**Non, aucun accès.** Il n'existe aucune API web exposant VisionKit, `VNDocumentCameraViewController`, Live Text ou la reconnaissance de texte d'Apple. La **Shape Detection API** (`TextDetector`, `BarcodeDetector`, `FaceDetector`) est un draft WICG et **ne fonctionne plus sur Safari iOS depuis iOS 18** (WebKit bug 281848). Safari ne l'a jamais activée pour `TextDetector` en production.

**Donc, si tu veux un vrai scan sans dépendre de Raccourcis** : `getUserMedia` (supporté depuis iOS 13) + **jscanify** (v1.3.0+, basé sur OpenCV.js : détection du papier, correction de perspective, anti-reflets, `highlightPaper()` / `extractPaper()`) ou OpenCV.js directement. C'est le seul chemin 100 % web, et c'est **le fallback que je recommande de coder en parallèle** — voire en chemin principal, la voie Raccourcis en « bonus qualité ».

---

## 9. Mon verdict d'architecture

Le chemin Raccourcis **fonctionne** et donne la qualité de scan Apple, mais il coûte : une app tierce (Actions), un import manuel, deux bascules d'app, zéro remontée d'erreur, et un raccourci non versionnable par code. Pour un produit multi-utilisateurs je le traiterais comme un **mode « Scan Pro » optionnel**, avec **jscanify/OpenCV.js dans la PWA comme chemin par défaut** (installation zéro, contrôle total de l'UX, multipage géré côté client, upload direct en `fetch`).

## Faits clés vérifiés

- iOS 27 est sorti le 14 septembre 2026 ; l'iPhone 14 Plus est compatible (même liste qu'iOS 26 : iPhone 11 et plus récents).
- Syntaxe Apple officielle : shortcuts://run-shortcut?name=[nom]&input=[text|clipboard]&text=[texte] — espaces encodés en %20 ; text ignoré si input=clipboard.
- Variante x-callback : shortcuts://x-callback-url/run-shortcut?name=X&input=text&text=Y&x-success=...&x-error=...&x-cancel=... ; x-success reçoit result=, x-error reçoit errorMessage=.
- Autres schémas : shortcuts:// , shortcuts://open-shortcut?name=[name] , shortcuts://create-shortcut , shortcuts://import-shortcut?url=<icloud>&name=<nom> (ce dernier non documenté par Apple).
- L'action Apple native « Scan Document » (catégorie Fichiers, FR « Numériser un document ») scanne dans le dossier Fichiers courant et NE RETOURNE PAS le fichier comme variable exploitable.
- iOS/iPadOS 18.5 a ajouté une action Notes « Scan Documents » (FR « Scanner des documents »), avec « Renommer le dossier », « Ajouter un élément de liste de pointage », « Ajouter un fichier ».
- L'app gratuite « Actions » de Sindre Sorhus (v4.2.0, iOS 26.0+) fournit « Scan Documents » : « Scans one or more documents using the system document scanner », paramètre booléen `Use PDF`, sortie fichier VIA LE PRESSE-PAPIERS — doc officielle : « The resulting images are copied to the clipboard. Add the Wait to Return and Get Clipboard actions after this one. »
- L'action built-in « Wait to Return » (FR « Attendre le retour ») met le raccourci en pause jusqu'au retour dans Raccourcis — indispensable après le scan.
- Identifiant vérifié : is.workflow.actions.downloadurl = « Get Contents of URL » / « Obtenir le contenu de l'URL ».
- « Get Contents of URL » supporte GET/POST/PUT/PATCH/DELETE, headers personnalisés, et corps JSON / Form / File. Pour du multipart : choisir « Form » et basculer le type d'un champ de Text à File, puis y mettre la variable magique du fichier.
- La réponse JSON de « Get Contents of URL » devient automatiquement un Dictionnaire exploitable via « Get Dictionary Value ».
- iOS NE PERMET PAS de deep-linker une web app installée via une URL https : tout lien https ouvre Safari (confirmé forum dev Apple thread 762073, toujours vrai sur iOS 18 ; firt.dev : Link Capturing non supporté).
- L'action « Open App » / « Ouvrir l'app » de Raccourcis LISTE les web apps de l'écran d'accueil depuis iOS 16.4 — c'est le seul moyen officiel de rouvrir une PWA installée depuis l'extérieur.
- Un push Web (iOS 16.4+, VAPID standard, sans certificat APNs) ouvre bien la PWA installée quand on le tape, même si elle n'était pas lancée.
- Signature obligatoire depuis iOS 15 / macOS 12, format AEA (Apple Encrypted Archive) : un .shortcut non signé ne s'importe pas sur iOS 15+.
- CLI macOS : shortcuts sign --mode <people-who-know-me|anyone> --input in.shortcut --output out.shortcut (mode par défaut : people-who-know-me).
- Format du fichier : plist avec WFWorkflowActions (tableau), chaque action ayant WFWorkflowActionIdentifier + WFWorkflowActionParameters ; plus WFWorkflowName, WFWorkflowIcon, WFWorkflowClientVersion, WFWorkflowMinimumClientVersion(String), WFWorkflowImportQuestions, WFWorkflowInputContentItemClasses, WFWorkflowTypes.
- Endpoint iCloud non documenté et sans auth : https://www.icloud.com/shortcuts/api/records/<share-id> → JSON avec fields.name.value, fields.shortcut.value (plist non signé), fields.signedShortcut.value (blob signé), fields.shortcut.value.downloadURL (placeholder ${f} à remplacer par 'file').
- Un lien de partage iCloud a la forme https://www.icloud.com/shortcuts/<id> ; shortcuts://import-shortcut n'accepte QUE ces URLs iCloud.
- Raccourcis demande une autorisation réseau au premier lancement, par raccourci ET par domaine, puis la mémorise.
- Aucune API web n'expose VisionKit / Live Text. La Shape Detection API (TextDetector/BarcodeDetector) ne fonctionne plus sur Safari iOS depuis iOS 18 (WebKit bug 281848).
- Apple a annulé le 1er mars 2024 le retrait des home screen web apps en UE — les PWA standalone + push fonctionnent en France.
- Depuis iOS 26, tout site ajouté à l'écran d'accueil s'ouvre PAR DÉFAUT en web app (toggle « Ouvrir en tant que web app »), sans manifest requis.
- Fallback 100 % web : jscanify v1.3.0+ (OpenCV.js) — highlightPaper() / extractPaper(), détection du papier, correction de perspective, anti-reflets ; getUserMedia supporté sur iOS depuis 13.0.
- Laravel 13 est la version courante (13.30.x en septembre 2026), sortie le 17 mars 2026, PHP 8.3 minimum — donc PHP 8.5 est compatible.

## Pièges / ce qui ne marche PAS

- x-success=https://... NE ROUVRE PAS la PWA installée : ça ouvre Safari. N'utilise pas x-callback-url pour le retour ; termine le raccourci par l'action « Open App » ciblant la web app.
- Par conséquent x-error/x-cancel sont inutilisables pour remonter une erreur à la PWA. Fais porter tout l'état par le serveur (statut de la scan session) + Web Push.
- L'action Apple native « Scan Document » (Fichiers) ne renvoie PAS le fichier scanné comme variable — c'est la raison n°1 pour laquelle le flux 100 % Apple est fragile.
- L'action Notes « Scan Documents » (iOS 18.5+) dépose le scan dans une note ; le partage depuis la feuille de partage de Notes ne transmet pas le fichier à un raccourci. Inadapté.
- L'identifiant is.workflow.actions.documentscan N'EST PAS confirmé : aucun dump public ne le contient (on n'y trouve que scanbarcode, airdropdocument, previewdocument). Ne code rien dessus ; exporte un vrai raccourci et lis son plist.
- Ne pose PAS de header Content-Type à la main sur « Get Contents of URL » en mode Form : Raccourcis génère le boundary multipart et un Content-Type manuel casse la requête.
- Génération programmatique d'un .shortcut : shortcuts sign rejette tout plist fait main (« isn't in the correct format ») et exige d'être connecté à iCloud même pour signer un vrai fichier. shortcuts://import-shortcut refuse toute URL non-iCloud. Les conteneurs SQLite locaux ne contiennent que des stubs vides.
- Le nom du raccourci est le seul identifiant : si l'utilisateur le renomme, la PWA ne peut plus le lancer, et l'échec est silencieux.
- Si l'app Raccourcis a été supprimée, la navigation vers shortcuts:// échoue sans erreur exploitable depuis le JS. Prévois un timeout + fallback.
- Le raccourci est partagé entre tous les utilisateurs : ne mets JAMAIS de clé API ni d'URL par utilisateur dedans. Tout doit passer par l'input (jeton à usage unique).
- Raccourcis affiche un prompt de confidentialité au premier envoi vers ton domaine (par raccourci + par domaine). À expliquer en onboarding sinon l'utilisateur refuse.
- iOS n'a ni Background Sync, ni Periodic Background Sync, ni Background Fetch pour les PWA : aucune reprise d'upload en arrière-plan. Poll au visibilitychange + Web Push.
- Le stockage web iOS peut être purgé après ~7 jours sans ouverture de la PWA : ne conserve jamais l'état critique uniquement côté client.
- La Shape Detection API est cassée sur Safari iOS depuis iOS 18 — ne compte pas dessus pour de l'OCR/détection côté navigateur.
- Le PDF produit s'appelle souvent « Scanned Document.pdf » → collisions de noms. Renomme côté serveur, ne fais jamais confiance au filename envoyé par Raccourcis.
- On ne peut pas piloter par code les réglages du scanner VisionKit (couleur/N&B/niveaux de gris, auto/manuel, nombre de pages) : ce sont des choix faits par l'utilisateur dans l'UI.
- L'app Actions 4.2.0 exige iOS 26.0+ — ça exclut les utilisateurs restés sur iOS 18/25. Prévois un fallback.
- Attention aux articles 2026 qui répètent que les PWA sont désactivées en UE ou limitées à 50 Mo de stockage : c'est faux/périmé (Apple a fait marche arrière le 1er mars 2024 ; les quotas WebKit sont bien plus élevés depuis iOS 17).

## Extraits de code de référence

### Extrait 1

// PWA — démarrage du scan natif (scan.js)
const SHORTCUT_NAME = 'Papers Scan'; // doit correspondre EXACTEMENT au nom du raccourci

export async function startNativeScan() {
  // 1) jeton a usage unique, lie a l'utilisateur, TTL court
  const res = await fetch('/api/v1/scan-sessions', {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': window.csrf },
    credentials: 'same-origin',
  });
  const { session_id, upload_token } = await res.json();
  sessionStorage.setItem('papers.pendingScan', session_id);

  // 2) On quitte la PWA. PAS de x-success https : iOS ne sait pas rouvrir
  //    une web app installee depuis une URL https (ca ouvrirait Safari).
  //    Le retour se fait par l'action "Ouvrir l'app" en fin de raccourci.
  const url = 'shortcuts://run-shortcut'
    + '?name=' + encodeURIComponent(SHORTCUT_NAME)
    + '&input=text'
    + '&text=' + encodeURIComponent(upload_token);

  // doit etre declenche dans le meme tick qu'un vrai geste utilisateur
  window.location.href = url;

  // filet de securite : si Raccourcis n'est pas installe, rien ne se passe
  setTimeout(() => {
    if (document.visibilityState === 'visible') {
      showFallbackScanner(); // jscanify / <input capture>
    }
  }, 2500);
}

### Extrait 2

// PWA — reprise au retour depuis Raccourcis (pas de Background Sync sur iOS)
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return;
  const id = sessionStorage.getItem('papers.pendingScan');
  if (id) pollScanSession(id);
});

async function pollScanSession(id) {
  for (let i = 0; i < 60; i++) {                // ~2 min max
    const r = await fetch(`/api/v1/scan-sessions/${id}`, { credentials: 'same-origin' });
    const s = await r.json();
    if (s.status === 'uploaded' || s.status === 'analyzed') {
      sessionStorage.removeItem('papers.pendingScan');
      return renderDocument(s.document);
    }
    if (s.status === 'expired') {
      sessionStorage.removeItem('papers.pendingScan');
      return showError('Session de scan expiree');
    }
    await new Promise(res => setTimeout(res, 2000));
  }
}

### Extrait 3

<?php
// database/migrations/xxxx_create_scan_sessions_table.php
Schema::create('scan_sessions', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->char('token_hash', 64)->unique();          // sha256 du token en clair
    $t->string('status', 20)->default('pending');  // pending|uploaded|analyzed|expired
    $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
    $t->timestampTz('expires_at');
    $t->timestampTz('consumed_at')->nullable();
    $t->timestampsTz();
    $t->index(['user_id', 'status']);
});

### Extrait 4

<?php
// app/Http/Controllers/Api/ScanSessionController.php
public function store(Request $request)
{
    $plain = Str::random(48);   // court : tient sans probleme dans l'URL shortcuts://

    $session = ScanSession::create([
        'id'         => (string) Str::uuid7(),
        'user_id'    => $request->user()->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addMinutes(15),
    ]);

    return response()->json([
        'session_id'   => $session->id,
        'upload_token' => $plain,   // rendu UNE seule fois, jamais restocke en clair
        'expires_at'   => $session->expires_at,
    ], 201);
}

### Extrait 5

<?php
// app/Http/Middleware/OneTimeUploadToken.php
class OneTimeUploadToken
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        abort_if(! $plain, 401, 'Missing token');

        $session = ScanSession::where('token_hash', hash('sha256', $plain))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        abort_if(! $session, 401, 'Invalid or expired token');

        $request->attributes->set('scanSession', $session);
        Auth::setUser($session->user);   // multi-utilisateurs : on retrouve le proprietaire

        return $next($request);
    }
}

### Extrait 6

<?php
// routes/api.php
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::post('/scan-sessions', [ScanSessionController::class, 'store']);
    Route::get('/scan-sessions/{session}', [ScanSessionController::class, 'show']);
});

// L'upload vient de Raccourcis : pas de cookie, pas de CSRF, juste le Bearer a usage unique
Route::post('/v1/scans', [ScanUploadController::class, 'store'])
    ->middleware([OneTimeUploadToken::class, 'throttle:20,1']);

### Extrait 7

<?php
// app/Http/Controllers/Api/ScanUploadController.php
public function store(Request $request)
{
    $session = $request->attributes->get('scanSession');

    // Raccourcis envoie du multipart/form-data avec le champ "file"
    $request->validate([
        'file' => ['required', 'file', 'max:51200',
                   'mimetypes:application/pdf,image/jpeg,image/png,image/heic'],
    ]);

    return DB::transaction(function () use ($request, $session) {
        $upload = $request->file('file');

        // NE JAMAIS faire confiance au filename ( souvent "Scanned Document.pdf" )
        $path = $upload->store("users/{$session->user_id}/scans", 's3');

        $doc = Document::create([
            'user_id'      => $session->user_id,
            'storage_path' => $path,
            'mime'         => $upload->getMimeType(),
            'bytes'        => $upload->getSize(),
            'status'       => 'queued',
        ]);

        $session->forceFill([
            'status'      => 'uploaded',
            'document_id' => $doc->id,
            'consumed_at' => now(),
        ])->save();

        AnalyzeDocument::dispatch($doc);  // OCR + OpenAI + extraction taches/echeances

        // Raccourcis parse ce JSON en Dictionnaire : garde-le plat et minuscule
        return response()->json([
            'ok'         => true,
            'documentId' => $doc->id,
            'message'    => 'Scan recu',
        ], 201);
    });
}

### Extrait 8

# Recette du raccourci "Papers Scan" (EN / FR)
# Prerequis : app gratuite "Actions" (Sindre Sorhus), iOS 26+
#
# 1. [Entree du raccourci]              -> variable magique "Shortcut Input" = le jeton
# 2. Scan Documents (Actions)           / Numeriser des documents
#      Use PDF = On                     -> ouvre l'UI VisionKit, resultat copie dans le presse-papiers
# 3. Wait to Return                     / Attendre le retour
# 4. Get Clipboard                      / Obtenir le presse-papiers
# 5. Get Contents of URL                / Obtenir le contenu de l'URL
#      URL      : https://papers.exemple.fr/api/v1/scans
#      Method   : POST
#      Headers  : Authorization = "Bearer " + [Shortcut Input]
#      Body     : Form           <-- multipart/form-data
#        champ "file"  -> basculer le type Text -> File -> variable "Presse-papiers"
#      (NE PAS ajouter de header Content-Type : Raccourcis gere le boundary)
# 6. Get Dictionary Value               / Obtenir la valeur du dictionnaire
#      Key = documentId
# 7. Open App                           / Ouvrir l'app
#      App = "Papers"  <-- les web apps de l'ecran d'accueil sont listees depuis iOS 16.4

# Variante 100% Apple (sans app tierce, plus fragile) :
# 1. Scan Document (Fichiers) / Numeriser un document
# 2. Wait to Return
# 3. Get Contents of Folder  -> dossier iCloud Drive dedie
# 4. Filter Files            -> tri Date de creation, decroissant, limite 1
# 5. Get Contents of URL ... 6. Open App

### Extrait 9

<!-- Onboarding PWA : import du raccourci en un tap -->
<a href="https://www.icloud.com/shortcuts/VOTRE_ID_32_HEX">
  Installer le raccourci « Papers Scan »
</a>

<!-- Variante schema direct (non documentee par Apple, marche uniquement avec une URL iCloud) -->
<a href="shortcuts://import-shortcut?url=https%3A%2F%2Fwww.icloud.com%2Fshortcuts%2FVOTRE_ID_32_HEX&name=Papers%20Scan">
  Installer le raccourci
</a>

### Extrait 10

# Lire / versionner un raccourci publie (endpoint iCloud non documente, sans auth)
curl -s "https://www.icloud.com/shortcuts/api/records/<share-id>" -o rec.json

# Puis recuperer le plist non signe :
python3 - <<'PY'
import json, plistlib, urllib.request
rec = json.load(open('rec.json'))
url = rec['fields']['shortcut']['value']['downloadURL'].replace('${f}', 'file')
data = urllib.request.urlopen(url).read()
open('raw.shortcut', 'wb').write(data)
print(plistlib.loads(data).keys())
# -> WFWorkflowActions, WFWorkflowName, WFWorkflowIcon,
#    WFWorkflowClientVersion, WFWorkflowMinimumClientVersion(String),
#    WFWorkflowImportQuestions, WFWorkflowInputContentItemClasses, WFWorkflowTypes
PY

# Signature (macOS uniquement, necessite d'etre connecte a iCloud) :
shortcuts sign --mode anyone --input unsigned.shortcut --output signed.shortcut
# ATTENTION : refuse les plists ecrits a la main ("isn't in the correct format")

### Extrait 11

<?xml version="1.0" encoding="UTF-8"?>
<!-- Squelette .shortcut (plist). Utile pour LIRE/diffuser, pas pour publier :
     la signature AEA est obligatoire depuis iOS 15. -->
<plist version="1.0">
<dict>
  <key>WFWorkflowName</key><string>Papers Scan</string>
  <key>WFWorkflowClientVersion</key><string>3000</string>
  <key>WFWorkflowMinimumClientVersion</key><integer>900</integer>
  <key>WFWorkflowMinimumClientVersionString</key><string>900</string>
  <key>WFWorkflowTypes</key><array><string>NCWidget</string></array>
  <key>WFWorkflowInputContentItemClasses</key>
  <array><string>WFStringContentItem</string></array>
  <key>WFWorkflowImportQuestions</key><array/>
  <key>WFWorkflowActions</key>
  <array>
    <dict>
      <key>WFWorkflowActionIdentifier</key>
      <string>is.workflow.actions.downloadurl</string>
      <key>WFWorkflowActionParameters</key>
      <dict>
        <key>WFURL</key><string>https://papers.exemple.fr/api/v1/scans</string>
        <key>WFHTTPMethod</key><string>POST</string>
        <key>WFHTTPBodyType</key><string>Form</string>
      </dict>
    </dict>
  </array>
</dict>
</plist>

### Extrait 12

// Fallback 100% web (recommande comme chemin par defaut) — jscanify + OpenCV.js
// <script async src="https://docs.opencv.org/4.x/opencv.js"></script>
// <script src="https://cdn.jsdelivr.net/npm/jscanify@1.3.0/src/jscanify.min.js"></script>

const scanner = new jscanify();
const stream = await navigator.mediaDevices.getUserMedia({
  video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 } },
});
video.srcObject = stream;

// apercu temps reel avec surlignage du document
function loop() {
  const highlighted = scanner.highlightPaper(video);
  overlayCtx.drawImage(highlighted, 0, 0);
  requestAnimationFrame(loop);
}

// capture : recadrage + correction de perspective
function capturePage() {
  const canvas = document.createElement('canvas');
  canvas.width = video.videoWidth; canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0);
  return scanner.extractPaper(canvas, 1240, 1754); // A4 @ 150dpi
}


## Incertitudes

- Identifiant d'action exact du scan de document : is.workflow.actions.documentscan N'A PAS été vérifié. Aucune source fiable. À obtenir en exportant un raccourci réel et en lisant son plist (plutôt qu'en le devinant).
- Nom FR exact de l'action Apple native de la catégorie Fichiers : probablement « Numériser un document » (l'UI Fichiers utilise « Scanner un document »), non confirmé sur une page Apple FR listant les actions. Seul « Scanner des documents » (Notes, 18.5) est confirmé en FR par Apple.
- Limite de taille exacte de l'input passé via shortcuts://run-shortcut : non trouvée. Safari accepterait ~80 000 caractères d'URL, mais rien ne confirme la limite du handler shortcuts://. Reste sous ~200 caractères pour un jeton (aucun risque).
- Comportement précis de l'action Actions « Scan Documents » avec Use PDF = Off (une image ou plusieurs dans le presse-papiers, et comment les récupérer toutes) : non documenté.
- Est-ce que l'action « Open App » liste bien une web app dont le nom contient des accents / espaces, et comment la sélectionner de façon stable si l'utilisateur renomme son icône : non vérifié.
- Si le raccourci est lancé via shortcuts://run-shortcut depuis une PWA, bascule-t-il en plein écran Raccourcis ou en simple bannière (réglage « Afficher lors de l'exécution ») ? Non confirmé — à tester sur l'appareil.
- Le dépôt shortcuts-toolkit affirme « no cryptographic signing involved », ce qui contredit la documentation AEA/shortcuts sign. Probablement valable seulement pour un import local sur macOS ou pour une écriture directe en base — à ne pas considérer comme un chemin de distribution iOS.
- Fiabilité et pérennité des serveurs de signature distants (HubSign, shortcut-signing-server) : non évaluée, dépendance tierce risquée.
- Quota de stockage exact des web apps iOS en 2026 et politique d'éviction à 7 jours : les sources se contredisent, non tranché.
- Comportement exact de Raccourcis si le jeton est expiré (réponse 401) : l'action « Get Contents of URL » lève-t-elle une erreur bloquante qui empêche l'action « Open App » de s'exécuter ? À tester ; sinon placer « Open App » avant l'upload ou gérer avec « Si » sur le code de statut.

## Sources

- https://support.apple.com/guide/shortcuts/use-x-callback-url-apdcd7f20a6f/ios
- https://support.apple.com/guide/shortcuts/apd624386f42/ios
- https://support.apple.com/fr-fr/guide/shortcuts/apd624386f42/ios
- https://support.apple.com/guide/shortcuts/open-create-and-run-a-shortcut-apda283236d7/ios
- https://support.apple.com/fr-fr/121131
- https://support.apple.com/guide/shortcuts/share-shortcuts-apdf01f8c054/ios
- https://support.apple.com/guide/shortcuts/add-a-shortcut-to-the-home-screen-apd735880972/ios
- https://support.apple.com/guide/shortcuts/advanced-shortcuts-settings-apdfeb05586f/ios
- https://matthewcassinelli.com/shortcuts/scan-documents/
- https://matthewcassinelli.com/actions/get-contents-of-url/
- https://matthewcassinelli.com/actions/wait-to-return/
- https://gist.github.com/sindresorhus/fbba65a774fb9da915e624807a02a6d2
- https://sindresorhus.com/actions
- https://apps.apple.com/us/app/actions/id1586435171
- https://github.com/sindresorhus/Actions
- https://zachary7829.github.io/blog/shortcuts/fileformat
- https://cherrilang.org/compiler/signing.html
- https://cherrilang.org/compiler/file-format.html
- https://github.com/sebj/iOS-Shortcuts-Reference
- https://github.com/joshfarrant/shortcuts-js/blob/master/src/interfaces/WF/WFWorkflowActionIdentifier.ts
- https://github.com/drewburchfield/shortcuts-toolkit
- https://dev.to/eugeniya_ivanova_4a58eadc/i-tried-to-build-an-apple-shortcut-from-code-apple-said-no-four-times-4l5d
- https://github.com/scaxyz/shortcut-signing-server
- https://github.com/0xilis/libshortcutsign
- https://developer.apple.com/forums/thread/762073
- https://github.com/khmyznikov/pwa-install/issues/174
- https://github.com/ErikHardin/Trips/pull/294
- https://firt.dev/notes/pwa-ios/
- https://www.macrumors.com/2026/09/13/ios-27-release-date-new-features/
- https://www.macrumors.com/2026/09/14/ios-27-features-available-tomorrow/
- https://www.heise.de/en/news/iOS-26-and-iPadOS-26-Changed-web-app-behaviour-on-the-home-screen-10749652.html
- https://www.idownloadblog.com/2025/06/17/apple-ios-26-safari-web-apps-home-screen-bookmarks/
- https://9to5mac.com/2024/03/01/apple-home-screen-web-apps-ios-17-eu/
- https://techcrunch.com/2024/03/01/apple-reverses-decision-about-blocking-web-apps-on-iphones-in-the-eu/
- https://bugs.webkit.org/show_bug.cgi?id=281848
- https://wicg.github.io/shape-detection-api/text.html
- https://github.com/puffinsoft/jscanify
- https://colonelparrot.github.io/jscanify/
- https://www.dynamsoft.com/codepool/web-document-scanner-with-opencvjs.html
- https://pushpad.xyz/blog/ios-special-requirements-for-web-push-notifications
- https://laravel.com/docs/13.x/releases
- https://endoflife.date/laravel
- https://talk.automators.fm/t/app-or-shortcut-for-scanning/15937
- https://heydingus.net/blog/2024/8/simple-scan-aped-my-shortcut
- https://github.com/zoul/ios-url-scheme-length-limit


---

# research:laravel-stack

## Synthèse

Recherche web effectuée le 17/09/2026. Toutes les versions ont été vérifiées via l'API Packagist, l'API npm, l'API GitHub ou la doc officielle Laravel 13.x — rien ne vient de ma mémoire interne. Plusieurs blogs consultés étaient FAUX (voir `uncertain`).

# 1. Laravel 13 — versions et création de projet

Laravel 13 est sorti le **17 mars 2026**. Dernière release vérifiée : **laravel/framework v13.32.0 (2026-09-15)**, contrainte `php: ^8.3`. Matrice officielle : Laravel 13 supporte **PHP 8.3 → 8.5**. Le PHP 8.5 natif de l'utilisateur sur Windows 11 est donc parfaitement supporté. Bugfix jusqu'à Q3 2027, sécurité jusqu'au 17 mars 2028.

Le squelette `laravel/laravel` 13.x requiert `laravel/framework: ^13.17` et `laravel/tinker: ^3.0`.

Création (installateur officiel recommandé) :
```
composer global require laravel/installer
laravel new papers
cd papers
```
Ou `composer create-project laravel/laravel papers`.

Structure Laravel 13 (inchangée depuis 11) : **pas de `app/Http/Kernel.php`, pas de `app/Console/Kernel.php`**. Tout se configure dans `bootstrap/app.php` (`withRouting`, `withMiddleware`, `withExceptions`, `withSchedule`, `withBroadcasting`). Par défaut seuls `routes/web.php` et `routes/console.php` existent : `routes/api.php` n'apparaît qu'après `php artisan install:api` (qui installe aussi Sanctum). Le scheduler se déclare dans `routes/console.php`.

Nouveautés Laravel 13 directement utiles pour ce projet :
- **Laravel AI SDK** (`laravel/ai`) — API unifiée texte / embeddings / structured output / agents / tools / reranking.
- **Recherche vectorielle native** : colonne `vector()`, `Schema::ensureVectorExtensionExists()`, cast `AsVector`, `whereVectorSimilarTo()`. **Aucun package tiers pgvector n'est nécessaire.**
- **Manipulation d'images first-party** (`Illuminate\Support\Facades\Image`, propulsée par Intervention Image v4) — parfait pour les miniatures.
- Attributs PHP sur les jobs : `#[Tries]`, `#[Backoff]`, `#[Timeout]`, `#[FailOnTimeout]`, `#[DebounceFor]`, `#[UniqueFor]`.
- `Queue::route(Job::class, connection:, queue:)` pour centraliser le routage des jobs.
- `php artisan dev` remplace le script `concurrently` (voir section Bun).
- `PreventRequestForgery` (CSRF avec vérification d'origine).

# 2. Authentification PWA iOS — recommandation : **Sanctum en mode SPA (cookies de session)**

Versions : `laravel/sanctum v4.3.3`, `laravel/fortify v1.39.0`, `laravel/breeze v2.4.2`.

Installation : `php artisan install:api` (crée `routes/api.php`, publie la migration `personal_access_tokens`, installe Sanctum).

## Pourquoi les cookies et pas les tokens Bearer

L'argument décisif est **ITP (Intelligent Tracking Prevention) de Safari** :
- Le stockage *script-writable* — `localStorage`, `sessionStorage`, **IndexedDB**, enregistrements de service worker, Cache API — est **purgé après 7 jours sans interaction utilisateur**.
- Les cookies posés par le serveur via l'en-tête `Set-Cookie` (HttpOnly) **ne sont PAS soumis à ce plafond de 7 jours**. Seuls les cookies écrits en JavaScript (`document.cookie`) sont plafonnés à 7 jours.

Conséquence concrète : un token Bearer stocké en IndexedDB déconnecte l'utilisateur après une semaine d'inactivité. Un cookie de session HttpOnly + cookie `remember_me` survit. Pour une app « je scanne mes papiers quand j'en ai » (usage sporadique par nature), c'est rédhibitoire.

Autres arguments :
- La PWA est servie par Laravel lui-même → **même origine** → zéro CORS, zéro `supports_credentials`, zéro sous-domaine à gérer. Sanctum SPA exige le même domaine de premier niveau : c'est trivialement satisfait ici.
- HttpOnly protège contre l'exfiltration par XSS ; un token en localStorage/IndexedDB est lisible par tout script injecté.
- Depuis iOS 16.4, une PWA installée sur l'écran d'accueil possède **son propre store de données isolé de Safari** : la session de la PWA est indépendante de l'onglet Safari (l'utilisateur devra se connecter une fois dans la PWA installée, c'est normal).
- `SameSite=Lax` suffit en same-origin. Ne pas passer à `None` sans nécessité.

Config `.env` :
```
APP_URL=https://papers.example.com
SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=papers.example.com
```
`php artisan make:session-table && php artisan migrate`.

`bootstrap/app.php` : `$middleware->statefulApi();`

Flux de login côté PWA : `GET /sanctum/csrf-cookie` → puis `POST /login` avec `X-XSRF-TOKEN` (valeur du cookie `XSRF-TOKEN` **URL-décodée**) + `Accept: application/json` + `credentials: 'include'`. Utiliser `remember: true` pour obtenir le cookie `remember_web_*` (5 ans, posé par le serveur donc non plafonné).

**Astuce dev tunnel** : quand l'URL du tunnel change (quick tunnel Cloudflare), ajouter `Sanctum::currentRequestHost()` dans `config/sanctum.php` → `stateful` : le host de la requête courante devient stateful à l'exécution. Indispensable sinon 401 systématique en dev.

Fortify vs Breeze : **Fortify** (headless, pas de vues) est le bon choix — il fournit `/login`, `/register`, `/forgot-password`, `/user/two-factor-authentication` en JSON pur, sans scaffolding Blade. Breeze est un scaffolding one-shot, moins adapté à une PWA custom.

Garder Sanctum en mode token **en plus**, uniquement pour un éventuel client tiers ou un worker (`createToken('caldav-sync', ['calendar:write'], now()->plus(weeks: 1))`), avec `Schedule::command('sanctum:prune-expired --hours=24')->daily()`.

Pièges service worker : ne **jamais** mettre en cache les réponses des routes d'auth ni les requêtes POST. Laisser passer `/login`, `/logout`, `/sanctum/*`, `/api/*` en network-only. Une 419 (CSRF expiré) doit déclencher un re-fetch de `/sanctum/csrf-cookie` puis un retry, pas une déconnexion.

# 3. Bun dans un projet Laravel 13 — **support officiel de première classe**

Version vérifiée : **Bun v1.4.2 (2026-09-05)**. `laravel-vite-plugin` **3.2.0**, `vite` **8.3.0**.

## Découverte majeure (vérifiée dans le code source du framework)

Laravel 13 **détecte Bun nativement**. Le fichier `Illuminate/Support/NodePackageManager.php` teste dans l'ordre `Bun`, `Pnpm`, `Yarn`, sinon `Npm`. Et `Illuminate/Support/NodePackageManagers/Bun.php` :
```php
public static function matches(): bool
{
    return array_any(['bun.lock', 'bun.lockb'], fn ($f) => file_exists(getcwd().'/'.$f));
}
public function getRunCommand(string $c): string { return "bun run {$c}"; }
public function getExecCommand(string $c): string { return "bunx {$c}"; }
```
Donc : **il suffit de faire `bun install` une fois** (ce qui crée `bun.lock`) et tout le tooling Laravel — notamment `php artisan dev` — bascule automatiquement sur `bun run` / `bunx`. Aucune config à écrire.

## Ce que fait `php artisan dev` (nouveau en 13)

`composer run dev` appelle `@php artisan dev`, qui lance en parallèle :
- `php artisan serve`
- `php artisan queue:listen --tries=1 --timeout=0`
- `php artisan pail --timeout=0` (seulement si `pcntl_fork` existe → **pas sur Windows**)
- `<package-manager> run dev` → donc `bun run dev` → `vite`

Sur **Windows** le multiplexage passe par `concurrently` (déjà en devDependencies) au lieu de `@laravel/multiplex` (qui est en `optionalDependencies`). C'est géré par `match (PHP_OS_FAMILY) { 'Windows' => runViaConcurrently(...), default => runViaMultiplex(...) }`. Rien à faire.

## Bun comme package manager : OUI, sans réserve

```
bun install
bun add -d @tailwindcss/vite tailwindcss vite laravel-vite-plugin
```
Gains réels : installs ~10-20x plus rapides que npm, lockfile texte `bun.lock` (diffable en revue), `bun why`, `bun update --interactive`.

## Bun comme runtime pour le build Vite : **NON par défaut**

`bunx --bun vite` force Vite à tourner sur le runtime Bun au lieu de Node (sans `--bun`, Bun respecte le shebang `#!/usr/bin/env node` de Vite).

**Piège vérifié** : le HMR de Vite est **régulièrement cassé sous `--bun`** — les changements de fichiers sont détectés et les modules régénérés, mais le hot-reload ne se déclenche pas ; le problème disparaît en repassant sur Node. Il existe aussi un historique d'incompatibilités entre `laravel-vite-plugin` v1+ et Bun (issue laravel/vite-plugin#278).

Noter que Laravel lui-même émet `bunx vite` et **pas** `bunx --bun vite` : le choix par défaut du framework est Bun pour la gestion de paquets, Node pour l'exécution de Vite. C'est exactement la recommandation à suivre.

Noter aussi que la doc Vite de Laravel 13 dit toujours « You must ensure that Node.js (16+) and NPM are installed » — le support Bun est réel côté code mais la doc Vite n'a pas été mise à jour (la page Installation, elle, mentionne « Node and NPM or Bun »).

**Recommandation** : `bun install` + `bun run dev` (Vite sur Node). N'essayer `bunx --bun vite build` que pour le build de prod, en CI, et seulement si on mesure un gain — en vérifiant l'output. Ne jamais mettre `--bun` sur le `dev`.

## Bun comme runtime serveur dans ce projet : **NON — ne pas le faire**

Analyse par cas d'usage :

1. **Worker de traitement d'image** — inutile. Le vrai scan (détection de bords, correction de perspective, binarisation) doit tourner **côté client** dans la PWA (OpenCV.js/WASM) : c'est là qu'on a l'aperçu temps réel, et ça évite d'uploader des JPEG 12 Mpx bruts depuis un iPhone 14 Plus en 4G. Côté serveur, il ne reste que du redimensionnement/ré-encodage (miniatures, normalisation) que Laravel 13 fait nativement via `Image::` + Imagick dans un job. Ajouter un service Bun ici, c'est un deuxième runtime, un deuxième déploiement, un protocole inter-process à écrire, et des credentials à partager — pour zéro gain.

2. **SSE / WebSocket** — inutile. Laravel a **Reverb** (WebSocket first-party, PHP). Et surtout, l'AI SDK expose déjà `->stream()` avec `->usingVercelDataProtocol()` pour streamer la réponse du LLM en SSE directement depuis Laravel.

3. **Service de rendu** — pas de besoin identifié (pas de SSR : c'est une PWA client-side).

Le seul cas où un service Bun se défendrait : un OCR/CV lourd pour lequel il existe une lib JS/WASM sans équivalent PHP et qu'on refuse de faire côté client. Ce n'est pas le cas ici. **Conclusion : Bun reste un outil de build. Un seul runtime serveur : PHP.**

# 4. PostgreSQL

**Version recommandée : PostgreSQL 18** (18.0 le 25/09/2025 ; dernier mineur stable vérifié **18.6 du 13/08/2026**). PG 18 apporte un nouveau sous-système I/O (asynchrone) donné jusqu'à 3x plus rapide en lecture disque. Attention : **la 18.5 n'a jamais été publiée** (régression détectée après wrap) — ne pas la chercher, aller directement en 18.6.

Config `.env` :
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=papers
DB_USERNAME=papers
DB_PASSWORD=secret
```
Extension PHP requise : `pdo_pgsql` (+ `pgsql`). Sur Windows, décommenter dans `php.ini`.

## Recherche plein texte française — détails vérifiés dans le code source

Signature réelle : `whereFullText($columns, $value, array $options = [], $boolean = 'and')`.

Le `PostgresGrammar::whereFullText()` lit `$options['language']` (défaut `'english'`), `$options['mode']` et `$options['vector']`. Modes supportés : défaut `plainto_tsquery`, `'phrase'` → `phraseto_tsquery`, `'websearch'` → `websearch_to_tsquery`, `'raw'` → `to_tsquery`.

**`'french'` EST dans la whitelist `validFullTextLanguages()`** (aux côtés de simple, english, german, spanish, italian, etc.).

**PIÈGE MAJEUR** : si la langue passée n'est pas dans cette whitelist, **elle est silencieusement remplacée par `'english'`** — aucune exception, aucun warning. Donc une configuration TS custom du type `fr_unaccent` (nécessaire pour ignorer les accents) **sera ignorée** par `whereFullText()`. Deux solutions :
- **(recommandé)** créer une colonne `tsvector` générée stockée avec la config custom, et interroger avec `whereFullText('search_vector', $q, ['vector' => true, 'mode' => 'websearch'])`. L'option `vector => true` fait que Laravel utilise la colonne **telle quelle** au lieu de l'envelopper dans `to_tsvector('...', col)`. C'est le mécanisme prévu exactement pour ça.
- ou passer en `DB::raw()` complet.

Sur l'immutabilité : `unaccent()` n'est **pas** IMMUTABLE par défaut, donc inutilisable directement dans une colonne générée ou un index d'expression. La bonne solution n'est pas de wrapper `unaccent()` dans une fonction custom, mais de créer une **TEXT SEARCH CONFIGURATION** `fr_unaccent` qui chaîne le dictionnaire `unaccent` avant `french_stem` : `to_tsvector('fr_unaccent', col)` est alors immutable (la variante `to_tsvector(regconfig, text)` l'est) et utilisable en colonne générée.

`pg_trgm` reste utile en complément pour le fuzzy/typo sur les noms d'émetteurs (`similarity()`, index GIN `gin_trgm_ops`) — le FTS ne tolère pas les fautes de frappe.

## Index dans les migrations

- `$table->index(['col'], 'nom')->algorithm('gin')` → `create index "nom" on "t" using gin ("col")`. Vérifié dans `compileIndex()` : `$command->algorithm ? ' using '.$command->algorithm : ''`.
- `->online()` → ajoute `CONCURRENTLY` (PostgreSQL) — utile en prod, **interdit dans une transaction**, donc migration avec `public $withinTransaction = false;`.
- `$table->jsonb('col')` existe nativement.
- `$table->tsvector('col')` existe nativement (`typeTsvector`).
- `->storedAs($expression)` est supporté sur PostgreSQL (colonne générée STORED).

## pgvector

Version : **pgvector 0.8.2 publiée le 26/02/2026**. Les tags Docker `pgvector/pgvector:pg18` existent. **CVE-2026-3172 corrigée en 0.8.2** : buffer overflow lors des builds d'index HNSW parallèles, pouvant fuiter des données d'autres relations ou crasher le serveur. **Ne pas déployer une version < 0.8.2.**

`halfvec` (float 2 octets) permet d'indexer jusqu'à 4000 dimensions contre 2000 pour `vector` — utile si on passe à `text-embedding-3-large` (3072 dims), qui **ne rentre pas** dans un index HNSW `vector` standard.

**PIÈGE DOC vs CODE — important.** La doc Laravel 13 écrit :
```php
$table->vector('embedding', dimensions: 1536)->index();
```
Or `->index()` crée un **index btree**, totalement inutile pour la recherche vectorielle. La vraie méthode est **`vectorIndex()`**, vérifiée dans `Blueprint.php` :
```php
public function vectorIndex($column, $name = null)
{
    [$algorithm, $operatorClass] = $this->grammar instanceof MariaDbGrammar
        ? [null, 'M=6 DISTANCE=cosine']
        : ['hnsw', 'vector_cosine_ops'];
    return $this->indexCommand('vectorIndex', $column, $name, $algorithm, $operatorClass);
}
```
→ génère `create index ... using hnsw ("embedding" vector_cosine_ops)`. **Utiliser `->vectorIndex()` (ou `$table->vectorIndex('embedding')`), pas `->index()`.**

Cohérent avec l'opérateur de distance : `compileVectorDistanceExpression()` retourne `("col" <=> ?)`, c'est-à-dire la **distance cosinus** de pgvector. D'où `vector_cosine_ops`. Si vous créez l'index à la main, n'utilisez surtout pas `vector_l2_ops`, l'index ne serait jamais utilisé.

# 5. Files d'attente

## database vs Redis

**Redis + Horizon** (`laravel/horizon v5.49.0`, 2026-09-07). Raisons : les jobs OpenAI durent 10-60 s, le driver `database` fait du polling avec `SELECT ... FOR UPDATE SKIP LOCKED` et devient contentieux ; Horizon donne le monitoring temps réel, les métriques de temps d'attente, le balancing auto et le retry en un clic — indispensable quand un job coûte de l'argent (appel API) à chaque retry. Horizon **exige** `QUEUE_CONNECTION=redis` et **n'est pas compatible Redis Cluster**. La connexion Redis nommée `horizon` est réservée.

`database` reste acceptable en dev solo. Si vous y restez : `php artisan make:queue-table && php artisan migrate`.

## Réglages pour les jobs OpenAI

Utiliser les nouveaux attributs. Règle d'or des timeouts, source classique de jobs fantômes :
**`retry_after` (config/queue.php) > `--timeout` (worker) > `#[Timeout]` (job)**. Si `retry_after` < timeout, le job est redistribué à un second worker **pendant qu'il tourne encore** → double appel OpenAI, double facturation.

**PIÈGE WINDOWS** : le timeout de job Laravel repose sur `pcntl_alarm`. **`pcntl` n'existe pas sur Windows** → `#[Timeout]` et `--timeout` ne sont **pas appliqués** par un worker lancé sur Windows natif. Un appel OpenAI qui pend bloque le worker indéfiniment. Mitigation : imposer un timeout au niveau du client HTTP (`Http::timeout(120)->connectTimeout(10)`) — c'est de toute façon une bonne pratique, et c'est la seule protection réelle sur Windows.

Pour le rate limit OpenAI (429), `ThrottlesExceptions` est le bon outil, pas le retry brut.

## Batching multipage

`Bus::batch()` est fait pour ça : un job par page (prétraitement + OCR) puis `->then()` qui déclenche l'analyse LLM sur le document complet. Prérequis : `php artisan make:queue-batches-table && php artisan migrate`, trait `Batchable`, et vérifier `$this->batch()->cancelled()` en début de `handle()`. Utiliser `->allowFailures()` pour qu'une page ratée n'annule pas le document entier.

Alternative si les pages doivent être traitées dans l'ordre : `Bus::chain()`. Pour un scan multipage, le batch est préférable (parallélisable).

`->onConnection('deferred')` (nouveau) exécute après l'envoi de la réponse HTTP sans passer par la queue — pratique pour un accusé de réception léger.

# 6. Stockage de fichiers

**Local en dev, S3 (ou compatible : R2/MinIO/Scaleway) en prod.** Les documents sont des données personnelles sensibles (factures, courriers administratifs, santé) — pas de disque `public`, jamais de `storage:link` pour ces fichiers.

Points vérifiés :
- `Storage::temporaryUrl($path, now()->plus(minutes: 5))` fonctionne sur `local` **et** `s3`. Pour le driver local il faut `'serve' => true` dans la config du disque.
- `buildTemporaryUrlsUsing()` permet de rediriger vers une route signée custom (`URL::temporarySignedRoute`) — utile pour ajouter un contrôle de policy avant de servir.
- URLs d'upload temporaires (`temporaryUploadUrl`) supportées sur `s3` et `local` → upload direct depuis l'iPhone vers S3, sans faire transiter un scan multipage par PHP. Recommandé.

**Miniatures** — natif en Laravel 13, `composer require intervention/image:^4.0` (**4.3.2** vérifié), `IMAGE_DRIVER=imagick` (meilleure qualité que GD pour les documents). API : `Image::fromStorage($p, 'documents')->orient()->scale(width: 400)->toWebp()->quality(70)->store('thumbs', 'documents')`. `->orient()` applique la rotation EXIF — **indispensable** avec les photos iPhone, sinon les miniatures sont couchées. La doc avertit explicitement de faire ça en job queue, pas dans la requête HTTP.

**Chiffrement au repos** : trois niveaux, à combiner.
- Métadonnées extraites (montants, IBAN, noms) : cast `encrypted` / `encrypted:array` / `encrypted:collection` sur le modèle → AES-256-CBC + MAC via `APP_KEY`. Contrepartie : **une colonne chiffrée n'est ni indexable ni interrogeable** — ne pas chiffrer ce qui alimente `search_vector`.
- Fichiers : `Crypt::encrypt()` avant écriture est possible mais casse le streaming et double la mémoire sur un PDF multipage. Préférer **SSE-S3/SSE-KMS côté S3** (transparent, indexable, pas de charge PHP), ou LUKS/BitLocker pour du local.
- `APP_KEY` : rotation via `APP_PREVIOUS_KEYS` (déchiffrement avec les anciennes clés). Perdre `APP_KEY` = perdre définitivement toutes les données chiffrées. À sauvegarder hors dépôt.

# 7. Scheduler (synchro CalDAV + rappels)

Déclaration dans `routes/console.php` via la façade `Schedule` (ou `->withSchedule()` dans `bootstrap/app.php`).

Méthodes clés pour ce projet : `->everyFiveMinutes()`, `->withoutOverlapping(10)` (verrou via le cache, **obligatoire** pour CalDAV : une synchro lente ne doit pas se superposer), `->onOneServer()` (nécessite cache database/redis/memcached/dynamodb), `->runInBackground()`, `->timezone('Europe/Paris')`, `->onFailure()`, `->evenWhenPaused()`.

Tâches sub-minute disponibles (`->everyTenSeconds()`), mais la doc recommande explicitement de ne les utiliser que pour **dispatcher des jobs**, jamais pour faire le travail.

Config globale : `'schedule_timezone' => 'Europe/Paris'` dans `config/app.php`. Avertissement de la doc : le passage heure d'été/hiver peut faire tourner une tâche deux fois ou zéro fois — donc rendre les jobs de rappel **idempotents** (clé d'unicité sur `reminder_id + occurrence`), ne pas se fier à l'horloge.

Exécution : en prod un seul cron `* * * * * cd /path && php artisan schedule:run`. **En local sur Windows : `php artisan schedule:work`** (processus au premier plan, pas de Planificateur de tâches à configurer). `php artisan schedule:list` pour vérifier, `schedule:interrupt` au déploiement.

# 8. Docker Compose sur Windows 11

**Laravel Sail n'est PAS pertinent ici** (`laravel/sail v1.67.0`). Sail met **PHP lui-même** dans un conteneur, ce qui sur Windows impose WSL2 et, si le code est sur `C:\`, un passage par le système de fichiers 9p → I/O catastrophiques (Composer, `artisan`, Vite). L'utilisateur a déjà **PHP 8.5 natif sur Windows**.

**Architecture recommandée : Docker uniquement pour Postgres + Redis ; PHP, Bun et Vite en natif sur Windows.** On garde les perfs I/O natives, le débogage Xdebug direct, et on n'a en conteneur que ce qui est pénible à installer sur Windows.

Utiliser l'image `pgvector/pgvector:pg18` (tag vérifié) plutôt que `postgres:18` : pgvector est déjà compilé dedans, sinon `CREATE EXTENSION vector` échoue avec « extension not available ». Healthcheck `pg_isready` + `depends_on: condition: service_healthy` pour que `artisan migrate` ne parte pas trop tôt.

# 9. HTTPS en dev pour tester sur un vrai iPhone

Contrainte absolue : `getUserMedia()` n'est disponible qu'en **secure context** (HTTPS, ou `http://localhost`). Un iPhone qui tape l'IP LAN du PC (`http://192.168.1.x:8000`) **n'est ni l'un ni l'autre** → pas de caméra. HTTPS est donc obligatoire, pas optionnel.

**Recommandation pour Windows : Cloudflare Tunnel *nommé* (avec un hostname stable).**

Comparaison :
- **mkcert** : génère un certificat de confiance, mais la CA racine est installée dans le magasin du **PC**, pas de l'iPhone. Il faut exporter `rootCA.pem`, le transférer sur l'iPhone, l'installer comme profil, **puis** l'activer manuellement dans Réglages → Général → Informations → Réglages des certificats. Fastidieux, à refaire à chaque appareil, et il faut aussi servir **Vite** en HTTPS avec le même certificat. Utilisable, mais c'est le chemin le plus long.
- **Quick tunnel Cloudflare** (`cloudflared tunnel --url http://localhost:8000`, sans compte) : URL `*.trycloudflare.com` en 2 secondes. **Mais l'URL change à chaque redémarrage.** Rédhibitoire pour une PWA : une PWA installée est liée à son origine/scope — si l'origine change, l'app installée est morte, le service worker et le stockage sont perdus, et il faut réinstaller. Cloudflare qualifie explicitement ces tunnels de dev/test, best-effort, non destinés à rester up plusieurs jours.
- **Tunnel nommé Cloudflare** : hostname stable sur un domaine que vous possédez, certificat valide automatique, aucun port à ouvrir, binaire Windows natif. **C'est l'option correcte.** L'origine ne bouge jamais → installation PWA, service worker, IndexedDB et session persistent entre les sessions de dev.
- **Tailscale Funnel** : hostname stable `*.ts.net`, HTTPS auto, Windows + iOS supportés. Bonne alternative si vous ne possédez pas de domaine. La fonctionnalité Funnel (exposition publique) est encore en **beta**. Le mode réseau privé (sans Funnel) ne fournit pas de certificat public utilisable tel quel.
- **ngrok** : marche, mais URL aléatoire en gratuit (même problème que les quick tunnels).

Points de config indispensables avec un tunnel :
- `APP_URL=https://dev.mondomaine.com` — le `laravel-vite-plugin` autorise automatiquement l'origine de `APP_URL` en CORS (les origines auto-autorisées sont `::1`, `127.0.0.1`, `localhost`, `*.test`, `*.localhost` et `APP_URL`).
- Vite : `server.host: '0.0.0.0'`, `server.hmr.host` = le hostname du tunnel, sinon le client HMR tente `localhost:5173` depuis l'iPhone et échoue.
- `SESSION_SECURE_COOKIE=true` et `SANCTUM_STATEFUL_DOMAINS` = hostname du tunnel.
- Faire passer **deux** ports dans le tunnel (8000 pour Laravel, 5173 pour Vite), ou plus simple : `bun run build` + `php artisan serve` et se passer du HMR pour les tests iPhone.

**Pièges caméra iOS en mode standalone à connaître** (à remonter à l'agent PWA) : les décisions de permission caméra **ne sont pas persistées** pour les PWA installées, la permission est **révoquée à chaque changement de hash** de l'URL (bug WebKit 215884) — donc utiliser le History API, jamais de routage par hash ; et depuis **iOS 26** des rapports de flux caméra **tourné de 90°** en mode standalone ont été signalés (prévoir une correction d'orientation côté canvas).

# 10. Tests

**Pest 5** (et non Pest 4 comme l'affirment plusieurs blogs). Vérifié sur Packagist : `pestphp/pest v5.2.1` publié **le 17/09/2026**, contrainte `php: ^8.4`. `pestphp/pest-plugin-laravel v5.0.1` requiert `laravel/framework: ^13.23.0`. Pest 5 tourne sur **PHPUnit 13**.

PHP 8.4 minimum → le PHP 8.5 de l'utilisateur convient. Attention en revanche : Laravel 13 accepte PHP 8.3, mais **Pest 5 non**.

Nouveautés Pest 5 : moteur **TIA (Test Impact Analysis)** — n'exécute que les tests impactés par le diff et rejoue le cache pour le reste (suite de 19 000 tests de Laravel Cloud passée de 3 min à 5 s), détecte les changements de migrations et de vues Blade ; **plugin Agent** (commande unique de vérification pour agents IA) ; **Evals** pour scorer des sorties LLM depuis `expect()` — directement pertinent pour tester la qualité de l'extraction de documents ; plugin **PHPStan** first-party.

Le **browser testing** (Playwright, `--parallel`, `--shard`), introduit en Pest 4, remplace Laravel Dusk.

Installation : `composer require pestphp/pest pestphp/pest-plugin-laravel --dev` puis `./vendor/bin/pest --init`. Sanctum fournit `Sanctum::actingAs($user, ['*'])`.

Autres outils vérifiés : `laravel/pint v1.32.1`, `laravel/telescope v5.24.0`, `laravel/scout v11.7.0`.

# 11. Laravel AI SDK — avertissement de maturité

`laravel/ai` est en **v0.11.2 (2026-09-03)** : **pré-1.0**. L'API peut casser entre mineures. Épingler strictement (`"laravel/ai": "0.11.2"`) et relire le changelog à chaque montée. C'est le point le plus instable de la stack.

`composer require laravel/ai`, puis `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"` et `php artisan migrate`. Env : `OPENAI_API_KEY`. Providers embeddings disponibles : OpenAI, Gemini, Azure, Bedrock, Cohere, Mistral, Jina, VoyageAI, Ollama, OpenRouter. Reranking : Cohere, Jina, VoyageAI, Bedrock uniquement (**pas OpenAI**) — si vous voulez le pattern « retrieve then rerank », il faut une clé chez un de ces quatre.

Générateurs : `php artisan make:agent NomAgent --structured`, `php artisan make:tool NomTool`.

**Cohérence dimensions ↔ colonne** : `config/ai.php` → `providers.openai.models.embeddings.dimensions` doit correspondre **exactement** à `$table->vector('embedding', dimensions: N)`. Un mismatch produit une erreur Postgres à l'insertion, pas à la config. `text-embedding-3-small` = 1536.

`whereVectorSimilarTo('embedding', 'requête en texte')` génère l'embedding automatiquement → **un appel API facturé par requête de recherche**. En pratique : mettre en cache l'embedding de la requête, et préférer passer un `$queryEmbedding` explicite.

## Faits clés vérifiés

- Laravel 13 est sorti le 17 mars 2026. Derniere release verifiee: laravel/framework v13.32.0 (2026-09-15), contrainte php ^8.3. Matrice officielle: Laravel 13 = PHP 8.3 a 8.5. Bugfix Q3 2027, securite 17 mars 2028.
- Le squelette laravel/laravel 13.x requiert laravel/framework ^13.17 et laravel/tinker ^3.0. package.json par defaut: vite ^8.0.0, laravel-vite-plugin ^3.1, tailwindcss ^4.0.0, concurrently ^10.0.3, et @laravel/multiplex ^0.4.1 en optionalDependencies.
- Structure Laravel 13: pas de app/Http/Kernel.php ni app/Console/Kernel.php. Tout dans bootstrap/app.php (withRouting, withMiddleware, withExceptions, withSchedule, withBroadcasting). routes/api.php n'existe qu'apres php artisan install:api.
- VERIFIE DANS LE CODE SOURCE: Laravel 13 detecte Bun nativement. Illuminate/Support/NodePackageManager.php teste Bun, Pnpm, Yarn puis Npm. Bun::matches() retourne vrai si bun.lock ou bun.lockb existe. Il suffit de faire bun install une fois.
- php artisan dev (nouveau en Laravel 13, remplace le script concurrently) lance: artisan serve, artisan queue:listen --tries=1 --timeout=0, artisan pail (seulement si pcntl_fork existe donc PAS sur Windows), et <pm> run dev. Sur Windows le multiplexage passe par concurrently, ailleurs par @laravel/multiplex.
- Laravel emet 'bunx <cmd>' et non 'bunx --bun <cmd>': le choix par defaut du framework est Bun pour les paquets, Node pour executer Vite.
- Versions verifiees: Bun v1.4.2 (2026-09-05), Vite 8.3.0, laravel-vite-plugin 3.2.0 (2026-08-11), laravel/sanctum v4.3.3, laravel/horizon v5.49.0, laravel/fortify v1.39.0, laravel/sail v1.67.0, laravel/scout v11.7.0, laravel/pint v1.32.1, laravel/telescope v5.24.0, intervention/image 4.3.2.
- laravel/ai est en v0.11.2 (2026-09-03), donc PRE-1.0. API instable entre mineures. Installation: composer require laravel/ai + vendor:publish AiServiceProvider + migrate.
- Pest est en v5.2.1 (publie le 17/09/2026), contrainte php ^8.4, tourne sur PHPUnit 13. pest-plugin-laravel v5.0.1 requiert laravel/framework ^13.23.0. Pest 5 apporte le moteur TIA, le plugin Agent, les Evals et un plugin PHPStan first-party.
- PostgreSQL 18 est la version recommandee. 18.0 le 25/09/2025, dernier mineur stable 18.6 du 13/08/2026. La 18.5 n'a JAMAIS ete publiee (regression apres wrap).
- pgvector 0.8.2 publie le 26/02/2026 corrige CVE-2026-3172: buffer overflow sur les builds d'index HNSW paralleles pouvant fuiter des donnees d'autres relations ou crasher le serveur. Ne pas deployer < 0.8.2.
- Laravel 13 a la recherche vectorielle NATIVE: Schema::ensureVectorExtensionExists(), $table->vector('embedding', dimensions: 1536), cast Illuminate\Database\Eloquent\Casts\AsVector, whereVectorSimilarTo(), whereVectorDistanceLessThan(), selectVectorDistance(), orderByVectorDistance(). AUCUN package pgvector tiers necessaire.
- VERIFIE DANS Blueprint.php: la methode d'index HNSW est vectorIndex() et non index(). vectorIndex() produit ['hnsw', 'vector_cosine_ops'] sur PostgreSQL. La doc officielle montre ->index() ce qui creerait un btree inutile.
- VERIFIE: PostgresGrammar::compileVectorDistanceExpression() retourne ("col" <=> ?), c'est-a-dire la distance COSINUS de pgvector. D'ou l'operator class vector_cosine_ops. Un index vector_l2_ops ne serait jamais utilise.
- Signature reelle verifiee: whereFullText($columns, $value, array $options = [], $boolean = 'and'). Options lues par PostgresGrammar: 'language' (defaut 'english'), 'mode' et 'vector'.
- Modes FTS supportes: defaut plainto_tsquery, 'phrase' -> phraseto_tsquery, 'websearch' -> websearch_to_tsquery, 'raw' -> to_tsquery.
- 'french' EST dans la whitelist validFullTextLanguages() de PostgresGrammar (avec simple, english, german, spanish, italian, portuguese, russian, etc.).
- L'option ['vector' => true] de whereFullText fait que Laravel utilise la colonne TELLE QUELLE au lieu de l'envelopper dans to_tsvector('lang', col). C'est le mecanisme prevu pour interroger une colonne tsvector generee stockee.
- $table->tsvector('col') et $table->jsonb('col') existent nativement dans le Blueprint Laravel 13 (typeTsvector, typeJsonb).
- $table->index(['col'], 'nom')->algorithm('gin') genere: create index "nom" on "t" using gin ("col"). Verifie dans compileIndex(): $command->algorithm ? ' using '.$command->algorithm : ''.
- ->online() sur un index ajoute CONCURRENTLY sur PostgreSQL (et WITH (online = on) sur SQL Server). Necessite public $withinTransaction = false dans la migration.
- ->storedAs($expression) est supporte sur PostgreSQL (colonne generee STORED). ->virtualAs() ne l'est PAS sur PostgreSQL.
- ITP Safari: le stockage script-writable (localStorage, sessionStorage, IndexedDB, service worker registrations, Cache API) est purge apres 7 jours sans interaction. Les cookies poses par le serveur via l'en-tete Set-Cookie ne sont PAS soumis a ce plafond. C'est l'argument decisif pour Sanctum SPA cookies plutot que tokens Bearer.
- Depuis iOS 16.4 une PWA installee sur l'ecran d'accueil possede son propre store de donnees isole de Safari.
- Sanctum expose Sanctum::currentRequestHost() a mettre dans config/sanctum.php 'stateful': injecte le host de la requete courante a l'execution. Indispensable avec un tunnel dont l'URL change.
- Laravel 13 a la manipulation d'images first-party via la facade Illuminate\Support\Facades\Image, propulsee par Intervention Image v4 (composer require intervention/image:^4.0), drivers gd ou imagick via IMAGE_DRIVER. Methodes: orient(), scale(), cover(), contain(), crop(), rotate(), blur(), sharpen(), grayscale(), toWebp(), quality(), optimize(), store(), storeAs(), storePublicly(), dimensions(), dominantColor().
- Storage::temporaryUrl($path, $expiration) fonctionne sur les drivers local ET s3. Pour local il faut 'serve' => true dans la config du disque. buildTemporaryUrlsUsing() permet de rediriger vers une route signee custom.
- Les URLs d'upload temporaires (temporaryUploadUrl) sont supportees par s3 et local: permet l'upload direct depuis l'iPhone vers S3 sans faire transiter les scans par PHP.
- Horizon exige QUEUE_CONNECTION=redis et n'est PAS compatible Redis Cluster. La connexion Redis nommee 'horizon' est reservee et ne doit pas etre reutilisee.
- Attributs de job Laravel 13 dans Illuminate\Queue\Attributes: #[Tries], #[MaxExceptions], #[Timeout], #[Backoff(initial, max, multiplier)], #[FailOnTimeout], #[UniqueFor], #[DebounceFor(seconds, maxWait:)].
- Queue::route(ProcessPodcast::class, connection: 'redis', queue: 'podcasts') centralise le routage des jobs (nouveau en 13). Aussi Queue::forward() et le driver de connexion 'failover'.
- Batching: php artisan make:queue-batches-table, trait Illuminate\Bus\Batchable, Bus::batch([...])->then()->catch()->finally()->allowFailures()->dispatch(), verification de $this->batch()->cancelled() dans handle().
- Regle des timeouts: retry_after (config/queue.php) > --timeout (worker) > #[Timeout] (job). Sinon le job est redistribue pendant qu'il tourne encore = double appel OpenAI facture.
- Le scheduler se declare dans routes/console.php via la facade Schedule, ou via ->withSchedule() dans bootstrap/app.php. En local sur Windows: php artisan schedule:work (pas de cron a configurer). En prod: un seul cron * * * * * php artisan schedule:run.
- withoutOverlapping() utilise le cache pour poser un verrou (expiration 24h par defaut). onOneServer() necessite un cache database, redis, memcached ou dynamodb. schedule:clear-cache libere un verrou coince.
- getUserMedia() n'est disponible qu'en secure context: HTTPS ou http://localhost. Un iPhone tapant l'IP LAN du PC n'est ni l'un ni l'autre, donc pas de camera.
- Le laravel-vite-plugin autorise automatiquement en CORS les origines ::1, 127.0.0.1, localhost, *.test, *.localhost et la valeur de APP_URL. Sinon configurer server.cors.origin dans vite.config.js (accepte des regex).
- Image de dev recommandee: pgvector/pgvector:pg18 (tag verifie sur Docker Hub) et non postgres:18, sinon CREATE EXTENSION vector echoue avec 'extension not available'.
- Laravel 13 introduit aussi: JSON:API resources, PreventRequestForgery (CSRF avec verification d'origine), Cache::touch(), attributs #[Middleware] et #[Authorize] sur les controleurs, et le reranking via Laravel AI SDK.

## Pièges / ce qui ne marche PAS

- PIEGE N.1 (doc vs code): la doc Laravel 13 ecrit $table->vector('embedding', dimensions: 1536)->index() mais ->index() cree un index BTREE inutile pour la recherche vectorielle. Il faut ->vectorIndex() (verifie dans Blueprint::vectorIndex() qui produit hnsw + vector_cosine_ops).
- PIEGE N.2: whereFullText() remplace SILENCIEUSEMENT la langue par 'english' si elle n'est pas dans validFullTextLanguages(). Aucune exception, aucun warning. Une configuration TS custom type 'fr_unaccent' sera donc ignoree. Solution: colonne tsvector generee + whereFullText($col, $q, ['vector' => true]).
- unaccent() n'est PAS IMMUTABLE par defaut, donc inutilisable directement dans une colonne generee ou un index d'expression. La bonne solution n'est pas de wrapper unaccent() mais de creer une TEXT SEARCH CONFIGURATION chainant le dictionnaire unaccent avant french_stem: to_tsvector('fr_unaccent', col) est alors immutable.
- PIEGE WINDOWS MAJEUR: les timeouts de job Laravel reposent sur pcntl_alarm. pcntl n'existe PAS sur Windows, donc #[Timeout] et --timeout ne sont PAS appliques par un worker Windows natif. Un appel OpenAI qui pend bloque le worker indefiniment. Seule protection reelle: Http::timeout(120)->connectTimeout(10) au niveau du client HTTP.
- Si retry_after < --timeout, le job est redistribue a un second worker PENDANT qu'il tourne encore: double appel OpenAI, double facturation. Respecter retry_after > --timeout > #[Timeout].
- Le HMR de Vite est regulierement casse sous bunx --bun vite: les changements sont detectes et les modules regeneres mais le hot-reload ne se declenche pas. Disparait en repassant sur Node. NE PAS mettre --bun sur le script dev.
- Historique d'incompatibilites entre laravel-vite-plugin v1+ et Bun (issue laravel/vite-plugin#278). Le plugin est aujourd'hui en 3.2.0 et supporte Bun, mais ne pas presumer que --bun fonctionne sans le tester.
- La doc Vite de Laravel 13 dit toujours 'You must ensure that Node.js (16+) and NPM are installed'. Le support Bun est reel cote code source mais la page Vite de la doc n'a pas ete mise a jour (seule la page Installation mentionne Bun).
- Un token Bearer stocke en IndexedDB ou localStorage est EFFACE par ITP apres 7 jours d'inactivite: l'utilisateur est deconnecte. Pour une app a usage sporadique comme un scanner de papiers, c'est redhibitoire.
- Quick tunnel Cloudflare (trycloudflare.com) et ngrok gratuit: l'URL change a chaque redemarrage. Une PWA installee est liee a son origine/scope: si l'origine change, l'app installee est morte, service worker et stockage perdus, reinstallation obligatoire. Utiliser un tunnel NOMME avec hostname stable.
- mkcert installe la CA racine dans le magasin du PC, PAS de l'iPhone. Il faut exporter rootCA.pem, l'installer comme profil sur l'iPhone PUIS l'activer manuellement dans Reglages > General > Informations > Reglages des certificats. Et il faut aussi servir Vite en HTTPS avec le meme certificat.
- Camera iOS en mode standalone: les decisions de permission ne sont PAS persistees pour les PWA installees, et la permission est revoquee a chaque changement de HASH de l'URL (bug WebKit 215884). Ne jamais utiliser de routage par hash, utiliser le History API.
- Depuis iOS 26, des flux camera tournes de 90 degres ont ete signales en mode standalone via getUserMedia(). Prevoir une correction d'orientation cote canvas.
- pgvector < 0.8.2 est vulnerable a CVE-2026-3172 (buffer overflow sur builds d'index HNSW paralleles, fuite de donnees d'autres relations ou crash serveur).
- PostgreSQL 18.5 n'a jamais ete publiee (regression detectee apres le wrap). Ne pas la chercher, aller en 18.6.
- Laravel Sail sur Windows met PHP en conteneur: impose WSL2 et, si le code est sur C:, un passage par le systeme de fichiers 9p avec des I/O catastrophiques pour Composer, artisan et Vite. Inutile ici puisque PHP 8.5 est deja natif.
- Utiliser postgres:18 au lieu de pgvector/pgvector:pg18 fait echouer CREATE EXTENSION vector avec 'extension not available'.
- Les dimensions de config/ai.php (providers.openai.models.embeddings.dimensions) doivent correspondre EXACTEMENT a $table->vector('embedding', dimensions: N). Un mismatch produit une erreur Postgres a l'insertion, pas a la configuration.
- text-embedding-3-large (3072 dims) ne rentre PAS dans un index HNSW pgvector sur le type vector (limite 2000 dims). Il faut halfvec (limite 4000 dims) ou reduire les dimensions.
- whereVectorSimilarTo('embedding', 'texte de requete') genere l'embedding automatiquement: un appel API FACTURE par requete de recherche. Mettre en cache l'embedding de la requete ou passer un $queryEmbedding explicite.
- Une colonne avec le cast 'encrypted' n'est ni indexable ni interrogeable en SQL. Ne jamais chiffrer les colonnes qui alimentent search_vector ou les filtres.
- Perdre APP_KEY = perdre definitivement toutes les donnees chiffrees par le cast encrypted. Prevoir APP_PREVIOUS_KEYS pour la rotation et sauvegarder la cle hors depot.
- laravel/ai est en 0.11.2, PRE-1.0: l'API peut casser entre versions mineures. Epingler strictement la version.
- Le reranking de l'AI SDK n'est PAS disponible chez OpenAI: seulement Cohere, Jina, VoyageAI et Bedrock. Le pattern 'retrieve then rerank' necessite une cle chez l'un de ces quatre.
- Pest 5 exige PHP 8.4 minimum alors que Laravel 13 accepte PHP 8.3. Sur un projet en PHP 8.3, Pest 5 ne s'installe pas.
- Horizon n'est pas compatible Redis Cluster, et la connexion Redis nommee 'horizon' est reservee: ne pas la reutiliser dans database.php.
- php artisan pail ne tourne pas dans php artisan dev sur Windows (necessite pcntl_fork). Les logs ne s'affichent pas dans le multiplexeur, il faut lire storage/logs/laravel.log.
- Le passage heure d'ete/hiver peut faire tourner une tache planifiee deux fois ou zero fois (avertissement explicite de la doc). Rendre les jobs de rappel idempotents avec une cle d'unicite, ne pas se fier a l'horloge.
- Le service worker ne doit JAMAIS mettre en cache les routes d'auth ni les POST. Laisser /login, /logout, /sanctum/* et /api/* en network-only. Une 419 doit declencher un re-fetch de /sanctum/csrf-cookie puis un retry, pas une deconnexion.
- Le cookie XSRF-TOKEN doit etre URL-DECODE avant d'etre place dans l'en-tete X-XSRF-TOKEN. Axios et Angular HttpClient le font automatiquement, fetch() non.
- ->orient() est indispensable sur les photos iPhone: sans la rotation EXIF, toutes les miniatures sortent couchees.
- Chiffrer les fichiers avec Crypt::encrypt() avant ecriture casse le streaming et double la memoire sur un PDF multipage. Preferer SSE-S3/SSE-KMS cote S3.

## Extraits de code de référence

### Extrait 1

# Creation du projet (Windows 11, PHP 8.5 natif)
composer global require laravel/installer
laravel new papers
cd papers
php artisan install:api          # cree routes/api.php + installe Sanctum
php artisan make:session-table
php artisan make:queue-table
php artisan make:queue-batches-table
composer require laravel/ai intervention/image:^4.0 laravel/horizon laravel/fortify
composer require pestphp/pest pestphp/pest-plugin-laravel --dev
bun install                      # cree bun.lock -> Laravel bascule tout sur bun/bunx
php artisan migrate
php artisan dev                  # serve + queue:listen + bun run dev

### Extrait 2

// bootstrap/app.php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Autorise la PWA same-origin a s'authentifier par cookie de session
        $middleware->statefulApi();

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability'   => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ne jamais re-tenter un job dont l'echec est definitif (429 != 400)
        $exceptions->dontRetry([
            App\Exceptions\UnsupportedDocumentException::class,
        ]);
    })
    ->create();

### Extrait 3

# .env (dev via tunnel nomme Cloudflare)
APP_URL=https://dev.mondomaine.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=papers
DB_USERNAME=papers
DB_PASSWORD=secret

# Auth PWA : session cookie, PAS de token Bearer (ITP purge IndexedDB a 7j)
SESSION_DRIVER=database
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=dev.mondomaine.com

QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CACHE_STORE=redis

IMAGE_DRIVER=imagick
OPENAI_API_KEY=sk-...
FILESYSTEM_DISK=documents

### Extrait 4

// config/sanctum.php -- indispensable si l'URL du tunnel change en dev
use Laravel\Sanctum\Sanctum;

'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', array_filter([
    Sanctum::currentApplicationUrlWithPort(),
    // Rend stateful le host de la requete courante a l'execution :
    // sans ca, un quick tunnel a URL aleatoire renvoie 401 en boucle.
    env('APP_ENV') === 'local' ? Sanctum::currentRequestHost() : null,
])))),

'expiration' => null, // les tokens API (usage tiers uniquement) n'expirent pas par defaut

### Extrait 5

// Migration : configuration FTS francaise sans accents.
// unaccent() n'est PAS immutable -> on ne l'appelle jamais directement.
// On cree une TEXT SEARCH CONFIGURATION : to_tsvector(regconfig, text) EST immutable.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        Schema::ensureVectorExtensionExists();

        DB::statement("
            CREATE TEXT SEARCH CONFIGURATION fr_unaccent ( COPY = french )
        ");
        DB::statement("
            ALTER TEXT SEARCH CONFIGURATION fr_unaccent
            ALTER MAPPING FOR hword, hword_part, word
            WITH unaccent, french_stem
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TEXT SEARCH CONFIGURATION IF EXISTS fr_unaccent');
    }
};

### Extrait 6

// Migration documents : jsonb + tsvector genere + vector HNSW
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('ocr_text')->nullable();
            $table->text('summary')->nullable();

            // Metadonnees extraites par le LLM : requetables via ->where('extracted->iban', ...)
            $table->jsonb('extracted')->nullable();

            // Donnees sensibles : chiffrees, donc NI indexables NI requetables
            $table->text('sensitive')->nullable();

            $table->string('storage_path');
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('page_count')->default(1);
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            // Colonne tsvector generee STORED (supporte sur PostgreSQL)
            $table->tsvector('search_vector')->nullable()->storedAs(
                "to_tsvector('fr_unaccent', coalesce(title,'') || ' ' || coalesce(summary,'') || ' ' || coalesce(ocr_text,''))"
            );

            // 1536 DOIT correspondre a config('ai.providers.openai.models.embeddings.dimensions')
            $table->vector('embedding', dimensions: 1536)->nullable();

            $table->index(['user_id', 'created_at']);

            // GIN sur la colonne tsvector generee
            $table->index(['search_vector'], 'documents_search_gin')->algorithm('gin');

            // ATTENTION : ->vectorIndex() et NON ->index().
            // Genere : create index ... using hnsw ("embedding" vector_cosine_ops)
            // Coherent avec l'operateur <=> (distance cosinus) emis par Laravel.
            $table->vectorIndex('embedding', 'documents_embedding_hnsw');
        });

        // Fuzzy / tolerance aux fautes sur l'emetteur (le FTS n'en tolere aucune)
        DB::statement("
            CREATE INDEX documents_issuer_trgm ON documents
            USING gin ((extracted->>'issuer') gin_trgm_ops)
        ");
    }
};

### Extrait 7

<?php
// app/Models/Document.php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,   // <-> tableau PHP <-> type vector
            'extracted' => 'array',           // jsonb, requetable
            'sensitive' => 'encrypted:array', // AES-256-CBC + MAC, NON requetable
            'page_count' => 'integer',
        ];
    }
}

### Extrait 8

<?php
// Recherche hybride : plein texte francais + semantique, scopee par utilisateur
namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DocumentSearch
{
    public function lexical(int $userId, string $query)
    {
        return Document::query()
            ->where('user_id', $userId)
            // ['vector' => true] : utilise la colonne search_vector TELLE QUELLE,
            // sans l'envelopper dans to_tsvector('...', col).
            // Indispensable ici : 'fr_unaccent' n'est pas dans validFullTextLanguages()
            // et serait silencieusement remplace par 'english'.
            ->whereFullText('search_vector', $query, [
                'vector' => true,
                'mode'   => 'websearch', // websearch_to_tsquery : jamais d'erreur de syntaxe
            ])
            ->limit(50)
            ->get();
    }

    public function semantic(int $userId, string $query)
    {
        // On genere l'embedding NOUS-MEMES et on le cache.
        // Passer une string a whereVectorSimilarTo declencherait un appel API facture
        // a chaque frappe de l'utilisateur.
        $embedding = Cache::remember(
            'emb:'.md5($query),
            now()->addDay(),
            fn () => Str::of($query)->toEmbeddings()
        );

        return Document::query()
            ->where('user_id', $userId) // le filtre s'applique AVANT la similarite
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.35)
            ->limit(20)
            ->get();
    }
}

### Extrait 9

<?php
// app/Ai/Agents/DocumentAnalyst.php
// php artisan make:agent DocumentAnalyst --structured
namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class DocumentAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
        Tu analyses des documents administratifs francais scannes.
        Extrais les informations cles, produis un resume court,
        et liste toutes les actions a faire avec leur echeance.
        Les dates doivent etre au format ISO 8601 (AAAA-MM-JJ).
        Si aucune echeance n'est mentionnee, retourne null.
        N'invente jamais de montant ni de date.
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_type' => $schema->string()->required(),   // facture, impot, sante...
            'issuer'   => $schema->string()->nullable(),
            'summary'  => $schema->string()->required(),
            'amount'   => $schema->number()->nullable(),
            'due_date' => $schema->string()->nullable(),
            'tasks'    => $schema->array()->items(
                $schema->object([
                    'label'    => $schema->string()->required(),
                    'deadline' => $schema->string()->nullable(),
                    'priority' => $schema->integer()->min(1)->max(3)->required(),
                ])
            ),
        ];
    }
}

### Extrait 10

<?php
// app/Jobs/AnalyzeDocument.php -- appel LLM long, resilient et non re-facture
namespace App\Jobs;

use App\Ai\Agents\DocumentAnalyst;
use App\Models\Document;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

#[Tries(5)]
#[Timeout(180)]              // ATTENTION : sans effet sur un worker Windows (pas de pcntl)
#[Backoff(10, 300, 2)]       // 10s, 20s, 40s... plafonne a 300s
#[FailOnTimeout]
class AnalyzeDocument implements ShouldQueue
{
    use Queueable, Batchable;

    public function __construct(public Document $document) {}

    public function middleware(): array
    {
        return [
            // Un seul traitement a la fois par document
            (new WithoutOverlapping($this->document->id))->releaseAfter(60)->expireAfter(600),
            // Rate limit OpenAI (429) : on temporise au lieu de bruler les tentatives
            (new ThrottlesExceptions(10, 5 * 60))->backoff(5),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->plus(minutes: 45);
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $result = (new DocumentAnalyst)->prompt($this->document->ocr_text);

        $this->document->update([
            'summary'   => $result['summary'],
            'extracted' => [
                'doc_type' => $result['doc_type'],
                'issuer'   => $result['issuer'],
                'amount'   => $result['amount'],
                'due_date' => $result['due_date'],
            ],
            // Recalcule search_vector automatiquement (colonne generee STORED)
            'embedding' => Str::of($result['summary'].' '.$this->document->ocr_text)->toEmbeddings(),
            'status'    => 'analyzed',
        ]);

        foreach ($result['tasks'] as $task) {
            $this->document->tasks()->create([
                'user_id'  => $this->document->user_id,
                'label'    => $task['label'],
                'deadline' => $task['deadline'],
                'priority' => $task['priority'],
            ]);
        }
    }
}

### Extrait 11

<?php
// Batching multipage : une page = un job, puis analyse globale
use App\Jobs\AnalyzeDocument;
use App\Jobs\ProcessPage;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Throwable;

$jobs = collect($request->file('pages'))
    ->map(fn ($file, $i) => new ProcessPage($document, $file->store('scans'), $i));

Bus::batch($jobs)
    ->name("scan-doc-{$document->id}")
    ->allowFailures()          // une page ratee n'annule pas le document entier
    ->then(function (Batch $batch) use ($document) {
        // Toutes les pages OCRisees : on lance l'analyse LLM sur le texte complet
        AnalyzeDocument::dispatch($document->fresh());
    })
    ->catch(function (Batch $batch, Throwable $e) use ($document) {
        $document->update(['status' => 'failed']);
    })
    ->onQueue('scans')
    ->dispatch();

### Extrait 12

<?php
// Miniatures : natif Laravel 13, en job (l'API image est CPU/RAM intensive)
use Illuminate\Support\Facades\Image;

$thumbPath = Image::fromStorage($document->storage_path, 'documents')
    ->orient()                 // INDISPENSABLE : applique la rotation EXIF iPhone
    ->scale(width: 400)        // ne agrandit jamais
    ->toWebp()
    ->quality(70)
    ->store(path: 'thumbs', disk: 'documents');

$document->update(['thumb_path' => $thumbPath]);

// Acces securise : URL signee a duree limitee, jamais de disque public
$url = Storage::disk('documents')->temporaryUrl(
    $document->thumb_path,
    now()->plus(minutes: 10)
);

### Extrait 13

// config/filesystems.php -- disque prive + URLs temporaires locales
'documents' => [
    'driver' => 'local',
    'root'   => storage_path('app/private/documents'),
    'serve'  => true,   // requis pour temporaryUrl() sur le driver local
    'throw'  => true,
    'permissions' => [
        'file' => ['public' => 0644, 'private' => 0600],
        'dir'  => ['public' => 0755, 'private' => 0700],
    ],
],

// En prod, le meme code fonctionne avec s3 (+ SSE-KMS cote bucket) :
'documents' => [
    'driver' => 's3',
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'visibility' => 'private',
    'throw'  => true,
],

### Extrait 14

<?php
// routes/console.php -- synchro CalDAV + rappels
use App\Jobs\PushRemindersToCalendar;
use Illuminate\Support\Facades\Schedule;

// Synchro CalDAV : verrou obligatoire, une synchro lente ne doit pas se superposer
Schedule::command('caldav:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->timezone('Europe/Paris')
    ->onFailure(fn () => logger()->error('CalDAV sync failed'));

// Rappels : job idempotent (cle unique sur reminder_id + occurrence),
// car un changement d'heure ete/hiver peut declencher 0 ou 2 executions
Schedule::job(new PushRemindersToCalendar)
    ->everyFifteenMinutes()
    ->name('push-reminders')
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-batches --hours=48')->daily();

// En local sur Windows : php artisan schedule:work
// En prod : * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1

### Extrait 15

# compose.yaml -- Docker UNIQUEMENT pour la DB et Redis.
# PHP 8.5, Bun et Vite tournent en natif sur Windows (pas de Sail : PHP en
# conteneur imposerait WSL2 + systeme de fichiers 9p = I/O catastrophiques).
services:
  postgres:
    # pgvector/pgvector et NON postgres:18, sinon CREATE EXTENSION vector echoue
    image: pgvector/pgvector:pg18
    container_name: papers-pg
    restart: unless-stopped
    environment:
      POSTGRES_DB: papers
      POSTGRES_USER: papers
      POSTGRES_PASSWORD: secret
      # Collation francaise pour un tri alphabetique correct
      POSTGRES_INITDB_ARGS: "--locale=fr_FR.UTF-8 --encoding=UTF8"
    ports:
      - "127.0.0.1:5432:5432"   # bind loopback : jamais expose sur le LAN
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U papers -d papers"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 20s

  redis:
    image: redis:8-alpine
    container_name: papers-redis
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes"]
    ports:
      - "127.0.0.1:6379:6379"
    volumes:
      - redisdata:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 10

volumes:
  pgdata:
  redisdata:

### Extrait 16

// vite.config.js -- accessible depuis l'iPhone via tunnel nomme
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const TUNNEL = 'dev.mondomaine.com';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',   // sinon l'iPhone ne peut pas joindre le dev server
        port: 5173,
        hmr: {
            // Sans ca le client HMR tente localhost:5173 depuis l'iPhone et echoue
            host: TUNNEL,
            protocol: 'wss',
            clientPort: 443,
        },
        // APP_URL est auto-autorise ; on reste explicite
        cors: { origin: [`https://${TUNNEL}`] },
    },
});

### Extrait 17

// package.json -- Bun pour les paquets, Node pour executer Vite.
// NE PAS mettre --bun sur 'dev' : le HMR de Vite casse regulierement sous le runtime Bun.
{
  "private": true,
  "type": "module",
  "packageManager": "bun@1.4.2",
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  },
  "devDependencies": {
    "@tailwindcss/vite": "^4.0.0",
    "concurrently": "^10.0.3",
    "laravel-vite-plugin": "^3.2",
    "tailwindcss": "^4.0.0",
    "vite": "^8.3.0"
  }
}

### Extrait 18

// Login PWA cote client : cookies de session, pas de token stocke.
// credentials:'include' + X-XSRF-TOKEN URL-decode (fetch ne le fait pas seul).
async function login(email, password) {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' });

  const xsrf = decodeURIComponent(
    document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
  );

  const res = await fetch('/login', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-XSRF-TOKEN': xsrf,
    },
    // remember -> cookie remember_web_* pose par le SERVEUR (5 ans),
    // donc non soumis au plafond ITP de 7 jours
    body: JSON.stringify({ email, password, remember: true }),
  });

  if (!res.ok) throw new Error('Auth failed');
}

// Service worker : ces routes doivent rester en network-only.
// Une 419 = CSRF expire -> re-fetch de /sanctum/csrf-cookie puis retry,
// surtout PAS une deconnexion.
const NETWORK_ONLY = [/^\/login/, /^\/logout/, /^\/sanctum\//, /^\/api\//];

### Extrait 19

# Config queue : retry_after > --timeout > #[Timeout]
# Sinon le job est redistribue pendant qu'il tourne = double appel OpenAI facture.

# config/queue.php
'redis' => [
    'driver'      => 'redis',
    'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue'       => env('REDIS_QUEUE', 'default'),
    'retry_after' => 300,   // > --timeout
    'block_for'   => 5,
    'after_commit' => true,
],

# Worker
php artisan queue:work redis --queue=scans,default --timeout=240 --tries=3 --max-time=3600

# Sur Windows, --timeout et #[Timeout] ne sont PAS appliques (pas de pcntl).
# Seule protection reelle : borner le client HTTP.
# Http::timeout(120)->connectTimeout(10)->post(...)

# Horizon (prod)
php artisan horizon:install
php artisan horizon

### Extrait 20

# HTTPS pour tester la camera sur un vrai iPhone (Windows)
# getUserMedia exige un secure context : http://192.168.1.x:8000 ne marchera JAMAIS.
# Tunnel NOMME (hostname stable) : une PWA installee est liee a son origine,
# une URL aleatoire de quick tunnel casserait l'installation a chaque redemarrage.

winget install --id Cloudflare.cloudflared
cloudflared tunnel login
cloudflared tunnel create papers-dev
cloudflared tunnel route dns papers-dev dev.mondomaine.com

# %USERPROFILE%\.cloudflared\config.yml
# tunnel: papers-dev
# credentials-file: C:/Users/<user>/.cloudflared/<uuid>.json
# ingress:
#   - hostname: dev.mondomaine.com
#     service: http://localhost:8000
#   - service: http_status:404

cloudflared tunnel run papers-dev

# Puis, dans un autre terminal :
php artisan serve --host=0.0.0.0 --port=8000
bun run dev

# Le plus simple pour tester sur iPhone sans exposer 2 ports :
# bun run build && php artisan serve  (assets compiles, pas de HMR)

### Extrait 21

# Tests -- Pest 5 (PHP 8.4+ requis, PHPUnit 13)
./vendor/bin/pest --init
./vendor/bin/pest
./vendor/bin/pest --parallel
./vendor/bin/pest --dirty        # uniquement les fichiers modifies
php artisan test

### Extrait 22

<?php
// tests/Feature/DocumentSearchTest.php
use App\Models\Document;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('ne retourne que les documents de l utilisateur courant', function () {
    $user  = User::factory()->create();
    $other = User::factory()->create();

    Document::factory()->for($user)->create(['title' => 'Facture EDF fevrier']);
    Document::factory()->for($other)->create(['title' => 'Facture EDF mars']);

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/documents/search?q=facture+edf');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('trouve un document malgre les accents', function () {
    $user = User::factory()->create();
    Document::factory()->for($user)->create(['title' => 'Déclaration d impôts 2026']);

    Sanctum::actingAs($user);

    // fr_unaccent : 'impots' sans accent doit matcher 'impôts'
    $this->getJson('/api/documents/search?q=impots')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});


## Incertitudes

- SOURCES CONTRADICTOIRES CORRIGEES: plusieurs blogs (appcommandos.com, laravel.wiki relaye par la recherche) affirment que Laravel 13 est sorti en fevrier 2026 et exige PHP 8.4 minimum. C'est FAUX. La doc officielle laravel.com/docs/13.x/releases donne 17 mars 2026 et PHP 8.3-8.5, et composer.json du squelette confirme php ^8.3. J'ai retenu la source officielle.
- SOURCES CONTRADICTOIRES CORRIGEES: de nombreux articles de 2026 presentent Pest 4 comme la version courante. Packagist montre pestphp/pest v5.2.1 publie le 17/09/2026. Pest 5 est bien la version courante. J'ai retenu Packagist.
- SOURCES CONTRADICTOIRES sur pgvector: une source donne 0.8.2 comme derniere version (26/02/2026, postgresql.org/about/news), une autre mentionne des tags Docker 0.8.6-pg18 (aout 2026). L'appel a l'API GitHub des releases a renvoye vide. La version exacte la plus recente n'est PAS confirmee: verifier avec 'SELECT extversion FROM pg_extension WHERE extname='vector'' apres installation. Ce qui est certain: ne pas descendre sous 0.8.2 a cause de CVE-2026-3172.
- Je n'ai PAS pu lire l'issue laravel/vite-plugin#278 (WebFetch a renvoye 404, gh CLI non authentifie). Son contenu exact, les versions affectees et l'etat de resolution proviennent uniquement du resume de recherche. A verifier avant de conclure que --bun est definitivement casse ou repare.
- Je n'ai pas verifie si PostgreSQL 19 est sorti ou en beta/RC. Les majeures PG sortent generalement fin septembre; au 17/09/2026 la 18.6 est la stable a utiliser, mais une 19 pourrait apparaitre dans les jours qui suivent.
- La methode Schema::ensureVectorExtensionExists() est documentee officiellement mais je n'ai pas retrouve son implementation dans PostgresBuilder.php par grep (le nom de la methode est peut-etre ailleurs dans la hierarchie). Son existence est confirmee par la doc, pas par le code.
- Je n'ai pas verifie si whereFullText accepte une option 'language' cote Eloquent Builder de la meme facon que cote Query Builder (le passthrough est tres probable mais non teste).
- Les benchmarks de perte de performance de Sail sur Windows (facteur 9p) sont une inference d'architecture corroboree par des retours de blogs, pas une mesure que j'ai verifiee.
- Le comportement exact du stockage des PWA installees sur iOS 26 (isolation par rapport a Safari depuis iOS 16.4) provient de sources secondaires; la duree de vie reelle d'un cookie remember_me dans une PWA installee iOS 26 n'a pas ete verifiee experimentalement.
- Le rapport de camera tournee a 90 degres sur iOS 26 en mode standalone provient d'un fil du forum developpeur Apple (thread 801146) que je n'ai pas ouvert directement, seulement via le resume de recherche. A confirmer sur l'appareil cible.
- Le statut beta de Tailscale Funnel en septembre 2026 provient d'un resume de recherche et non de la doc Tailscale consultee directement.
- Je n'ai pas verifie la liste exacte des modeles d'embeddings OpenAI disponibles en septembre 2026 ni leur tarification: 'text-embedding-3-small' a 1536 dims est cite par la doc Laravel comme exemple, ce qui ne garantit pas qu'il soit le meilleur choix actuel. Un autre agent devrait verifier le catalogue OpenAI a jour.
- Le contenu exact de config/ai.php (structure des cles providers.openai.models.embeddings.dimensions) provient d'un resume WebFetch de la doc AI SDK, pas d'une lecture du fichier de config publie. A verifier apres vendor:publish.

## Sources

- https://laravel.com/docs/13.x/releases
- https://laravel.com/docs/13.x/installation
- https://laravel.com/docs/13.x/structure
- https://laravel.com/docs/13.x/sanctum
- https://laravel.com/docs/13.x/search
- https://laravel.com/docs/13.x/ai-sdk
- https://laravel.com/docs/13.x/queries
- https://laravel.com/docs/13.x/queues
- https://laravel.com/docs/13.x/migrations
- https://laravel.com/docs/13.x/filesystem
- https://laravel.com/docs/13.x/images
- https://laravel.com/docs/13.x/scheduling
- https://laravel.com/docs/13.x/vite
- https://laravel.com/docs/13.x/horizon
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Query/Grammars/PostgresGrammar.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Schema/Blueprint.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Schema/ColumnDefinition.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Query/Builder.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Support/NodePackageManager.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Support/NodePackageManagers/Bun.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Foundation/Console/DevCommand.php
- https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Foundation/DevCommands.php
- https://raw.githubusercontent.com/laravel/laravel/13.x/composer.json
- https://raw.githubusercontent.com/laravel/laravel/13.x/package.json
- https://repo.packagist.org/p2/laravel/framework.json
- https://repo.packagist.org/p2/laravel/sanctum.json
- https://repo.packagist.org/p2/laravel/horizon.json
- https://repo.packagist.org/p2/laravel/ai.json
- https://repo.packagist.org/p2/pestphp/pest.json
- https://repo.packagist.org/p2/pestphp/pest-plugin-laravel.json
- https://repo.packagist.org/p2/laravel/fortify.json
- https://repo.packagist.org/p2/intervention/image.json
- https://repo.packagist.org/p2/laravel/sail.json
- https://registry.npmjs.org/laravel-vite-plugin
- https://registry.npmjs.org/vite
- https://api.github.com/repos/oven-sh/bun/releases/latest
- https://bun.com/guides/ecosystem/vite
- https://bun.com/blog/bun-v1.3
- https://github.com/laravel/vite-plugin/issues/278
- https://www.postgresql.org/about/news/postgresql-18-released-3142/
- https://www.postgresql.org/about/news/postgresql-183-179-1613-1517-and-1422-released-3246/
- https://www.postgresql.org/support/versioning/
- https://www.postgresql.org/docs/current/textsearch-tables.html
- https://www.postgresql.org/about/news/pgvector-082-released-3245
- https://pgxn.org/dist/vector/0.8.0/
- https://api.pgxn.org/src/vector/vector-0.8.1/CHANGELOG.md
- https://hub.docker.com/r/pgvector/pgvector/tags
- https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia
- https://bugs.webkit.org/show_bug.cgi?id=215884
- https://bugs.webkit.org/show_bug.cgi?id=185448
- https://kb.strich.io/article/29-camera-access-issues-in-ios-pwa
- https://developer.apple.com/forums/thread/801146
- https://www.magicbell.com/blog/pwa-ios-limitations-safari-support-complete-guide
- https://laravel-news.com/pest-5
- https://pestphp.com/docs/pest5-now-available
- https://pestphp.com/docs/browser-testing
- https://laravel-news.com/php-8-5-0
- https://laravel-news.com/laravel-13
- https://laravel-news.com/eloquent-encrypted-casting
- https://www.dbi-services.com/blog/pgvector-a-guide-for-dba-part-2-indexes-update-march-2026/
- https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/
- https://www.codemote.dev/blog/tunnels-for-remote-development
- https://merginit.com/blog/19062026-free-developer-tunnels-comparison
