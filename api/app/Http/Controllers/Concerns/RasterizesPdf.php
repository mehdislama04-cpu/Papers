<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Imagick;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Éclatement d'un PDF en pages JPEG.
 *
 * Le raccourci iOS poste un PDF (c'est ce que produit VisionKit via l'action
 * « Scan Documents »), alors que le pipeline d'analyse ne sait traiter que des
 * images : une page = une `input_image`. Il faut donc rasteriser.
 *
 * Trois stratégies, essayées dans cet ordre, parce qu'aucune n'est garantie
 * présente sur une machine de dev Windows :
 *
 *  1. l'extension PHP Imagick — qui a elle-même besoin de Ghostscript pour
 *     décoder un PDF, et d'une policy.xml autorisant le délégué PDF (bloqué
 *     par défaut depuis CVE-2018-16509) ;
 *  2. `pdftoppm` (poppler-utils) — le plus fiable et le plus rapide ;
 *  3. `gs` / `gswin64c` (Ghostscript) en direct.
 *
 * Si aucune n'est disponible, on lève : le contrôleur répond 503 avec un
 * message explicite plutôt que d'enregistrer un document vide qui échouerait
 * silencieusement à l'analyse.
 */
trait RasterizesPdf
{
    /** 200 dpi : au-delà, le coût en tokens vision croît sans gain d'OCR. */
    protected const PDF_DPI = 200;

    /** Une conversion qui dépasse ça est un PDF pathologique, pas un scan. */
    protected const PDF_PROCESS_TIMEOUT = 120;

    /**
     * @param  string  $pdfPath  chemin local du PDF
     * @param  string  $outputDir  répertoire temporaire déjà créé
     * @return list<string> chemins des images produites, dans l'ordre des pages
     *
     * @throws RuntimeException si aucun moteur n'est disponible
     */
    protected function rasterizePdf(string $pdfPath, string $outputDir, int $maxPages): array
    {
        $pages = $this->rasterizeWithImagick($pdfPath, $outputDir, $maxPages)
            ?? $this->rasterizeWithPdftoppm($pdfPath, $outputDir, $maxPages)
            ?? $this->rasterizeWithGhostscript($pdfPath, $outputDir, $maxPages);

        if ($pages === null) {
            throw new RuntimeException(
                'Aucun moteur de conversion PDF disponible sur ce serveur '
                .'(extension PHP imagick + Ghostscript, pdftoppm, ou gs).'
            );
        }

        if ($pages === []) {
            throw new RuntimeException('Le PDF reçu ne contient aucune page exploitable.');
        }

        return $pages;
    }

    /**
     * @return list<string>|null null = moteur indisponible (et non « échec »)
     */
    private function rasterizeWithImagick(string $pdfPath, string $outputDir, int $maxPages): ?array
    {
        if (! extension_loaded('imagick') || ! class_exists(Imagick::class)) {
            return null;
        }

        try {
            $pdf = new Imagick;
            $pdf->setResolution(self::PDF_DPI, self::PDF_DPI);
            $pdf->readImage($pdfPath);
        } catch (Throwable) {
            // Typiquement : Ghostscript absent, ou policy.xml qui interdit PDF.
            return null;
        }

        $paths = [];

        try {
            $count = min($pdf->getNumberImages(), $maxPages);

            for ($i = 0; $i < $count; $i++) {
                $pdf->setIteratorIndex($i);

                $page = $pdf->getImage();
                // Un PDF a un fond transparent : sans aplatissement sur blanc,
                // le JPEG sort avec un fond noir.
                $page->setImageBackgroundColor('white');
                $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $page = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality((int) config('papers.images.jpeg_quality', 85));

                $target = sprintf('%s/page-%03d.jpg', $outputDir, $i + 1);
                $page->writeImage($target);
                $page->clear();

                $paths[] = $target;
            }
        } finally {
            $pdf->clear();
        }

        return $paths;
    }

    /** @return list<string>|null */
    private function rasterizeWithPdftoppm(string $pdfPath, string $outputDir, int $maxPages): ?array
    {
        $binary = $this->findBinary(['pdftoppm']);

        if ($binary === null) {
            return null;
        }

        $this->run([
            $binary,
            '-jpeg',
            '-r', (string) self::PDF_DPI,
            '-f', '1',
            '-l', (string) $maxPages,
            $pdfPath,
            $outputDir.'/page',
        ]);

        return $this->collect($outputDir, $maxPages);
    }

    /** @return list<string>|null */
    private function rasterizeWithGhostscript(string $pdfPath, string $outputDir, int $maxPages): ?array
    {
        $binary = $this->findBinary(['gs', 'gswin64c', 'gswin32c']);

        if ($binary === null) {
            return null;
        }

        $this->run([
            $binary,
            '-dQUIET', '-dBATCH', '-dNOPAUSE', '-dSAFER',
            '-sDEVICE=jpeg',
            '-dJPEGQ='.(int) config('papers.images.jpeg_quality', 85),
            '-r'.self::PDF_DPI,
            '-dFirstPage=1',
            '-dLastPage='.$maxPages,
            '-sOutputFile='.$outputDir.'/page-%03d.jpg',
            $pdfPath,
        ]);

        return $this->collect($outputDir, $maxPages);
    }

    /** @param  list<string>  $candidates */
    private function findBinary(array $candidates): ?string
    {
        $finder = new ExecutableFinder;

        foreach ($candidates as $candidate) {
            if (($path = $finder->find($candidate)) !== null) {
                return $path;
            }
        }

        return null;
    }

    /** @param  list<string>  $command */
    private function run(array $command): void
    {
        $process = new Process($command, timeout: self::PDF_PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Conversion du PDF impossible : '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * pdftoppm et gs numérotent eux-mêmes (page-1.jpg, page-001.jpg...) :
     * on relit le répertoire plutôt que de deviner le format.
     *
     * @return list<string>
     */
    private function collect(string $outputDir, int $maxPages): array
    {
        $files = glob($outputDir.'/page*.jpg') ?: [];

        // Tri NATUREL : un tri lexicographique mettrait page-10 avant page-2.
        natsort($files);

        return array_slice(array_values($files), 0, $maxPages);
    }
}
