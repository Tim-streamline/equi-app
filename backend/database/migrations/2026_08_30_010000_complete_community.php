<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', fn (Blueprint $table) => $table->timestamp('edited_at')->nullable());
        Schema::table('community_replies', function (Blueprint $table) {
            $table->foreignUuid('parent_reply_id')->nullable()->constrained('community_replies')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
        });
        Schema::create('community_bookmarks', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'post_id']);
        });
        Schema::create('community_mutes', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->uuid('target_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'target_type', 'target_id']);
        });
        Schema::create('community_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
        // Remove the abandoned personalization filter, retaining existing posts.
        $focus = DB::table('community_categories')->where('slug', 'mijn-focus')->pluck('id');
        DB::table('community_posts')->whereIn('category_id', $focus)->update(['category_id' => null]);
        DB::table('community_categories')->whereIn('id', $focus)->delete();
        DB::table('account_settings')->where('route', '/(tabs)/account/community')->update(['route' => '/(tabs)/(pager)/community']);
    }

    public function down(): void
    {
        Schema::dropIfExists('community_media');
        Schema::dropIfExists('community_mutes');
        Schema::dropIfExists('community_bookmarks');
        Schema::table('community_replies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_reply_id');
            $table->dropColumn('edited_at');
        });
        Schema::table('community_posts', fn (Blueprint $table) => $table->dropColumn('edited_at'));
    }
};
