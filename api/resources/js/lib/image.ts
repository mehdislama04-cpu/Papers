/**
 * Reduction d'une photo avant envoi.
 *
 * Une photo prise dans la photothèque d'un iPhone pese 3 a 8 Mo. Le serveur
 * n'en garde qu'un carre de 320 px : envoyer l'original, c'est faire transiter
 * cinquante fois les octets utiles sur un reseau mobile, pour le meme resultat.
 *
 * On reduit donc ici. Effet de bord bienvenu : toutes les pannes liees a la
 * taille — `post_max_size`, `upload_max_filesize`, coupure de tunnel —
 * disparaissent avec elle.
 */

/** Assez grand pour un recadrage serveur en 320 px, assez petit pour partir vite. */
const MAX_SIDE = 1280;

const QUALITY = 0.85;

/** En dessous, reduire ne rapporterait rien : on renvoie le fichier tel quel. */
const ALREADY_SMALL_BYTES = 400 * 1024;

export async function shrinkImage(file: File): Promise<File> {
    if (file.size <= ALREADY_SMALL_BYTES) return file;

    let bitmap: ImageBitmap;

    try {
        /*
         | `imageOrientation: 'from-image'` n'est PAS optionnel.
         |
         | Une photo d'iPhone est stockee dans l'orientation du capteur, la
         | rotation reelle n'etant portee que par le tag EXIF. Re-encoder en
         | JPEG depuis un canvas efface ce tag : sans cette option, on
         | fabriquerait un portrait couche, et le `->orient()` du serveur
         | n'aurait plus rien a lire pour le redresser.
         */
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        // Image illisible ici : on laisse le serveur trancher et repondre.
        return file;
    }

    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (!context) {
        bitmap.close();

        return file;
    }

    context.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) => {
        // JPEG et non WebP : `toBlob('image/webp')` retombe SILENCIEUSEMENT sur
        // PNG dans Safari, et un PNG de photo pese plus lourd que l'original.
        canvas.toBlob(resolve, 'image/jpeg', QUALITY);
    });

    // iOS a une limite MEMOIRE de canvas distincte de la limite d'aire : on
    // libere explicitement, sinon quelques photos suffisent a la faire sauter.
    canvas.width = 0;
    canvas.height = 0;

    if (!blob || blob.size >= file.size) return file;

    return new File([blob], 'photo.jpg', { type: 'image/jpeg', lastModified: Date.now() });
}
