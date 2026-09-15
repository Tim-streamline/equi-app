<?php

namespace App\Support;

use App\Models\LibraryArticleSection;
use App\Models\LibraryCategory;
use App\Models\LibraryChapter;
use App\Models\LibraryItem;
use App\Models\LibraryItemCategory;
use App\Models\MediaAsset;
use App\Models\Therapist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LibraryDeployment
{
    private const MODELS = [
        'therapists' => Therapist::class,
        'library_categories' => LibraryCategory::class,
        'library_items' => LibraryItem::class,
        'library_chapters' => LibraryChapter::class,
        'library_article_sections' => LibraryArticleSection::class,
        'library_item_categories' => LibraryItemCategory::class,
        'media_assets' => MediaAsset::class,
    ];

    private const URL_PATTERN = '~(?:https?://|/storage/)[^\s<>"\'()]+~u';

    public function export(string $directory, ?callable $progress = null): array
    {
        $records = DB::transaction(function (): array {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $records = [];
            foreach (self::MODELS as $table => $class) {
                $query = DB::table($table)->select($this->fields($table))->orderBy('id');
                if ($table === 'therapists') {
                    $query->whereIn('id', DB::table('library_items')->select('author_therapist_id'));
                }
                $records[$table] = $query->get()->map(fn (object $row) => (array) $row)->all();
            }

            return $records;
        });

        if ($records['library_items'] === []) {
            throw new RuntimeException('The source library is empty; no bundle was exported.');
        }

        $files = [];
        foreach ($records['media_assets'] as $asset) {
            $this->addFile($files, $asset['disk'], $asset['path'], $asset['url']);
            if ($asset['thumbnail_path']) {
                $this->addFile($files, $asset['disk'], $asset['thumbnail_path'], $asset['thumbnail_url']);
            }
        }

        // Older content can reference public uploads without a media_assets row.
        $publicPrefixes = [rtrim(Storage::disk('public')->url(''), '/').'/', rtrim(config('app.url'), '/').'/storage/', '/storage/'];
        foreach ($files as $file) {
            if ($file['disk'] !== 'public') {
                continue;
            }
            foreach ($file['urls'] as $url) {
                if (str_ends_with($url, $file['path'])) {
                    $publicPrefixes[] = substr($url, 0, -strlen($file['path']));
                }
            }
        }
        foreach ($records as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    if (! is_string($value)) {
                        continue;
                    }
                    preg_match_all(self::URL_PATTERN, html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $matches);
                    foreach ($matches[0] as $url) {
                        foreach (array_unique($publicPrefixes) as $prefix) {
                            if (str_starts_with($url, $prefix)) {
                                $path = rawurldecode(explode('?', explode('#', substr($url, strlen($prefix)), 2)[0], 2)[0]);
                                $this->addFile($files, 'public', $path, $url);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $this->makeDirectory($directory.'/files');
        foreach ($files as &$file) {
            $progress && $progress('Exporting '.$file['disk'].':'.$file['path']);
            $source = $this->diskPath($file['disk'], $file['path']);
            $temporary = $directory.'/files/.partial-'.Str::uuid();
            try {
                $result = $this->copyFile($source, $temporary);
                $blob = $directory.'/files/'.$result['sha256'];
                if (! rename($temporary, $blob)) {
                    throw new RuntimeException("Cannot publish media file: {$blob}");
                }
                $file = [...$file, ...$result];
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        unset($file);

        $manifest = ['version' => 1, 'exported_at' => now()->toIso8601String(), 'records' => $records, 'files' => array_values($files)];
        $temporary = $directory.'/.manifest-'.Str::uuid();
        try {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($temporary, $json) !== strlen($json) || ! chmod($temporary, 0644) || ! rename($temporary, $directory.'/manifest.json')) {
                throw new RuntimeException('Cannot publish the library manifest.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $manifest;
    }

    public function verify(string $directory, ?callable $progress = null): array
    {
        $manifestPath = $directory.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Library bundle not found: {$manifestPath}. Export it with --export first.");
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->validateManifest($manifest);
        foreach ($manifest['files'] as $file) {
            $progress && $progress('Verifying '.$file['disk'].':'.$file['path']);
            $blob = $directory.'/files/'.$file['sha256'];
            $this->assertContained($directory, $blob);
            if (! is_file($blob) || filesize($blob) !== $file['size_bytes'] || hash_file('sha256', $blob) !== $file['sha256']) {
                throw new RuntimeException('Missing or corrupt bundle file: '.$file['path']);
            }
        }

        return $manifest;
    }

    public function deploy(string $directory, ?callable $progress = null): array
    {
        $manifest = $this->verify($directory, $progress);
        $created = [];
        $urls = [];

        // Check ALL destination collisions before copying any file or changing records.
        foreach ($manifest['files'] as $file) {
            $destination = $this->diskPath($file['disk'], $file['path']);
            if (file_exists($destination) && (! is_file($destination) || hash_file('sha256', $destination) !== $file['sha256'])) {
                throw new RuntimeException('Destination file has different content: '.$file['disk'].':'.$file['path']);
            }
            foreach ($file['urls'] as $url) {
                $urls[$url] = Storage::disk($file['disk'])->url($file['path']);
            }
        }

        try {
            foreach ($manifest['files'] as $file) {
                $destination = $this->diskPath($file['disk'], $file['path']);
                if (! is_file($destination)) {
                    $progress && $progress('Installing '.$file['disk'].':'.$file['path']);
                    $this->makeDirectory(dirname($destination));
                    $temporary = dirname($destination).'/.library-deploy-'.Str::uuid();
                    try {
                        $result = $this->copyFile($directory.'/files/'.$file['sha256'], $temporary);
                        if ($result['sha256'] !== $file['sha256']) {
                            throw new RuntimeException('Media changed while importing: '.$file['path']);
                        }
                        // Publish complete files without overwriting a concurrent upload.
                        if (@link($temporary, $destination)) {
                            $created[] = $destination;
                        } elseif (! is_file($destination) || hash_file('sha256', $destination) !== $file['sha256']) {
                            throw new RuntimeException('Cannot install media without overwriting: '.$destination);
                        }
                    } finally {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                }
            }

            DB::transaction(fn () => $this->importRecords($manifest['records'], $urls));
        } catch (Throwable $exception) {
            foreach ($created as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $exception;
        }

        return $manifest;
    }

    private function importRecords(array $records, array $urls): void
    {
        $ids = [];
        foreach (self::MODELS as $table => $class) {
            $ids[$table] = [];
            foreach ($records[$table] as $source) {
                $row = array_map(fn ($value) => is_string($value)
                    ? preg_replace_callback(self::URL_PATTERN, fn ($match) => $urls[html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5)] ?? $match[0], $value)
                    : $value, $source);
                foreach ($this->foreignKeys($table) as $field => $parent) {
                    if ($row[$field] !== null) {
                        $row[$field] = $ids[$parent][$row[$field]];
                    }
                }
                if ($table === 'media_assets') {
                    $row['url'] = Storage::disk($row['disk'])->url($row['path']);
                    $row['thumbnail_url'] = $row['thumbnail_path'] ? Storage::disk($row['disk'])->url($row['thumbnail_path']) : null;
                }
                $natural = match ($table) {
                    'therapists' => Arr::only($row, ['name', 'title']),
                    'library_items', 'library_categories' => Arr::only($row, ['slug']),
                    // Order is not unique: two blocks may legitimately share it.
                    'library_chapters', 'library_article_sections' => Arr::only($row, ['id']),
                    'library_item_categories' => Arr::only($row, ['item_id', 'category_id']),
                    'media_assets' => Arr::only($row, ['disk', 'path']),
                };
                $model = $this->saveRecord($class, $row, $natural, $table !== 'therapists');
                if (in_array($model->id, $ids[$table], true)) {
                    throw new RuntimeException("Multiple source {$table} records match destination {$model->id}.");
                }
                $ids[$table][$source['id']] = $model->id;
            }
        }

        // Replace only the imported items' child content and category membership.
        foreach (['library_chapters', 'library_article_sections', 'library_item_categories'] as $table) {
            DB::table($table)->whereIn('item_id', array_values($ids['library_items']))
                ->whereNotIn('id', array_values($ids[$table]))->delete();
        }
    }

    private function saveRecord(string $class, array $row, array $natural, bool $overwrite): Model
    {
        $byId = $class::find($row['id']);
        $matches = $class::query()->where($natural)->limit(2)->get();
        if ($matches->count() > 1 || ($byId && $matches->isNotEmpty() && ! $byId->is($matches->first()))) {
            throw new RuntimeException("Ambiguous existing {$class} record for {$row['id']}.");
        }
        $model = $byId ?? $matches->first() ?? new $class;
        if (! $model->exists) {
            $model->forceFill($row);
        } elseif ($overwrite) {
            $model->forceFill(Arr::except($row, ['id']));
        }
        $model->save();

        return $model;
    }

    private function fields(string $table): array
    {
        $fields = (new (self::MODELS[$table]))->getFillable();
        if ($table === 'therapists') {
            $fields[] = 'archived_at';
        }

        return array_values(array_diff(['id', ...$fields], ['uploaded_by']));
    }

    private function foreignKeys(string $table): array
    {
        return match ($table) {
            'library_items' => ['author_therapist_id' => 'therapists'],
            'library_chapters', 'library_article_sections' => ['item_id' => 'library_items'],
            'library_item_categories' => ['item_id' => 'library_items', 'category_id' => 'library_categories'],
            'media_assets' => ['library_item_id' => 'library_items'],
            default => [],
        };
    }

    private function validateManifest(mixed $manifest): void
    {
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Unsupported or invalid library bundle.');
        }
        $ids = [];
        foreach (self::MODELS as $table => $class) {
            if (! is_array($manifest['records'][$table] ?? null)) {
                throw new RuntimeException("Missing bundle records: {$table}");
            }
            $ids[$table] = [];
            foreach ($manifest['records'][$table] as $row) {
                if (! is_array($row) || array_diff($this->fields($table), array_keys($row))
                    || array_diff(array_keys($row), $this->fields($table)) || ! Str::isUuid($row['id']) || isset($ids[$table][$row['id']])) {
                    throw new RuntimeException("Invalid bundle record: {$table}");
                }
                foreach ($this->foreignKeys($table) as $field => $parent) {
                    if ($row[$field] !== null && ! isset($ids[$parent][$row[$field]])) {
                        throw new RuntimeException("Missing {$parent} reference in {$table}.{$field}");
                    }
                }
                $ids[$table][$row['id']] = true;
            }
        }
        if ($ids['library_items'] === []) {
            throw new RuntimeException('The library bundle contains no items.');
        }
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'] ?? '')
                || ! is_int($file['size_bytes'] ?? null) || $file['size_bytes'] < 0
                || ! is_array($file['urls'] ?? null) || array_filter($file['urls'], fn ($url) => ! is_string($url))) {
                throw new RuntimeException('Invalid bundle file metadata.');
            }
            $this->diskPath($file['disk'], $file['path']);
            $key = $file['disk'].':'.$file['path'];
            if (isset($files[$key])) {
                throw new RuntimeException('Duplicate bundle file: '.$key);
            }
            $files[$key] = $file;
        }
        foreach ($manifest['records']['media_assets'] as $asset) {
            foreach (array_filter([$asset['path'], $asset['thumbnail_path']]) as $path) {
                if (! isset($files[$asset['disk'].':'.$path])) {
                    throw new RuntimeException('Media asset missing from bundle: '.$path);
                }
            }
        }
    }

    private function addFile(array &$files, string $disk, string $path, ?string $url): void
    {
        $source = $this->diskPath($disk, $path);
        if (! is_file($source)) {
            throw new RuntimeException("Missing source media: {$disk}:{$path}");
        }
        $key = $disk.':'.$path;
        $files[$key] ??= ['disk' => $disk, 'path' => $path, 'urls' => []];
        $files[$key]['urls'] = array_values(array_unique(array_filter([
            ...$files[$key]['urls'], $url, Storage::disk($disk)->url($path),
            $disk === 'public' ? '/storage/'.$path : null,
        ])));
    }

    private function diskPath(string $disk, string $path): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $disk) || config("filesystems.disks.{$disk}.driver") !== 'local'
            || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || array_intersect(explode('/', $path), ['..', '.'])) {
            throw new RuntimeException("Unsupported disk or unsafe media path: {$disk}:{$path}");
        }
        $root = Storage::disk($disk)->path('');
        $destination = Storage::disk($disk)->path($path);
        $this->assertContained($root, $destination);

        return $destination;
    }

    private function assertContained(string $root, string $path): void
    {
        $root = realpath($root);
        $ancestor = $path;
        while (! file_exists($ancestor) && ! is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $resolved = realpath($ancestor);
        if ($root === false || $resolved === false || ($resolved !== $root && ! str_starts_with($resolved, rtrim($root, '/').'/'))) {
            throw new RuntimeException('Media path leaves its configured directory: '.$path);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create directory: '.$path);
        }
    }

    private function copyFile(string $source, string $destination): array
    {
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('Cannot copy media: '.$source);
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Media copy failed: '.$source);
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }
            if (! fflush($output) || ! chmod($destination, 0644)) {
                throw new RuntimeException('Cannot finish media copy: '.$destination);
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return ['sha256' => hash_final($hash), 'size_bytes' => $size];
    }
}
