<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Library advice is archived instead of deleted, so protocol snapshots and overrides never lose their source. */
return new class extends Migration
{
    private const TABLES = ['voeding_adviezen', 'management_adviezen', 'beweging_adviezen'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('archived_at'));
        }
    }
};
