<?php

namespace App\Lessons;

use GdImage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Bereitet ein hochgeladenes Foto für die Analyse vor.
 *
 * Das Bild wird mit GD neu kodiert. Dabei gehen alle Metadaten verloren (EXIF, GPS, Kamera),
 * die Ausrichtung wird ins Bild übernommen und die längere Seite auf max_edge verkleinert.
 * Der Browser verkleinert die Fotos schon vorher; das hier ist die Absicherung auf dem Server.
 */
class ImageProcessor
{
    /**
     * @return array{mime: string, data: string}
     */
    public static function process(UploadedFile $file): array
    {
        $contents = $file->get();
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Das Bild konnte nicht gelesen werden.');
        }

        $image = self::orient($image, $file);
        $image = self::scale($image, config('lessons.images.max_edge'));

        ob_start();
        imagejpeg($image, null, config('lessons.images.jpeg_quality'));
        $data = (string) ob_get_clean();

        return ['mime' => 'image/jpeg', 'data' => $data];
    }

    private static function orient(GdImage $image, UploadedFile $file): GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private static function scale(GdImage $image, int $maxEdge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxEdge) {
            return $image;
        }

        $factor = $maxEdge / $longest;

        return imagescale($image, (int) round($width * $factor), (int) round($height * $factor)) ?: $image;
    }
}
