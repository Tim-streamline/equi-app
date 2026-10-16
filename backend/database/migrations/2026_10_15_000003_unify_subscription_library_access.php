<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/library-subscription-access.sql')));
        DB::statement('SELECT refresh_library_access()');
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/library-sync.sql')));
        DB::statement('SELECT refresh_library_access()');
    }
};
