<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->dropColumn('flag_if');
        });
    }

    public function down(): void
    {
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->jsonb('flag_if')->nullable();
        });
    }
};
