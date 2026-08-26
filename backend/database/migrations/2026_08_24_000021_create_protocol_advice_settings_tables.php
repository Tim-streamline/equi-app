<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['voeding_adviezen', 'management_adviezen', 'beweging_adviezen'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
                $table->string('title');
                $table->text('description');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('beweging_adviezen');
        Schema::dropIfExists('management_adviezen');
        Schema::dropIfExists('voeding_adviezen');
    }
};
