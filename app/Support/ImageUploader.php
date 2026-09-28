<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Menyimpan foto beserta versi kecilnya (thumbnail) agar halaman
 * publik tidak memuat berkas berukuran besar.
 */
class ImageUploader
{
    private const MAX_WIDTH = 1200;

    /**
     * Simpan foto asli dan buat thumbnail bila ukurannya besar.
     *
     * @return array{path: string, thumb: string|null}
     */
    public static function store(UploadedFile $file, string $folder): array
    {
        $path = $file->store($folder, 'public');

        return [
            'path' => $path,
            'thumb' => self::createThumbnail($path),
        ];
    }

    /**
     * Hapus foto asli beserta thumbnail-nya.
     */
    public static function delete(?string $path, ?string $thumb = null): void
    {
        $disk = Storage::disk('public');

        if ($path) {
            $disk->delete($path);
        }

        if ($thumb) {
            $disk->delete($thumb);
        }
    }

    /**
     * URL publik sebuah foto (thumbnail bila ada).
     */
    public static function url(?string $path, ?string $thumb = null): ?string
    {
        if ($thumb) {
            return asset('storage/'.$thumb);
        }

        return $path ? asset('storage/'.$path) : null;
    }

    private static function createThumbnail(string $originalPath): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk('public');
        $fullPath = $disk->path($originalPath);

        if (! is_file($fullPath)) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($fullPath));

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::MAX_WIDTH) {
            imagedestroy($source);

            return null;
        }

        $newWidth = self::MAX_WIDTH;
        $newHeight = (int) round($height * (self::MAX_WIDTH / $width));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, 82);
        $contents = ob_get_clean();
        imagedestroy($canvas);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $thumbPath = dirname($originalPath).'/thumbs/'.pathinfo($originalPath, PATHINFO_FILENAME).'.jpg';
        $disk->put($thumbPath, $contents);

        return $thumbPath;
    }
}
