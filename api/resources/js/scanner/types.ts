/**
 * Types partages du pipeline de scan.
 *
 * Le pipeline vit dans un Web Worker : tout ce qui traverse la frontiere doit
 * etre structuredClone-able (pas de Mat, pas de fonction).
 */

/** Un coin, en pixels de l'image SOURCE (resolution native). */
export interface Corner {
    x: number;
    y: number;
}

/** Quadrilatere du document, toujours ordonne [haut-gauche, haut-droit, bas-droit, bas-gauche]. */
export type Quad = [Corner, Corner, Corner, Corner];

/** Rendu demande pour la sortie ecran. */
export type PreviewMode = 'color' | 'gray' | 'bw';

export interface DetectRequest {
    /** Image source complete, transferee au worker. */
    bitmap: ImageBitmap;
}

export interface DetectResponse {
    /** null quand aucun quadrilatere plausible n'a ete trouve. */
    quad: Quad | null;
    /** Dimensions de l'image source, pour caler l'editeur de coins. */
    width: number;
    height: number;
}

export interface WarpRequest {
    bitmap: ImageBitmap;
    quad: Quad;
    /** Rendu de l'apercu ecran. L'envoi serveur est TOUJOURS en couleur corrigee. */
    preview: PreviewMode;
}

export interface WarpResponse {
    /** Image couleur corrigee, destinee au serveur et au modele de vision. */
    upload: Blob;
    /** Rendu demande, destine a l'affichage. */
    preview: Blob;
    width: number;
    height: number;
    /**
     * Variance du laplacien normalisee, calculee APRES le warp sur la zone de
     * texte. En dessous de BLUR_THRESHOLD l'image est probablement floue.
     */
    sharpness: number;
    /** Nombre de patches 32x32 factures par le modele de vision. */
    patches: number;
}

/** Seuil empirique de nettete (variance du laplacien normalisee). */
export const BLUR_THRESHOLD = 90;

/**
 * Budget de patches 32x32 vise pour l'envoi.
 *
 * Au-dela de 30 000 patches l'API OpenAI REJETTE la requete au lieu de
 * redimensionner. On vise large sous le plafond : 9 000 patches laissent passer
 * une A4 a 300 dpi (2480x3508 = 8 580 patches) avec de la marge.
 */
export const MAX_PATCHES = 9000;

export type WorkerRequest =
    | ({ id: number; type: 'detect' } & DetectRequest)
    | ({ id: number; type: 'warp' } & WarpRequest);

export type WorkerResponse =
    | { id: number; type: 'detect'; ok: true; result: DetectResponse }
    | { id: number; type: 'warp'; ok: true; result: WarpResponse }
    | { id: number; type: 'detect' | 'warp'; ok: false; error: string }
    | { id: -1; type: 'progress'; stage: string };
