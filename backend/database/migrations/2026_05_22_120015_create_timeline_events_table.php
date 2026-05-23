<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('when_label')->nullable();
            $table->string('kind', 32);
            $table->text('message');
            $table->string('ref_type')->nullable();
            $table->uuid('ref_id')->nullable();
            $table->boolean('is_now')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['horse_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
