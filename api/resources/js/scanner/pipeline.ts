/**
 * Pipeline de scan : detection du quadrilatere, correction de perspective,
 * correction d'illumination.
 *
 * Ce module ne touche NI au DOM NI au worker : il prend une instance `cv` et
 * des Mat. C'est ce qui permet de le tester hors navigateur (voir
 * scripts/test-pipeline.mjs), et donc de garantir l'algorithme.
 *
 * REGLE DE VIE DES Mat : la heap WASM est en ALLOW_MEMORY_GROWTH et ne
 * redescend JAMAIS. Une fuite de Mat fait crasher l'onglet en une minute. Tout
 * Mat temporaire passe par un `Scope` qui les supprime dans un finally.
 */

import type { Quad, Corner } from './types';
import { MAX_PATCHES } from './types';

/* eslint-disable @typescript-eslint/no-explicit-any */
/**
 * Le paquet @techstark/opencv-js exporte une Promise, pas un namespace : il n'y
 * a pas de type statique exploitable pour l'instance resolue. On la traite donc
 * comme `any` ici, et uniquement ici — le reste du code est type.
 */
export type Cv = any;
type Mat = any;

/** Suit les Mat alloues et les libere tous, meme en cas d'exception. */
export class Scope {
    private readonly items: Array<{ delete(): void }> = [];

    keep<T extends { delete(): void }>(item: T): T {
        this.items.push(item);
        return item;
    }

    release(): void {
        for (const item of this.items) {
            try {
                item.delete();
            } catch {
                // deja libere : sans importance, on veut surtout ne rien laisser.
            }
        }
        this.items.length = 0;
    }
}

/** Hauteur de travail pour la detection. Inutile de chercher les bords en pleine resolution. */
const DETECT_HEIGHT = 720;

/** Part minimale de l'image que doit couvrir le document pour etre credible. */
const MIN_AREA_RATIO = 0.18;

/** Part maximale : au-dela, c'est le cadre de la photo qui a ete detecte, pas le papier. */
const MAX_AREA_RATIO = 0.985;

/**
 * Ordonne 4 points en [haut-gauche, haut-droit, bas-droit, bas-gauche].
 *
 * Le tri par somme/difference des coordonnees, tres repandu, n'est correct que
 * tant que la rotation reste sous 45 degres. On trie donc par angle autour du
 * centroide, puis on fait tourner le tableau pour placer en tete le point le
 * plus proche du coin haut-gauche.
 */
export function orderCorners(points: Corner[]): Quad {
    if (points.length !== 4) {
        throw new Error(`orderCorners attend 4 points, recu ${points.length}`);
    }

    const cx = points.reduce((sum, p) => sum + p.x, 0) / 4;
    const cy = points.reduce((sum, p) => sum + p.y, 0) / 4;

    // Sens horaire dans un repere ecran (y vers le bas).
    const sorted = [...points].sort(
        (a, b) => Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx),
    );

    let start = 0;
    let best = Number.POSITIVE_INFINITY;
    for (let i = 0; i < 4; i += 1) {
        const score = sorted[i].x + sorted[i].y;
        if (score < best) {
            best = score;
            start = i;
        }
    }

    return [
        sorted[start % 4],
        sorted[(start + 1) % 4],
        sorted[(start + 2) % 4],
        sorted[(start + 3) % 4],
    ];
}

function distance(a: Corner, b: Corner): number {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

/** Aire d'un quadrilatere par la formule du lacet. */
export function quadArea(quad: Quad): number {
    let area = 0;
    for (let i = 0; i < 4; i += 1) {
        const a = quad[i];
        const b = quad[(i + 1) % 4];
        area += a.x * b.y - b.x * a.y;
    }
    return Math.abs(area) / 2;
}

/**
 * Detecte le quadrilatere du document dans une image RGBA.
 * Renvoie les coins en coordonnees de l'image SOURCE, ou null.
 */
export function detectQuad(cv: Cv, src: Mat): Quad | null {
    const scope = new Scope();

    try {
        const scale = Math.min(1, DETECT_HEIGHT / src.rows);
        const work = scope.keep(new cv.Mat());

        if (scale < 1) {
            cv.resize(
                src,
                work,
                new cv.Size(Math.round(src.cols * scale), Math.round(src.rows * scale)),
                0,
                0,
                cv.INTER_AREA,
            );
        } else {
            src.copyTo(work);
        }

        const gray = scope.keep(new cv.Mat());
        cv.cvtColor(work, gray, cv.COLOR_RGBA2GRAY);

        // Flou AVANT Canny. L'ordre inverse (Canny puis flou sur la carte
        // d'aretes) est le bug de jscanify : il detruit les aretes au lieu de
        // supprimer le bruit qui les precede.
        const blurred = scope.keep(new cv.Mat());
        cv.GaussianBlur(gray, blurred, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);

        const edges = scope.keep(new cv.Mat());
        cv.Canny(blurred, edges, 60, 180, 3, false);

        // Dilatation legere : referme les bords interrompus (coin plie, ombre)
        // pour que findContours voie un contour ferme.
        const kernel = scope.keep(cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3)));
        cv.dilate(edges, edges, kernel, new cv.Point(-1, -1), 2);

        const contours = scope.keep(new cv.MatVector());
        const hierarchy = scope.keep(new cv.Mat());
        cv.findContours(edges, contours, hierarchy, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);

        const workArea = work.rows * work.cols;
        let bestQuad: Quad | null = null;
        let bestArea = 0;

        for (let i = 0; i < contours.size(); i += 1) {
            const contour = contours.get(i);
            const contourScope = new Scope();

            try {
                const area = cv.contourArea(contour, false);
                if (area < workArea * MIN_AREA_RATIO) {
                    continue;
                }

                const approx = contourScope.keep(new cv.Mat());
                const peri = cv.arcLength(contour, true);
                cv.approxPolyDP(contour, approx, 0.02 * peri, true);

                // Un document est un quadrilatere convexe. Les deux tests
                // ensemble eliminent les taches, les reflets et le cadre de la
                // photo (que jscanify selectionne faute de les faire).
                if (approx.rows !== 4 || !cv.isContourConvex(approx)) {
                    continue;
                }

                const points: Corner[] = [];
                for (let p = 0; p < 4; p += 1) {
                    points.push({
                        x: approx.intPtr(p, 0)[0],
                        y: approx.intPtr(p, 0)[1],
                    });
                }

                const quad = orderCorners(points);
                const quadA = quadArea(quad);
                const ratio = quadA / workArea;

                if (ratio < MIN_AREA_RATIO || ratio > MAX_AREA_RATIO) {
                    continue;
                }

                // Rejette les quadrilateres degeneres (tres aplatis).
                const w = Math.max(distance(quad[0], quad[1]), distance(quad[3], quad[2]));
                const h = Math.max(distance(quad[0], quad[3]), distance(quad[1], quad[2]));
                const aspect = Math.max(w, h) / Math.max(1, Math.min(w, h));
                if (aspect > 6) {
                    continue;
                }

                if (quadA > bestArea) {
                    bestArea = quadA;
                    bestQuad = quad;
                }
            } finally {
                contourScope.release();
                contour.delete();
            }
        }

        if (!bestQuad) {
            return null;
        }

        // Retour en coordonnees de l'image source.
        const inv = 1 / scale;
        return bestQuad.map((c) => ({
            x: Math.max(0, Math.min(src.cols, c.x * inv)),
            y: Math.max(0, Math.min(src.rows, c.y * inv)),
        })) as Quad;
    } finally {
        scope.release();
    }
}

/** Quadrilatere de repli : l'image entiere, legerement rentree. */
export function fullFrameQuad(width: number, height: number): Quad {
    const mx = width * 0.02;
    const my = height * 0.02;
    return [
        { x: mx, y: my },
        { x: width - mx, y: my },
        { x: width - mx, y: height - my },
        { x: mx, y: height - my },
    ];
}

/** Nombre de patches 32x32 factures par le modele de vision pour ces dimensions. */
export function patchCount(width: number, height: number): number {
    return Math.ceil(width / 32) * Math.ceil(height / 32);
}

/**
 * Dimensions de sortie du warp, deduites des longueurs des cotes du
 * quadrilatere, puis ramenees sous le budget de patches.
 */
export function outputSize(quad: Quad): { width: number; height: number } {
    const width = Math.max(distance(quad[0], quad[1]), distance(quad[3], quad[2]));
    const height = Math.max(distance(quad[0], quad[3]), distance(quad[1], quad[2]));

    let w = Math.max(64, Math.round(width));
    let h = Math.max(64, Math.round(height));

    // Au-dela de 30 000 patches OpenAI REJETTE la requete au lieu de
    // redimensionner : on reste tres en dessous.
    if (patchCount(w, h) > MAX_PATCHES) {
        const factor = Math.sqrt((MAX_PATCHES * 32 * 32) / (w * h));
        w = Math.max(64, Math.floor(w * factor));
        h = Math.max(64, Math.floor(h * factor));
    }

    return { width: w, height: h };
}

/**
 * Estime le fond lumineux (ombres, vignettage) par un flou de tres grand rayon.
 *
 * Un GaussianBlur a grand noyau serait trop lent sur iPhone : on reduit, on
 * floute petit, on re-agrandit. Resultat visuellement equivalent, cout divise
 * par plusieurs dizaines.
 */
function estimateBackground(cv: Cv, channel: Mat, scope: Scope): Mat {
    const small = scope.keep(new cv.Mat());
    const w = Math.max(8, Math.round(channel.cols / 8));
    const h = Math.max(8, Math.round(channel.rows / 8));

    cv.resize(channel, small, new cv.Size(w, h), 0, 0, cv.INTER_AREA);
    cv.GaussianBlur(small, small, new cv.Size(31, 31), 0, 0, cv.BORDER_REPLICATE);

    const background = scope.keep(new cv.Mat());
    cv.resize(small, background, new cv.Size(channel.cols, channel.rows), 0, 0, cv.INTER_LINEAR);

    return background;
}

/**
 * Corrige l'illumination en divisant chaque canal par son fond estime.
 *
 * On travaille EN COULEUR et non en niveaux de gris : le cout d'un modele de
 * vision depend des dimensions, pas du nombre de canaux, alors que passer en
 * gris perdrait les tampons, les surlignages et l'encre coloree. Le gris et le
 * noir et blanc sont reserves a l'apercu ecran.
 */
export function correctIllumination(cv: Cv, src: Mat): Mat {
    const scope = new Scope();

    try {
        const rgb = scope.keep(new cv.Mat());
        cv.cvtColor(src, rgb, cv.COLOR_RGBA2RGB);

        const channels = scope.keep(new cv.MatVector());
        cv.split(rgb, channels);

        const corrected = scope.keep(new cv.MatVector());

        for (let i = 0; i < channels.size(); i += 1) {
            const channel = channels.get(i);
            const channelScope = new Scope();

            try {
                const background = estimateBackground(cv, channel, channelScope);
                const out = new cv.Mat();
                // scale 255 : ramene le rapport canal/fond dans [0, 255].
                cv.divide(channel, background, out, 255, cv.CV_8U);
                corrected.push_back(out);
                out.delete();
            } finally {
                channelScope.release();
                channel.delete();
            }
        }

        const merged = new cv.Mat();
        cv.merge(corrected, merged);
        return merged;
    } finally {
        scope.release();
    }
}

/** Version niveaux de gris rehaussee (CLAHE), pour l'apercu. */
export function toEnhancedGray(cv: Cv, rgb: Mat): Mat {
    const scope = new Scope();

    try {
        const gray = scope.keep(new cv.Mat());
        cv.cvtColor(rgb, gray, cv.COLOR_RGB2GRAY);

        // Ce build n'expose PAS cv.createCLAHE() : la classe cv.CLAHE, si.
        const clahe = scope.keep(new cv.CLAHE(2.0, new cv.Size(8, 8)));
        const out = new cv.Mat();
        clahe.apply(gray, out);
        return out;
    } finally {
        scope.release();
    }
}

/**
 * Version noir et blanc, pour l'apercu ecran UNIQUEMENT.
 *
 * Ne jamais l'envoyer au modele : on perdrait toute l'information de mise en
 * forme sans rien economiser. OpenCV.js n'expose ni Sauvola ni
 * niBlackThreshold, d'ou adaptiveThreshold gaussien, applique apres la
 * correction d'illumination qui en fait le vrai travail.
 */
export function toBlackAndWhite(cv: Cv, gray: Mat): Mat {
    const out = new cv.Mat();
    cv.adaptiveThreshold(
        gray,
        out,
        255,
        cv.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv.THRESH_BINARY,
        31,
        10,
    );
    return out;
}

/**
 * Nettete par variance du laplacien.
 *
 * Elle depend de la resolution et du contraste : on la calcule donc sur une
 * zone fixe (le centre, ou se trouve le texte) ramenee a une taille fixe, sinon
 * le seuil n'a aucun sens d'une photo a l'autre.
 */
export function sharpnessScore(cv: Cv, gray: Mat): number {
    const scope = new Scope();

    try {
        const x = Math.round(gray.cols * 0.2);
        const y = Math.round(gray.rows * 0.2);
        const w = Math.max(16, Math.round(gray.cols * 0.6));
        const h = Math.max(16, Math.round(gray.rows * 0.6));

        const roi = scope.keep(gray.roi(new cv.Rect(x, y, w, h)));
        const norm = scope.keep(new cv.Mat());
        cv.resize(roi, norm, new cv.Size(512, 512), 0, 0, cv.INTER_AREA);

        const lap = scope.keep(new cv.Mat());
        cv.Laplacian(norm, lap, cv.CV_64F, 3, 1, 0, cv.BORDER_DEFAULT);

        const mean = scope.keep(new cv.Mat());
        const stddev = scope.keep(new cv.Mat());
        cv.meanStdDev(lap, mean, stddev);

        const sd = stddev.doubleAt(0, 0);
        return sd * sd;
    } finally {
        scope.release();
    }
}

export interface WarpOutput {
    /** RGB corrige, destine au serveur. L'appelant doit le supprimer. */
    color: Mat;
    /** Gris rehausse. L'appelant doit le supprimer. */
    gray: Mat;
    sharpness: number;
    width: number;
    height: number;
}

/**
 * Applique la correction de perspective puis la correction d'illumination.
 * L'appelant est responsable de supprimer `color` et `gray`.
 */
export function warpDocument(cv: Cv, src: Mat, quad: Quad): WarpOutput {
    const scope = new Scope();

    try {
        const { width, height } = outputSize(quad);

        const srcTri = scope.keep(
            cv.matFromArray(4, 1, cv.CV_32FC2, [
                quad[0].x, quad[0].y,
                quad[1].x, quad[1].y,
                quad[2].x, quad[2].y,
                quad[3].x, quad[3].y,
            ]),
        );

        const dstTri = scope.keep(
            cv.matFromArray(4, 1, cv.CV_32FC2, [
                0, 0,
                width - 1, 0,
                width - 1, height - 1,
                0, height - 1,
            ]),
        );

        const transform = scope.keep(cv.getPerspectiveTransform(srcTri, dstTri));
        const warped = scope.keep(new cv.Mat());

        cv.warpPerspective(
            src,
            warped,
            transform,
            new cv.Size(width, height),
            cv.INTER_LINEAR,
            cv.BORDER_REPLICATE,
            new cv.Scalar(255, 255, 255, 255),
        );

        const color = correctIllumination(cv, warped);
        const gray = toEnhancedGray(cv, color);
        const sharpness = sharpnessScore(cv, gray);

        return { color, gray, sharpness, width, height };
    } finally {
        scope.release();
    }
}
