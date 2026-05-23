<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horse_focus', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('focus_topic_id')->constrained()->cascadeOnDelete();
            $table->string('extra_label')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
            $table->unique(['horse_id', 'focus_topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horse_focus');
    }
};
