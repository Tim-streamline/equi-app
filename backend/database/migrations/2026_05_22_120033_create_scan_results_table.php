<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_results', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name')->nullable(); // snapshot
            $table->string('brand')->nullable();
            $table->timestamp('scanned_at');
            $table->string('when_label')->nullable();
            $table->unsignedTinyInteger('score');
            $table->string('rating', 16); // Goed | Matig | Slecht
            $table->text('advice')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('bookmarked')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};
