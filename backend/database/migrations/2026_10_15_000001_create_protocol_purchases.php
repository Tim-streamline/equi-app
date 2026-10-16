<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('protocol_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->uuid('request_key');
            $table->unique(['user_id', 'request_key']);
            $table->string('payment_reference')->nullable()->unique();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestamps();
        });
        // Legacy protocols/intakes remain valid. Old included-intake grants are retained
        // as historical data, but no longer authorize or limit protocol purchases.
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_purchases');
    }
};
