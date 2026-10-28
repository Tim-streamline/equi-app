<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** Private, disposable derivatives. Originals remain unchanged for downloads. */
class IntakePhotoPreview
{
    public static function path(string $original): string
    {
        return $original.'.preview-v1.jpg';
    }

    public function make(string $original): string
    {
        $disk = Storage::disk('local');
        $preview = self::path($original);
        if ($disk->exists($preview)) {
            return $preview;
        }
        // Lock the original inode: concurrent requests share one cached result,
        // without leaving permanent lock files alongside deleted attachments.
        $lock = fopen($disk->path($original), 'rb');
        abort_unless($lock, 404);
        $temporary = null;
        try {
            abort_unless(flock($lock, LOCK_EX), 503);
            if ($disk->exists($preview)) {
                return $preview;
            }
            $path = $disk->path($original);
            $size = @getimagesize($path);
            abort_unless($size && in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 415);
            // Bound server decode memory before invoking GD, including malformed
            // files whose small compressed size conceals enormous dimensions.
            $estimatedBytes = $size[0] * $size[1] * 8 + 16 * 1024 * 1024;
            $limit = ini_parse_quantity(ini_get('memory_limit'));
            abort_if($estimatedBytes > 512 * 1024 * 1024
                || ($limit > 0 && memory_get_usage(true) + $estimatedBytes > $limit), 413, 'Foto te groot om een voorbeeld te maken.');
            $image = match ($size[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            };
            abort_unless($image, 422);
            $scale = min(1, 960 / max($size[0], $size[1]));
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
            $small = imagecreatetruecolor($width, $height);
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            unset($image);
            $orientation = $size[2] === IMAGETYPE_JPEG ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
            // Apply EXIF to the small image, never rotate another full-size copy.
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($small, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($small, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
            };
            if ($angle) {
                $small = imagerotate($small, $angle, 0);
            }
            $temporary = tempnam(dirname($disk->path($preview)), '.preview-');
            abort_unless($temporary && imagejpeg($small, $temporary, 82), 500);
            unset($small);
            abort_unless(rename($temporary, $disk->path($preview)), 500);
            $temporary = null;

            return $preview;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
