<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_photos', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('observation_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('caption')->nullable();
            $table->string('kind', 32)->nullable(); // skin | stool | feed | other
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_photos');
    }
};
