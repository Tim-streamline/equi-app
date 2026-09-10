<?php

namespace App\Console\Commands;

use App\Models\LibraryItem;
use App\Models\MediaAsset;
use App\Support\LibraryThumbnail;
use Illuminate\Console\Command;

class GenerateLibraryThumbnails extends Command
{
    protected $signature = 'library:thumbnails';

    protected $description = 'Generate missing video thumbnails and update automatic library covers';

    public function handle(LibraryThumbnail $thumbnails): int
    {
        $generated = $failed = 0;
        MediaAsset::where('type', 'video')->whereNull('thumbnail_url')->each(function ($asset) use ($thumbnails, &$generated, &$failed) {
            $thumbnails->generate($asset);
            $asset->thumbnail_url ? $generated++ : $failed++;
        });
        LibraryItem::where('thumbnail_mode', 'auto')->whereIn('format', ['video', 'course', 'program'])
            ->each(fn ($item) => $thumbnails->resolve($item));
        $this->info("Generated: {$generated}. Using placeholder: {$failed}. Manual thumbnails preserved.");

        return self::SUCCESS;
    }
}
