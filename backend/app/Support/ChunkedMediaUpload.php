<?php

namespace App\Support;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ChunkedMediaUpload
{
    private const ROOT = 'media-chunks';

    /** @return array<string, mixed> */
    public function start(string $ownerId, ?string $libraryItemId, int $length): array
    {
        $max = max(array_map('intval', config('media.limits')));

        if ($length < 1 || $length > $max) {
            throw ValidationException::withMessages([
                'file' => 'The upload must be between 1 byte and '.$this->humanSize($max).'.',
            ]);
        }

        $id = (string) Str::uuid();
        $metadata = [
            'id' => $id,
            'owner_id' => $ownerId,
            'library_item_id' => $libraryItemId,
            'name' => null,
            'length' => $length,
            'offset' => 0,
            'status' => 'uploading',
            'asset_id' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        File::ensureDirectoryExists($this->absoluteDirectory($id));
        $this->writeMetadata($metadata);

        return $metadata;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $path = $this->metadataPath($id);
        if (! File::isFile($path)) {
            return null;
        }

        $metadata = json_decode((string) File::get($path), true);

        return is_array($metadata) ? $metadata : null;
    }

    /**
     * Append a request body without loading it into PHP memory.
     *
     * @param  resource  $input
     * @return array{metadata: array<string, mixed>, conflict: bool}
     */
    public function append(string $id, string $ownerId, int $offset, int $length, ?string $name, $input): array
    {
        $existing = $this->find($id);
        if (! $existing || ! hash_equals((string) $existing['owner_id'], $ownerId)) {
            abort(404);
        }

        $lock = fopen($this->lockPath($id), 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the upload.');
        }

        try {
            $metadata = $this->find($id);
            if (! $metadata || ! hash_equals((string) $metadata['owner_id'], $ownerId)) {
                abort(404);
            }
            if ((int) $metadata['length'] !== $length) {
                throw ValidationException::withMessages(['file' => 'The upload length changed while resuming.']);
            }
            if ($metadata['status'] === 'complete') {
                return ['metadata' => $metadata, 'conflict' => false];
            }
            if ((int) $metadata['offset'] !== $offset) {
                return ['metadata' => $metadata, 'conflict' => true];
            }

            $partPath = $this->partPath($id);
            $part = fopen($partPath, 'c+b');
            if ($part === false) {
                throw new RuntimeException('Could not open the temporary upload.');
            }

            try {
                fseek($part, 0, SEEK_END);
                $actualOffset = ftell($part);
                if ($actualOffset !== $offset) {
                    $metadata['offset'] = $actualOffset;
                    $this->writeMetadata($metadata);

                    return ['metadata' => $metadata, 'conflict' => true];
                }

                $maxChunk = (int) config('media.chunk_size');
                $written = stream_copy_to_stream($input, $part, $maxChunk + 1);
                if ($written === false || $written < 1 || $written > $maxChunk || ($offset + $written) > $length) {
                    ftruncate($part, $offset);
                    throw ValidationException::withMessages(['file' => 'The uploaded chunk has an invalid size.']);
                }
                fflush($part);
            } finally {
                fclose($part);
            }

            $metadata['name'] ??= $this->safeName($name);
            $metadata['offset'] = $offset + $written;
            $metadata['updated_at'] = now()->toIso8601String();
            $this->writeMetadata($metadata);

            if ((int) $metadata['offset'] === $length) {
                $metadata = $this->finish($metadata);
            }

            return ['metadata' => $metadata, 'conflict' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $metadata */
    public function revert(array $metadata): void
    {
        $lock = fopen($this->lockPath((string) $metadata['id']), 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the upload.');
        }

        try {
            $metadata = $this->find((string) $metadata['id']) ?? $metadata;
            if ($metadata['status'] === 'complete' && $metadata['asset_id']) {
                $asset = MediaAsset::find($metadata['asset_id']);
                if ($asset) {
                    AuditLogger::log('deleted', $asset, before: $asset->getAttributes(), label: $asset->original_name);
                    $asset->deleteFile();
                    $asset->delete();
                }
            }

            File::deleteDirectory($this->absoluteDirectory((string) $metadata['id']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function pruneStale(): int
    {
        $root = Storage::disk('local')->path(self::ROOT);
        if (! File::isDirectory($root)) {
            return 0;
        }

        $cutoff = now()->subHours((int) config('media.chunk_ttl_hours'));
        $deleted = 0;

        foreach (File::directories($root) as $directory) {
            $lock = fopen($directory.'/.lock', 'c+b');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                continue;
            }

            try {
                $metadataPath = $directory.'/metadata.json';
                $metadata = File::isFile($metadataPath)
                    ? json_decode((string) File::get($metadataPath), true)
                    : null;
                $updatedAt = is_array($metadata) && isset($metadata['updated_at'])
                    ? \Illuminate\Support\Carbon::parse($metadata['updated_at'])
                    : \Illuminate\Support\Carbon::createFromTimestamp(File::lastModified($directory));

                if ($updatedAt->lessThan($cutoff) && File::deleteDirectory($directory)) {
                    $deleted++;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $deleted;
    }

    /** @param array<string, mixed> $metadata
     *  @return array<string, mixed>
     */
    private function finish(array $metadata): array
    {
        $source = $this->partPath((string) $metadata['id']);
        $name = $this->safeName((string) ($metadata['name'] ?: 'upload.bin'));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream';
        $type = $this->detectType($mime);

        if (! $type || ! in_array($extension, config("media.extensions.{$type}"), true)) {
            throw ValidationException::withMessages(['file' => 'The assembled file type is not supported.']);
        }

        $limit = (int) config("media.limits.{$type}");
        if ((int) $metadata['length'] > $limit) {
            throw ValidationException::withMessages([
                'file' => ucfirst($type).' files can be up to '.$this->humanSize($limit).'.',
            ]);
        }

        $path = 'library/'.$type.'/'.Str::uuid().'.'.$extension;
        $destination = Storage::disk('public')->path($path);
        File::ensureDirectoryExists(dirname($destination));

        if (! File::move($source, $destination)) {
            throw new RuntimeException('Could not store the completed upload.');
        }

        try {
            [$width, $height] = $this->dimensions($type, $destination);
            $asset = MediaAsset::create([
                'library_item_id' => $metadata['library_item_id'],
                'uploaded_by' => $metadata['owner_id'],
                'type' => $type,
                'disk' => 'public',
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'original_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => $metadata['length'],
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $exception) {
            File::delete($destination);
            throw $exception;
        }

        AuditLogger::log('created', $asset, after: $asset->getAttributes(), label: $asset->original_name);

        $metadata['status'] = 'complete';
        $metadata['asset_id'] = $asset->id;
        $metadata['updated_at'] = now()->toIso8601String();
        $this->writeMetadata($metadata);

        return $metadata;
    }

    private function detectType(string $mime): ?string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => null,
        };
    }

    /** @return array{0:?int,1:?int} */
    private function dimensions(string $type, string $path): array
    {
        if ($type === 'image' && function_exists('getimagesize') && ($info = @getimagesize($path))) {
            return [$info[0], $info[1]];
        }

        return [null, null];
    }

    private function safeName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', trim((string) $name)));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'upload.bin';

        return mb_substr($name, 0, 255);
    }

    /** @param array<string, mixed> $metadata */
    private function writeMetadata(array $metadata): void
    {
        $path = $this->metadataPath((string) $metadata['id']);
        $temporary = $path.'.tmp';
        File::put($temporary, json_encode($metadata, JSON_THROW_ON_ERROR));
        File::move($temporary, $path);
    }

    private function absoluteDirectory(string $id): string
    {
        return Storage::disk('local')->path(self::ROOT.'/'.$id);
    }

    private function metadataPath(string $id): string
    {
        return $this->absoluteDirectory($id).'/metadata.json';
    }

    private function partPath(string $id): string
    {
        return $this->absoluteDirectory($id).'/upload.part';
    }

    private function lockPath(string $id): string
    {
        return $this->absoluteDirectory($id).'/.lock';
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1), '0'), '.').' GB'
            : (int) ceil($bytes / (1024 * 1024)).' MB';
    }
}
