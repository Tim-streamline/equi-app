<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Community moderation support tables. The spec calls these out as new
 * tables needed for reports, moderation actions, and user restrictions, plus
 * soft-moderation columns on the existing community content tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_reports', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->string('subject_type', 16);        // post | reply
            $table->uuid('subject_id');
            $table->foreignUuid('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 64);              // spam | abuse | medical_risk | duplicate | other
            $table->text('detail')->nullable();
            $table->string('status', 16)->default('open'); // open | reviewing | resolved | dismissed
            $table->foreignUuid('resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('status');
        });

        Schema::create('user_restrictions', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);                // warning | mute | ban
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        // Soft-moderation state on existing community content.
        Schema::table('community_posts', function (Blueprint $table) {
            $table->string('moderation_status', 16)->default('visible')->after('order'); // visible | hidden | locked | pinned
            $table->timestamp('reviewed_at')->nullable()->after('moderation_status');
        });

        Schema::table('community_replies', function (Blueprint $table) {
            $table->string('moderation_status', 16)->default('visible')->after('order');
            $table->timestamp('reviewed_at')->nullable()->after('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::table('community_replies', function (Blueprint $table) {
            $table->dropColumn(['moderation_status', 'reviewed_at']);
        });
        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropColumn(['moderation_status', 'reviewed_at']);
        });
        Schema::dropIfExists('user_restrictions');
        Schema::dropIfExists('moderation_reports');
    }
};
