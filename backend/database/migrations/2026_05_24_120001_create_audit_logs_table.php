<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every privileged admin mutation. Required by the admin
 * spec: actor, action, target, before/after values, IP/agent and an
 * optional reason. Server-owned — never synced to mobile clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 64);              // created | updated | deleted | login | impersonate ...
            $table->string('target_type')->nullable(); // model morph alias e.g. "user", "horse"
            $table->uuid('target_id')->nullable();
            $table->string('target_label')->nullable(); // human-readable snapshot
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
