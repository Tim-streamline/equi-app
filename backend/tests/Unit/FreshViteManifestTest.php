<?php

namespace Tests\Unit;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreshViteManifestTest extends TestCase
{
    public function test_flushing_vite_reloads_a_rebuilt_manifest(): void
    {
        $buildDirectory = '../storage/framework/testing/vite-'.Str::uuid();
        $absoluteBuildDirectory = public_path($buildDirectory);

        File::ensureDirectoryExists($absoluteBuildDirectory);

        try {
            $this->writeManifest($absoluteBuildDirectory, 'assets/app-old.js');

            /** @var Vite $vite */
            $vite = app(Vite::class);
            $firstTags = (string) $vite('resources/js/app.js', $buildDirectory);

            $this->writeManifest($absoluteBuildDirectory, 'assets/app-new.js');
            $vite->flush();

            $secondTags = (string) $vite('resources/js/app.js', $buildDirectory);

            $this->assertStringContainsString('assets/app-old.js', $firstTags);
            $this->assertStringContainsString('assets/app-new.js', $secondTags);
            $this->assertStringNotContainsString('assets/app-old.js', $secondTags);
        } finally {
            File::deleteDirectory($absoluteBuildDirectory);
        }
    }

    private function writeManifest(string $buildDirectory, string $file): void
    {
        File::put($buildDirectory.'/manifest.json', json_encode([
            'resources/js/app.js' => [
                'file' => $file,
                'name' => 'app',
                'src' => 'resources/js/app.js',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
