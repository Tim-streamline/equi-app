<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_questionnaires', function (Blueprint $table) {
            $table->jsonb('none_options')->default('[]')->after('disclaimer_long');
        });
    }

    public function down(): void
    {
        Schema::table('intake_questionnaires', function (Blueprint $table) {
            $table->dropColumn('none_options');
        });
    }
};
