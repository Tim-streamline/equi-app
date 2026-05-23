<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_reactions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16); // 'post' | 'reply'
            $table->uuid('target_id');
            $table->string('kind', 16)->default('like');
            $table->timestamps();
            $table->unique(['user_id', 'target_type', 'target_id', 'kind']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_reactions');
    }
};
