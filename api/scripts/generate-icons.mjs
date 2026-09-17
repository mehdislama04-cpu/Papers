/**
 * Generation des icones PWA — aucune dependance.
 *
 * Rasterise un glyphe vectoriel (page + coin plie + lignes de texte) avec
 * 4x de suréchantillonnage, puis encode des PNG RGBA a la main (zlib de Node).
 * Pas de sharp, pas de canvas natif : rien a installer, et le resultat est
 * reproductible sur n'importe quelle machine.
 *
 *   node scripts/generate-icons.mjs
 */

import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SS = 4; // suréchantillonnage

const BRAND = [0x1d, 0x4e, 0xd8]; // bleu Papers
const BRAND_DEEP = [0x17, 0x3c, 0xa8];
const PAPER = [0xff, 0xff, 0xff];
const FOLD = [0xdb, 0xe3, 0xf7];
const INK = [0x93, 0xaf, 0xe6];

/* ---------------------------------------------------------------- PNG ---- */

const CRC_TABLE = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n += 1) {
        let c = n;
        for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        table[n] = c;
    }
    return table;
})();

function crc32(buffer) {
    let crc = -1;
    for (let i = 0; i < buffer.length; i += 1) {
        crc = CRC_TABLE[(crc ^ buffer[i]) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ -1) >>> 0;
}

function chunk(type, data) {
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length, 0);
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(body), 0);
    return Buffer.concat([length, body, crc]);
}

function encodePng(width, height, rgba) {
    const stride = width * 4;
    const raw = Buffer.alloc(height * (stride + 1));
    for (let y = 0; y < height; y += 1) {
        raw[y * (stride + 1)] = 0; // filtre None
        rgba.copy(raw, y * (stride + 1) + 1, y * stride, (y + 1) * stride);
    }

    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8; // 8 bits par canal
    ihdr[9] = 6; // RGBA
    ihdr[10] = 0;
    ihdr[11] = 0;
    ihdr[12] = 0;

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/* ------------------------------------------------------------ geometrie -- */

function insideRoundRect(x, y, x0, y0, x1, y1, r) {
    if (x < x0 || x > x1 || y < y0 || y > y1) return false;
    const cx = Math.min(Math.max(x, x0 + r), x1 - r);
    const cy = Math.min(Math.max(y, y0 + r), y1 - r);
    const dx = x - cx;
    const dy = y - cy;
    return dx * dx + dy * dy <= r * r;
}

/**
 * Couleur d'un point en coordonnees normalisees (0..1), ou null (transparent).
 * @param {number} x
 * @param {number} y
 * @param {{ bleed: boolean, glyph: number }} options
 */
function sample(x, y, options) {
    // 1. Fond
    let background = null;
    if (options.bleed) {
        background = BRAND;
    } else if (insideRoundRect(x, y, 0, 0, 1, 1, 0.225)) {
        background = BRAND;
    }
    if (!background) return null;

    // Degrade diagonal discret.
    const t = (x + y) / 2;
    background = [
        Math.round(BRAND[0] + (BRAND_DEEP[0] - BRAND[0]) * t),
        Math.round(BRAND[1] + (BRAND_DEEP[1] - BRAND[1]) * t),
        Math.round(BRAND[2] + (BRAND_DEEP[2] - BRAND[2]) * t),
    ];

    // 2. Page, centree, mise a l'echelle par options.glyph
    const g = options.glyph;
    const docW = 0.74 * g;
    const docH = 0.92 * g;
    const x0 = 0.5 - docW / 2;
    const x1 = 0.5 + docW / 2;
    const y0 = 0.5 - docH / 2;
    const y1 = 0.5 + docH / 2;
    const fold = 0.26 * docW;
    const radius = 0.09 * docW;

    if (!insideRoundRect(x, y, x0, y0, x1, y1, radius)) return background;
    // Coin superieur droit coupe.
    if (x - y > x1 - y0 - fold) return background;

    // 3. Rabat du coin plie
    if (x >= x1 - fold && y <= y0 + fold) return FOLD;

    // 4. Lignes de texte
    const lineX0 = x0 + 0.14 * docW;
    const lines = [
        { y: y0 + 0.44 * docH, w: 0.72 },
        { y: y0 + 0.6 * docH, w: 0.72 },
        { y: y0 + 0.76 * docH, w: 0.44 },
    ];
    const lineH = 0.055 * docH;
    for (const line of lines) {
        const lx1 = lineX0 + line.w * docW;
        if (insideRoundRect(x, y, lineX0, line.y - lineH / 2, lx1, line.y + lineH / 2, lineH / 2)) {
            return INK;
        }
    }

    return PAPER;
}

function render(size, options) {
    const out = Buffer.alloc(size * size * 4);
    const step = 1 / (size * SS);

    for (let py = 0; py < size; py += 1) {
        for (let px = 0; px < size; px += 1) {
            let r = 0;
            let g = 0;
            let b = 0;
            let a = 0;

            for (let sy = 0; sy < SS; sy += 1) {
                const y = (py * SS + sy + 0.5) * step;
                for (let sx = 0; sx < SS; sx += 1) {
                    const x = (px * SS + sx + 0.5) * step;
                    const color = sample(x, y, options);
                    if (!color) continue;
                    r += color[0];
                    g += color[1];
                    b += color[2];
                    a += 255;
                }
            }

            const samples = SS * SS;
            const alpha = a / samples;
            const offset = (py * size + px) * 4;
            const covered = a / 255;

            if (covered > 0) {
                out[offset] = Math.round(r / covered);
                out[offset + 1] = Math.round(g / covered);
                out[offset + 2] = Math.round(b / covered);
            }
            out[offset + 3] = Math.round(alpha);
        }
    }

    return encodePng(size, size, out);
}

/* ----------------------------------------------------------------- run --- */

const targets = [
    { file: 'public/icons/pwa-64x64.png', size: 64, bleed: false, glyph: 0.6 },
    { file: 'public/icons/pwa-192x192.png', size: 192, bleed: false, glyph: 0.6 },
    { file: 'public/icons/pwa-512x512.png', size: 512, bleed: false, glyph: 0.6 },
    // Maskable : le glyphe doit tenir dans la zone sure (cercle de 80 %).
    { file: 'public/icons/maskable-icon-512x512.png', size: 512, bleed: true, glyph: 0.44 },
    // apple-touch-icon : iOS applique lui-meme le masque, le fond doit etre
    // opaque et plein cadre, sinon les coins ressortent en noir.
    { file: 'public/icons/apple-touch-icon-180x180.png', size: 180, bleed: true, glyph: 0.58 },
    { file: 'public/apple-touch-icon.png', size: 180, bleed: true, glyph: 0.58 },
];

for (const target of targets) {
    const path = join(ROOT, target.file);
    mkdirSync(dirname(path), { recursive: true });
    const png = render(target.size, { bleed: target.bleed, glyph: target.glyph });
    writeFileSync(path, png);
    console.log(`${target.file}  ${target.size}x${target.size}  ${png.length} o`);
}
