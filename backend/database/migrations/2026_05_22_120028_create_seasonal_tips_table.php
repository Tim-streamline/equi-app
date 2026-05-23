<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasonal_tips', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('month', 16); // 'mei'
            $table->unsignedTinyInteger('month_order'); // 1..12
            $table->text('body');
            $table->foreignUuid('cta_item_id')->nullable()->constrained('library_items')->nullOnDelete();
            $table->boolean('active')->default(false);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasonal_tips');
    }
};
