<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['voeding_adviezen', 'management_adviezen', 'beweging_adviezen'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('layout', 32)->default('normal');
                $table->string('icon')->nullable();
            });
        }

        foreach (['protocol_voeding_adviezen', 'protocol_management_adviezen', 'protocol_beweging_adviezen'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('layout', 32)->default('normal');
            });
        }
    }

    public function down(): void
    {
        foreach (['protocol_beweging_adviezen', 'protocol_management_adviezen', 'protocol_voeding_adviezen'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('layout'));
        }

        foreach (['beweging_adviezen', 'management_adviezen', 'voeding_adviezen'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(['layout', 'icon']));
        }
    }
};
