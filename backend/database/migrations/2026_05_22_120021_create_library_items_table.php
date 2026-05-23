<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_items', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('slug')->unique();
            $table->string('kind', 32); // Kruid | Voeding | Diagnose | Cursus | Locatie | Symptoom
            $table->string('format', 16); // article | video | course | program
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('body')->nullable();
            $table->string('video_url')->nullable();
            $table->string('hero_image_url')->nullable();
            $table->string('duration_label')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->foreignUuid('author_therapist_id')->nullable()->constrained('therapists')->nullOnDelete();
            $table->string('views_label')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_plus')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['format', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_items');
    }
};
