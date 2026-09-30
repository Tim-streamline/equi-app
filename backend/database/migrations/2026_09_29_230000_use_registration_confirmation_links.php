<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old code challenges cannot be used by the link-based flow.
        DB::table('pending_registrations')->delete();
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'attempts']);
            $table->string('uid_hash', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('pending_registrations')->delete();
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['uid_hash']);
            $table->dropColumn(['uid_hash', 'confirmed_at', 'user_id']);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
        });
    }
};
