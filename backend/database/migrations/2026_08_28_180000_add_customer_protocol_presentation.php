<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->json('customer_settings')->nullable());
        Schema::table('library_items', fn (Blueprint $table) => $table->unsignedInteger('credit_cost')->default(0));
        Schema::create('library_credit_balances', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('balance')->default(0);
            $table->timestamps();
        });
        Schema::create('library_unlocks', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'item_id']);
        });
        Schema::create('protocol_weekly_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->text('note');
            $table->unsignedTinyInteger('mood')->nullable();
            $table->timestamps();
            $table->unique(['protocol_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_weekly_updates');
        Schema::dropIfExists('library_unlocks');
        Schema::dropIfExists('library_credit_balances');
        Schema::table('library_items', fn (Blueprint $table) => $table->dropColumn('credit_cost'));
        Schema::table('protocols', fn (Blueprint $table) => $table->dropColumn('customer_settings'));
    }
};
