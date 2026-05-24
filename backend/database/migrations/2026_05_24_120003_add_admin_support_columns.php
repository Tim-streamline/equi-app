<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the admin support console needs that the app schema didn't have:
 * account disable/restore on users, and an expert-review flag on the scanner
 * catalog + results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('onboarded_at');
            $table->string('admin_note')->nullable()->after('disabled_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('category');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('default_tag');
        });

        Schema::table('scan_results', function (Blueprint $table) {
            $table->boolean('flagged')->default(false)->after('bookmarked');
        });
    }

    public function down(): void
    {
        Schema::table('scan_results', fn (Blueprint $t) => $t->dropColumn('flagged'));
        Schema::table('ingredients', fn (Blueprint $t) => $t->dropColumn('needs_review'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('needs_review'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['disabled_at', 'admin_note']));
    }
};
