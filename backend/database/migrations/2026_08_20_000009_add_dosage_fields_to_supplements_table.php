<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplements', function (Blueprint $table) {
            $table->enum('dosis_type', ['per_kg', 'per_600_kg', 'vast'])->nullable()->after('supplement_type');
            $table->double('dosis')->nullable()->after('dosis_type');
            $table->enum('unit', ['g', 'ml', 'theelepel', 'eetlepel'])->nullable()->after('dosis');
        });
    }

    public function down(): void
    {
        Schema::table('supplements', function (Blueprint $table) {
            $table->dropColumn(['dosis_type', 'dosis', 'unit']);
        });
    }
};
