<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Stores an uploaded image after re-encoding it.
 *
 * Validation alone only proves a file parses as an image. Re-encoding is what
 * makes it safe: the pixels are decoded and written out as a fresh file, so
 * anything riding along in the original container — EXIF payloads, appended
 * archives, polyglot headers — does not survive. Nothing of the uploaded bytes
 * reaches disk.
 *
 * The stored name is random and the extension comes from the format this class
 * chose, never from the client filename.
 */
final class ImageStorageService
{
    private const MAX_EDGE = 1600;

    private const QUALITY = 82;

    private ImageManager $manager;

    public function __construct()
    {
        // GD rather than Imagick: it ships with the PHP build here and has a
        // far smaller history of decoder vulnerabilities.
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * @param  string  $directory  e.g. "restaurants/3/products"
     * @return string the path within the public disk
     */
    public function store(UploadedFile $file, string $directory): string
    {
        // v4 renamed read() to decodePath().
        $image = $this->manager->decodePath($file->getRealPath());

        // Scale down only: never upscale a small image into a blurry large one.
        $image->scaleDown(width: self::MAX_EDGE, height: self::MAX_EDGE);

        // WebP for every stored image: one format downstream, and a
        // meaningfully smaller payload on a menu full of photographs.
        $encoded = (string) $image->encode(new WebpEncoder(quality: self::QUALITY));

        $path = trim($directory, '/').'/'.Str::random(40).'.webp';

        Storage::disk('public')->put($path, $encoded);

        return $path;
    }

    /** Removes a previously stored image, ignoring one that is already gone. */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    public function url(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}
