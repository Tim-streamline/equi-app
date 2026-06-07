<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_answers', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('response_id')->constrained('intake_responses')->cascadeOnDelete();
            $table->string('section_id', 64);
            $table->string('field_id', 64);
            // JSON-encoded answer value: scalar ("5" / "\"text\""), multi
            // (["a","b"]) or repeater rows ([{...}]). Decoded client-side.
            $table->text('value')->nullable();
            $table->timestamps();
            // One row per question; the client reuses the row id per field.
            $table->unique(['response_id', 'section_id', 'field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_answers');
    }
};
