<?php

declare(strict_types=1);

namespace App\Filesystem;

use Cloudinary\Api as CloudinaryApi;
use Cloudinary\Api\NotFound;
use Cloudinary\Uploader as CloudinaryUploader;
use DateTimeImmutable;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Throwable;

/**
 * Stores files as Cloudinary assets instead of on local disk.
 *
 * Render's filesystem is wiped on every deploy and every scale-to-zero, so
 * anything written to the "public" disk there disappears within minutes.
 * This adapter makes uploads durable by sending them to Cloudinary instead,
 * using the given Flysystem path as the Cloudinary public_id (extension
 * stripped, then restored explicitly via the `format` param) so path, delete
 * key, and URL all derive from the same string.
 *
 * Built against cloudinary/cloudinary_php 1.x, whose API is the legacy
 * global \Cloudinary / \Cloudinary\Uploader / \Cloudinary\Api statics rather
 * than the v2 Cloudinary\Cloudinary object — config() must be called once
 * (see CloudinaryServiceProvider) before this adapter does anything.
 */
final class CloudinaryAdapter implements FilesystemAdapter, PublicUrlGenerator
{
    private readonly CloudinaryApi $api;

    private readonly ExtensionMimeTypeDetector $mimeDetector;

    public function __construct(private readonly string $cloudName)
    {
        $this->api = new CloudinaryApi;
        $this->mimeDetector = new ExtensionMimeTypeDetector;
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->api->resource($this->publicId($path), ['resource_type' => 'image']);

            return true;
        } catch (NotFound) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        // Cloudinary "folders" are a display convenience derived from public_id
        // prefixes, not real objects — nothing to check.
        return true;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $body = stream_get_contents($contents);

        if ($body === false) {
            throw UnableToWriteFile::atLocation($path, 'Could not read the given stream.');
        }

        $this->upload($path, $body);
    }

    private function upload(string $path, string $contents): void
    {
        $tmp = tmpfile();

        if ($tmp === false) {
            throw UnableToWriteFile::atLocation($path, 'Could not open a temp file for upload.');
        }

        try {
            fwrite($tmp, $contents);
            $meta = stream_get_meta_data($tmp);

            CloudinaryUploader::upload($meta['uri'], [
                'public_id' => $this->publicId($path),
                'format' => $this->extension($path),
                'resource_type' => 'image',
                'overwrite' => true,
                'unique_filename' => false,
                'use_filename' => false,
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        } finally {
            fclose($tmp);
        }
    }

    /**
     * Fetches the asset's bytes over cURL directly, using the CA bundle that
     * ships with cloudinary_php.
     *
     * Not Laravel's Http facade: on a machine whose system CA store is
     * missing or stale (common on a bare Windows/XAMPP install), Guzzle's
     * default verification fails even though Cloudinary's own SDK calls
     * succeed — those explicitly point curl at their bundled cacert.pem
     * rather than trusting the system store. Reusing that same file here
     * keeps every Cloudinary call working under the same trust root.
     */
    public function read(string $path): string
    {
        $ch = curl_init($this->publicUrl($path, new Config));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO => dirname((new \ReflectionClass(CloudinaryUploader::class))->getFileName()).'/cacert.pem',
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw UnableToReadFile::fromLocation($path, $error !== '' ? $error : "HTTP status {$status}");
        }

        return $body;
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            CloudinaryUploader::destroy($this->publicId($path), [
                'resource_type' => 'image',
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->api->delete_resources_by_prefix(trim($path, '/').'/', [
                'resource_type' => 'image',
            ]);
        } catch (Throwable) {
            // Nothing under this prefix — not an error for our purposes.
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // No-op: Cloudinary creates the folder implicitly on first upload.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Every asset in the bucket is public; there is nothing per-file to set.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $mime = $this->mimeDetector->detectMimeTypeFromPath($path);

        if ($mime === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unknown extension.');
        }

        return new FileAttributes($path, mimeType: $mime);
    }

    public function lastModified(string $path): FileAttributes
    {
        $asset = $this->asset($path);

        $timestamp = isset($asset['created_at'])
            ? (new DateTimeImmutable((string) $asset['created_at']))->getTimestamp()
            : null;

        return new FileAttributes($path, lastModified: $timestamp);
    }

    public function fileSize(string $path): FileAttributes
    {
        $asset = $this->asset($path);

        return new FileAttributes($path, fileSize: isset($asset['bytes']) ? (int) $asset['bytes'] : null);
    }

    private function asset(string $path): \Cloudinary\Api\Response
    {
        try {
            return $this->api->resource($this->publicId($path), ['resource_type' => 'image']);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', $e->getMessage(), $e);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $result = $this->api->resources([
                'type' => 'upload',
                'resource_type' => 'image',
                'prefix' => trim($path, '/'),
                'max_results' => 500,
            ]);
        } catch (Throwable) {
            return;
        }

        foreach ($result['resources'] ?? [] as $resource) {
            yield new FileAttributes(
                path: $resource['public_id'].'.'.$resource['format'],
                fileSize: isset($resource['bytes']) ? (int) $resource['bytes'] : null,
            );
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            CloudinaryUploader::rename(
                $this->publicId($source),
                $this->publicId($destination),
                ['resource_type' => 'image', 'overwrite' => true],
            );
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($destination, $e->getMessage(), $e);
        }
    }

    /**
     * Copies by handing Cloudinary the source asset's own URL as the upload
     * source, so Cloudinary fetches it server-to-server — this is what moves
     * a Livewire/Filament temp upload to its final path on save, and it must
     * not round-trip the bytes through this machine's curl/SSL stack.
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            CloudinaryUploader::upload($this->getUrl($source), [
                'public_id' => $this->publicId($destination),
                'format' => $this->extension($destination),
                'resource_type' => 'image',
                'overwrite' => true,
                'unique_filename' => false,
                'use_filename' => false,
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function publicUrl(string $path, Config $config): string
    {
        return $this->getUrl($path);
    }

    /**
     * Laravel's FilesystemAdapter::url() looks for this exact method name on
     * the adapter — it does not check for League's PublicUrlGenerator
     * interface — so both must exist and agree.
     */
    public function getUrl(string $path): string
    {
        return sprintf(
            'https://res.cloudinary.com/%s/image/upload/%s.%s',
            $this->cloudName,
            $this->publicId($path),
            $this->extension($path),
        );
    }

    /** The Cloudinary public_id for a path: the path with its extension stripped. */
    private function publicId(string $path): string
    {
        $path = ltrim($path, '/');
        $dot = strrpos($path, '.');

        return $dot === false ? $path : substr($path, 0, $dot);
    }

    /** Falls back to webp — every path this app writes has an extension, but a bare one still needs a format. */
    private function extension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : 'webp';
    }
}
