<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_replies', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('author_therapist_id')->nullable()->constrained('therapists')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->string('author_initial', 4)->nullable();
            $table->string('author_avatar_color', 16)->nullable();
            $table->boolean('author_is_expert')->default(false);
            $table->text('body');
            $table->string('when_label')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_replies');
    }
};
