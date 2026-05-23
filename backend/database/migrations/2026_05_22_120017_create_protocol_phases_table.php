<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_phases', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('title');
            $table->string('state', 16); // done | active | upcoming
            $table->unsignedSmallInteger('week_start')->nullable();
            $table->unsignedSmallInteger('week_end')->nullable();
            $table->string('chip_label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_phases');
    }
};
