<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_evaluations', fn (Blueprint $table) => $table->unsignedBigInteger('revision')->default(1));
    }

    public function down(): void
    {
        Schema::table('protocol_evaluations', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
