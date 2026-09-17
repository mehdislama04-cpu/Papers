/**
 * Test de l'algorithme de scan, hors navigateur.
 *
 *   bun scripts/test-pipeline.ts
 *
 * Construit une "photo" de synthese : une feuille blanche vue de biais sur un
 * fond sombre, avec des lignes de texte. Verifie que le pipeline retrouve les
 * quatre coins, redresse correctement, et que la nettete distingue une image
 * nette d'une image floue.
 */

import { appendFileSync, writeFileSync } from 'node:fs';

/**
 * Sortie non bufferisee.
 *
 * Bun bufferise stdout des qu'il est redirige : sur un script qui met une
 * trentaine de secondes a charger OpenCV, on ne voit alors rien avant la fin et
 * on ne peut pas distinguer « lent » de « bloque ». On ecrit donc directement
 * dans un fichier, en plus de la console.
 */
const LOG = new URL('./pipeline-result.txt', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
writeFileSync(LOG, '');
const say = (line: string): void => {
    process.stdout.write(line + '\n');
    appendFileSync(LOG, line + '\n');
};

import {
    detectQuad,
    warpDocument,
    orderCorners,
    outputSize,
    patchCount,
    quadArea,
    Scope,
    type Cv,
} from '../resources/js/scanner/pipeline';
import type { Corner, Quad } from '../resources/js/scanner/types';

const W = 1200;
const H = 900;

/** Coins de la feuille dans la photo de synthese (sens horaire depuis le haut-gauche). */
const TRUTH: Quad = [
    { x: 210, y: 130 },
    { x: 975, y: 205 },
    { x: 900, y: 755 },
    { x: 155, y: 655 },
];

function inPolygon(p: Corner, poly: readonly Corner[]): boolean {
    let inside = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i, i += 1) {
        const a = poly[i];
        const b = poly[j];
        if (a.y > p.y !== b.y > p.y && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x) {
            inside = !inside;
        }
    }
    return inside;
}

/** Interpolation bilineaire dans le quadrilatere -> coordonnees (u, v) dans [0,1]. */
function pointInQuad(quad: Quad, u: number, v: number): Corner {
    const top = { x: quad[0].x + (quad[1].x - quad[0].x) * u, y: quad[0].y + (quad[1].y - quad[0].y) * u };
    const bottom = { x: quad[3].x + (quad[2].x - quad[3].x) * u, y: quad[3].y + (quad[2].y - quad[3].y) * u };
    return { x: top.x + (bottom.x - top.x) * v, y: top.y + (bottom.y - top.y) * v };
}

function buildPhoto(blur: boolean): ImageData {
    const data = new Uint8ClampedArray(W * H * 4);

    // Fond sombre, legerement degrade pour simuler une ombre de bureau.
    for (let y = 0; y < H; y += 1) {
        for (let x = 0; x < W; x += 1) {
            const i = (y * W + x) * 4;
            const shade = 38 + Math.round((x / W) * 18);
            data[i] = shade;
            data[i + 1] = shade;
            data[i + 2] = shade + 4;
            data[i + 3] = 255;
        }
    }

    // La feuille, avec un leger vignettage pour tester la correction d'illumination.
    for (let y = 0; y < H; y += 1) {
        for (let x = 0; x < W; x += 1) {
            if (!inPolygon({ x, y }, TRUTH)) continue;
            const i = (y * W + x) * 4;
            const vignette = 235 - Math.round(((x - 155) / 820) * 45);
            data[i] = vignette;
            data[i + 1] = vignette;
            data[i + 2] = vignette - 3;
            data[i + 3] = 255;
        }
    }

    // Lignes de texte, posees dans le repere de la feuille pour suivre la perspective.
    for (let line = 0; line < 14; line += 1) {
        const v = 0.12 + line * 0.06;
        for (let step = 0; step <= 600; step += 1) {
            const u = 0.1 + (step / 600) * (line % 4 === 3 ? 0.42 : 0.78);
            const p = pointInQuad(TRUTH, u, v);
            for (let dy = -3; dy <= 3; dy += 1) {
                for (let dx = -1; dx <= 1; dx += 1) {
                    const px = Math.round(p.x) + dx;
                    const py = Math.round(p.y) + dy;
                    if (px < 0 || py < 0 || px >= W || py >= H) continue;
                    const i = (py * W + px) * 4;
                    data[i] = 28;
                    data[i + 1] = 28;
                    data[i + 2] = 32;
                }
            }
        }
    }

    if (blur) {
        // Flou boite 9x9 SEPARABLE : deux passes 1D au lieu d'une passe 2D.
        // Le resultat est identique, pour 2*(2r+1) operations par pixel au lieu
        // de (2r+1)^2 — neuf fois moins ici.
        const r = 4;
        const window = 2 * r + 1;

        const pass = (horizontal: boolean) => {
            const copy = new Uint8ClampedArray(data);
            const outer = horizontal ? H : W;
            const inner = horizontal ? W : H;

            for (let o = 0; o < outer; o += 1) {
                for (let i = r; i < inner - r; i += 1) {
                    for (let c = 0; c < 3; c += 1) {
                        let sum = 0;
                        for (let d = -r; d <= r; d += 1) {
                            const x = horizontal ? i + d : o;
                            const y = horizontal ? o : i + d;
                            sum += copy[(y * W + x) * 4 + c];
                        }
                        const x = horizontal ? i : o;
                        const y = horizontal ? o : i;
                        data[(y * W + x) * 4 + c] = sum / window;
                    }
                }
            }
        };

        pass(true);
        pass(false);
    }

    return { data, width: W, height: H, colorSpace: 'srgb' } as ImageData;
}

let failures = 0;

function check(label: string, ok: boolean, detail = ''): void {
    if (ok) {
        say(`  OK   ${label}${detail ? ` — ${detail}` : ''}`);
    } else {
        failures += 1;
        say(`  ECHEC ${label}${detail ? ` — ${detail}` : ''}`);
    }
}

const cv: Cv = await (await import('@techstark/opencv-js')).default;

say('\n== Fonctions pures ==');

{
    // Donne les 4 coins dans le desordre : l'ordonnancement doit les remettre d'aplomb.
    const shuffled: Corner[] = [TRUTH[2], TRUTH[0], TRUTH[3], TRUTH[1]];
    const ordered = orderCorners(shuffled);
    const same = ordered.every((c, i) => c.x === TRUTH[i].x && c.y === TRUTH[i].y);
    check('orderCorners remet 4 points en desordre dans le bon ordre', same);
}

{
    // Un quadrilatere tourne de 50 degres : la methode somme/difference echouerait ici.
    const cx = 500;
    const cy = 500;
    const rot = (Math.PI / 180) * 50;
    const base: Corner[] = [
        { x: -200, y: -120 },
        { x: 200, y: -120 },
        { x: 200, y: 120 },
        { x: -200, y: 120 },
    ];
    const rotated = base.map((p) => ({
        x: cx + p.x * Math.cos(rot) - p.y * Math.sin(rot),
        y: cy + p.x * Math.sin(rot) + p.y * Math.cos(rot),
    }));
    const ordered = orderCorners([rotated[3], rotated[1], rotated[2], rotated[0]]);
    // Apres rotation de 50 deg, l'ordre doit rester un cycle horaire coherent.
    const cyclic =
        ordered.every((c) => rotated.some((r) => Math.abs(r.x - c.x) < 1e-6 && Math.abs(r.y - c.y) < 1e-6)) &&
        new Set(ordered.map((c) => `${c.x.toFixed(3)},${c.y.toFixed(3)}`)).size === 4;
    check('orderCorners tient au-dela de 45 degres de rotation', cyclic);
}

{
    const big: Quad = [
        { x: 0, y: 0 },
        { x: 6000, y: 0 },
        { x: 6000, y: 8000 },
        { x: 0, y: 8000 },
    ];
    const size = outputSize(big);
    const patches = patchCount(size.width, size.height);
    check(
        'outputSize ramene une image enorme sous le budget de patches',
        patches <= 9000,
        `${size.width}x${size.height} = ${patches} patches`,
    );

    const a4 = outputSize([
        { x: 0, y: 0 },
        { x: 2480, y: 0 },
        { x: 2480, y: 3508 },
        { x: 0, y: 3508 },
    ]);
    check(
        'une A4 a 300 dpi passe sans etre reduite',
        a4.width === 2480 && a4.height === 3508,
        `${a4.width}x${a4.height} = ${patchCount(a4.width, a4.height)} patches`,
    );
}

say('\n== Detection sur photo de synthese ==');

const scope = new Scope();
let sharpScore = 0;

try {
    const photo = buildPhoto(false);
    const src = scope.keep(cv.matFromImageData(photo));

    const quad = detectQuad(cv, src);
    check('un quadrilatere est detecte', quad !== null);

    if (quad) {
        const errors = quad.map((c, i) => Math.hypot(c.x - TRUTH[i].x, c.y - TRUTH[i].y));
        const worst = Math.max(...errors);
        check(
            'les 4 coins tombent a moins de 15 px de la verite terrain',
            worst < 15,
            `erreur max ${worst.toFixed(1)} px`,
        );

        const ratio = quadArea(quad) / quadArea(TRUTH);
        check('l aire detectee correspond a la feuille', ratio > 0.97 && ratio < 1.03, `ratio ${ratio.toFixed(3)}`);

        const out = warpDocument(cv, src, quad);
        try {
            check(
                'le redressement produit une image plausible',
                out.width > 600 && out.height > 400 && out.color.rows === out.height,
                `${out.width}x${out.height}`,
            );
            check('la sortie serveur est en couleur (3 canaux)', out.color.channels() === 3);
            check('la sortie apercu est en gris (1 canal)', out.gray.channels() === 1);

            sharpScore = out.sharpness;
            check('la nettete est mesurable', Number.isFinite(out.sharpness), `score ${out.sharpness.toFixed(0)}`);
        } finally {
            out.color.delete();
            out.gray.delete();
        }
    }
} finally {
    scope.release();
}

say('\n== Detection de flou ==');

{
    const blurScope = new Scope();
    try {
        const blurred = blurScope.keep(cv.matFromImageData(buildPhoto(true)));
        const quad = detectQuad(cv, blurred);

        if (quad) {
            const out = warpDocument(cv, blurred, quad);
            try {
                check(
                    'une photo floue obtient un score franchement inferieur a une nette',
                    out.sharpness < sharpScore * 0.5,
                    `flou ${out.sharpness.toFixed(0)} contre net ${sharpScore.toFixed(0)}`,
                );
            } finally {
                out.color.delete();
                out.gray.delete();
            }
        } else {
            check('un quadrilatere est detecte meme sur photo floue', false);
        }
    } finally {
        blurScope.release();
    }
}

say(failures === 0 ? '\nTOUS LES TESTS PASSENT\n' : `\n${failures} TEST(S) EN ECHEC\n`);
process.exit(failures === 0 ? 0 : 1);
