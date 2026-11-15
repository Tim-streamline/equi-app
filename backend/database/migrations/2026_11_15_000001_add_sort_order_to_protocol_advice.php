<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Admins order the protocol measure library; existing rows keep their alphabetical order as the starting point. */
return new class extends Migration
{
    private const TABLES = ['voeding_adviezen', 'management_adviezen', 'beweging_adviezen'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0));
            DB::statement(<<<SQL
                UPDATE {$table} AS t SET sort_order = ranked.position
                FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY title, id) AS position FROM {$table}) AS ranked
                WHERE ranked.id = t.id
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('sort_order'));
        }
    }
};
