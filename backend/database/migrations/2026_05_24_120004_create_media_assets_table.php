<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable uploaded media (images, video, audio) for the content library.
 * Assets may belong to a specific library item or sit in the shared pool
 * (library_item_id null) so they can be embedded in any article body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('library_item_id')->nullable()->constrained('library_items')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('type', 8);                 // image | video | audio
            $table->string('disk', 32)->default('public');
            $table->string('path');                    // path on the disk
            $table->string('url');                     // public URL
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index(['library_item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
