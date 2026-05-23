<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->string('role', 16); // assistant | user
            $table->text('body');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['session_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
