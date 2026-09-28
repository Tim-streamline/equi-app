<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('monthly_credits')->default(7);
            $t->unsignedInteger('membership_cap')->default(28);
            $t->unsignedInteger('purchase_validity_months')->default(6);
        });
        DB::table('credit_settings')->insert(['id' => 1]);
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->timestampTz('paid_through')->nullable();
            $t->timestampTz('cancel_requested_at')->nullable();
            $t->timestampTz('ended_at')->nullable();
        });
        // Existing development subscriptions retain their current paid period, but generate no credits.
        DB::table('subscriptions')->whereNotNull('renews_at')->update(['paid_through' => DB::raw('renews_at')]);
        Schema::create('credit_bundles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('label')->nullable();
            $t->unsignedInteger('credits'); $t->unsignedInteger('price_cents');
            $t->string('currency', 3)->default('EUR'); $t->boolean('active')->default(false);
            $t->integer('order')->default(0); $t->timestampsTz();
        });
        Schema::create('credit_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('bundle_id')->nullable()->constrained('credit_bundles')->nullOnDelete();
            $t->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('item_id')->nullable()->constrained('library_items')->nullOnDelete();
            $t->string('kind'); $t->string('status')->default('pending');
            $t->unsignedInteger('credits'); $t->unsignedInteger('price_cents'); $t->string('currency', 3);
            $t->unsignedInteger('validity_months'); $t->string('provider')->nullable();
            $t->string('payment_id')->nullable()->unique(); $t->text('checkout_url')->nullable();
            $t->uuid('request_key'); $t->unique(['user_id', 'request_key']);
            $t->timestampTz('paid_at')->nullable(); $t->timestampTz('period_end')->nullable();
            $t->unique(['subscription_id', 'period_end']);
            $t->timestampTz('reversed_at')->nullable(); $t->unsignedInteger('spent_before_reversal')->default(0);
            $t->timestampsTz();
        });
        Schema::create('credit_grants', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('source'); $t->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('order_id')->nullable()->unique()->constrained('credit_orders')->nullOnDelete();
            $t->timestampTz('expires_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('credit_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('grant_id')->constrained('credit_grants')->cascadeOnDelete();
            $t->integer('amount'); $t->string('type'); $t->string('source');
            $t->timestampTz('expires_at')->nullable(); $t->string('payment_id')->nullable()->index();
            $t->foreignUuid('item_id')->nullable()->constrained('library_items')->nullOnDelete();
            $t->string('description'); $t->text('reason')->nullable(); $t->timestampTz('created_at');
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('credit_reminders', function (Blueprint $t) {
            $t->foreignUuid('grant_id')->constrained('credit_grants')->cascadeOnDelete();
            $t->unsignedInteger('days'); $t->timestampTz('sent_at'); $t->string('ticket_id')->nullable();
            $t->primary(['grant_id', 'days']);
        });
        foreach (DB::table('library_credit_balances')->where('balance', '>', 0)->get() as $balance) {
            $grant = (string) Str::uuid();
            DB::table('credit_grants')->insert(['id' => $grant, 'user_id' => $balance->user_id, 'source' => 'adjustment', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('credit_transactions')->insert(['id' => (string) Str::uuid(), 'user_id' => $balance->user_id, 'grant_id' => $grant, 'amount' => $balance->balance, 'type' => 'adjustment', 'source' => 'adjustment', 'description' => 'Bestaand creditsaldo', 'reason' => 'Opening balance on ledger migration; no payment assumed', 'created_at' => now()]);
        }
    }
    public function down(): void
    {
        foreach (['credit_reminders', 'credit_transactions', 'credit_grants', 'credit_orders', 'credit_bundles', 'credit_settings'] as $table) Schema::dropIfExists($table);
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['paid_through', 'cancel_requested_at', 'ended_at']));
    }
};
