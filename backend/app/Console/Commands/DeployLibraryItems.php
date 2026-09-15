<?php

namespace App\Console\Commands;

use App\Support\LibraryDeployment;
use Illuminate\Console\Command;
use Throwable;

class DeployLibraryItems extends Command
{
    protected $signature = 'deploy-library-items
        {--export : Export this database and its library media to the bundle}
        {--verify : Verify the bundle without importing it}
        {--path= : Bundle directory (defaults to project-root/deploy/lib-assets)}';

    protected $description = 'Export or deploy the complete library catalog and its uploaded media';

    public function handle(LibraryDeployment $deployment): int
    {
        if ($this->option('export') && $this->option('verify')) {
            $this->error('Choose either --export or --verify.');

            return self::FAILURE;
        }

        $path = $this->option('path') ?: base_path('../deploy/lib-assets');

        try {
            $progress = fn (string $message) => $this->line($message);
            $manifest = match (true) {
                (bool) $this->option('export') => $deployment->export($path, $progress),
                (bool) $this->option('verify') => $deployment->verify($path, $progress),
                default => $deployment->deploy($path, $progress),
            };
            $action = $this->option('export') ? 'Exported' : ($this->option('verify') ? 'Verified' : 'Deployed');
            $this->info(sprintf('%s %d library items, %d media assets and %d files (%.2f GiB). Bundle: %s',
                $action, count($manifest['records']['library_items']), count($manifest['records']['media_assets']),
                count($manifest['files']), array_sum(array_column($manifest['files'], 'size_bytes')) / 1024 ** 3, $path));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
