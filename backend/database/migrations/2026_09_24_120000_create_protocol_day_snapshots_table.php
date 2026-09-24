<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_day_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Intentionally independent of live supplements: removing one must not erase history.
            $table->json('items');
            $table->timestamps();
            $table->unique(['protocol_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_day_snapshots');
    }
};
