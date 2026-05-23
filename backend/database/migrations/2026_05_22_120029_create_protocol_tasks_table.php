<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_tasks', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('phase_id')->nullable()->constrained('protocol_phases')->nullOnDelete();
            $table->string('label');
            $table->string('meta')->nullable();
            $table->string('kind', 32); // feeding | observation | care | other
            $table->unsignedInteger('order')->default(0);
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->foreignUuid('reference_item_id')->nullable()->constrained('library_items')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_tasks');
    }
};
