<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_initial', 4)->nullable()->after('name');
            $table->string('avatar_url')->nullable()->after('avatar_initial');
            $table->string('locale', 16)->default('nl-NL')->after('avatar_url');
            $table->string('units_system', 16)->default('metric')->after('locale');
            $table->boolean('notifications_on')->default(true)->after('units_system');
            $table->timestamp('onboarded_at')->nullable()->after('notifications_on');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_initial',
                'avatar_url',
                'locale',
                'units_system',
                'notifications_on',
                'onboarded_at',
            ]);
        });
    }
};
