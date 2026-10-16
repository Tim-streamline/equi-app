<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plus_intake_entitlements', function (Blueprint $table) {
            // One included intake for one horse, not a new intake on every renewal.
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->constrained()->restrictOnDelete();
            $table->uuid('booking_id')->unique();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('test_bypass')->default(false);
            $table->timestamps();
        });
        Schema::create('plus_upgrades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->string('payment_status')->default('not_started');
            $table->timestamps();
            $table->unique(['user_id', 'horse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plus_upgrades');
        Schema::dropIfExists('plus_intake_entitlements');
    }
};
