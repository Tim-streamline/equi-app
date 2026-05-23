<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 16); // active | cancelled | past_due
            $table->unsignedInteger('price_cents');
            $table->string('currency', 8)->default('EUR');
            $table->string('interval', 16);
            $table->date('started_at')->nullable();
            $table->string('started_label')->nullable();
            $table->date('renews_at')->nullable();
            $table->string('renews_label')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->unsignedSmallInteger('max_horses')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
