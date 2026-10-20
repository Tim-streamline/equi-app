<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class LibrarySyncLock
{
    /** Call inside a transaction, after any account lock and before library-item row locks. */
    public static function acquire(): void
    {
        // Match lock_library_access_sources() in database/sql/library-sync.sql.
        DB::select('SELECT pg_advisory_xact_lock(724119301)');
    }
}
