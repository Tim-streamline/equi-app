<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'protocol_voeding_adviezen' => ['voeding_advies_id', 'voeding_adviezen'],
            'protocol_management_adviezen' => ['management_advies_id', 'management_adviezen'],
            'protocol_beweging_adviezen' => ['beweging_advies_id', 'beweging_adviezen'],
        ] as $tableName => [$sourceColumn, $sourceTable]) {
            Schema::create($tableName, function (Blueprint $table) use ($sourceColumn, $sourceTable) {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
                $table->foreignUuid('protocol_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid($sourceColumn)->constrained($sourceTable)->restrictOnDelete();
                $table->string('title');
                $table->text('description');
                $table->timestamps();

                $table->unique(['protocol_id', $sourceColumn]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_beweging_adviezen');
        Schema::dropIfExists('protocol_management_adviezen');
        Schema::dropIfExists('protocol_voeding_adviezen');
    }
};
