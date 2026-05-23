<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('date_label')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('amount_label')->nullable();
            $table->string('currency', 8)->default('EUR');
            $table->string('status', 16)->default('paid'); // paid | failed | refunded
            $table->string('receipt_url')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['subscription_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
