<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horses', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('breed')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('sex', 16)->nullable(); // merrie | ruin | hengst
            $table->unsignedInteger('weight_kg')->nullable();
            $table->string('stable')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('status', 16)->default('active'); // active | archived
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_note')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horses');
    }
};
