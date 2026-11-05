<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dose_buildups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type');
            $table->decimal('start_dose_ratio', 12, 10);
            $table->unsignedInteger('days');
            $table->json('schedule')->nullable();
            $table->timestamps();
        });
        // Seed here as well as in the seeder: deployed databases need the FK default.
        DB::table('dose_buildups')->insert([
            ['id' => 'f5907240-5f13-4f87-a032-000000000001', 'name' => 'No buildup', 'type' => 'linear', 'start_dose_ratio' => 1, 'days' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'f5907240-5f13-4f87-a032-000000000007', 'name' => '7 days', 'type' => 'linear', 'start_dose_ratio' => 0.25, 'days' => 7, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('dose_buildups')->insert([
            'id' => 'f5907240-5f13-4f87-a032-000000000003',
            'name' => 'Dag 1–2 50%, vanaf dag 3 100%', 'type' => 'custom', 'start_dose_ratio' => 0.5, 'days' => 3,
            'schedule' => json_encode([
                ['day' => 1, 'dose_ratio' => 0.5],
                ['day' => 2, 'dose_ratio' => 0.5],
                ['day' => 3, 'dose_ratio' => 1],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('supplements', function (Blueprint $table) {
            $table->foreignUuid('dose_buildup_id')->default('f5907240-5f13-4f87-a032-000000000001')->constrained('dose_buildups')->restrictOnDelete();
        });
        Schema::table('protocol_phase_supplements', function (Blueprint $table) {
            // Null means no buildup for existing protocols; never infer from today's template.
            $table->json('dose_buildup')->nullable();
            $table->decimal('full_dose_amount', 20, 10)->nullable();
            $table->string('full_dose_unit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('protocol_phase_supplements', fn (Blueprint $table) => $table->dropColumn(['dose_buildup', 'full_dose_amount', 'full_dose_unit']));
        Schema::table('supplements', fn (Blueprint $table) => $table->dropConstrainedForeignId('dose_buildup_id'));
        Schema::dropIfExists('dose_buildups');
    }
};
