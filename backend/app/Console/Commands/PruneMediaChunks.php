<?php

namespace App\Console\Commands;

use App\Support\ChunkedMediaUpload;
use Illuminate\Console\Command;

class PruneMediaChunks extends Command
{
    protected $signature = 'media:prune-chunks';

    protected $description = 'Delete stale temporary media upload chunks';

    public function handle(ChunkedMediaUpload $uploads): int
    {
        $count = $uploads->pruneStale();
        $this->info("Deleted {$count} stale media upload(s).");

        return self::SUCCESS;
    }
}
