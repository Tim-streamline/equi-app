<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_home_preferences', function (Blueprint $table) {
            // One deterministic row per account, also when first edited offline.
            $table->foreignUuid('id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('seasonal_tips_enabled')->default(true);
            $table->json('dismissed_tip_ids')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_home_preferences');
    }
};
