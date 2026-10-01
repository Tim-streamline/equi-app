<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLibraryAccess extends Command
{
    protected $signature = 'library:refresh-access';

    protected $description = 'Reconcile library sync access after scheduled publication and subscription expiry';

    public function handle(): int
    {
        DB::statement('SELECT refresh_library_access(?::timestamp)', [now()->utc()->format('Y-m-d H:i:s')]);
        $this->info('Library sync access reconciled.');

        return self::SUCCESS;
    }
}
