<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A removed product can have recorded doses on a day whose schedule isn't frozen yet.
        Schema::create('protocol_removed_doses', function (Blueprint $table) {
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('items');
            $table->primary(['protocol_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_removed_doses');
    }
};
