<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplements DROP CONSTRAINT IF EXISTS supplements_dosis_type_check');
            DB::statement("ALTER TABLE supplements ADD CONSTRAINT supplements_dosis_type_check CHECK (dosis_type IN ('per_kg', 'per_600_kg', 'vast'))");
            DB::statement('ALTER TABLE supplements DROP CONSTRAINT IF EXISTS supplements_unit_check');
            DB::statement("ALTER TABLE supplements ADD CONSTRAINT supplements_unit_check CHECK (unit IN ('g', 'ml', 'theelepel', 'eetlepel'))");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE supplements MODIFY dosis_type ENUM('per_kg', 'per_600_kg', 'vast') NULL");
            DB::statement("ALTER TABLE supplements MODIFY unit ENUM('g', 'ml', 'theelepel', 'eetlepel') NULL");
        }
    }

    public function down(): void
    {
        DB::table('supplements')->where('dosis_type', 'per_600_kg')->update(['dosis_type' => 'per_kg']);
        DB::table('supplements')->whereIn('unit', ['theelepel', 'eetlepel'])->update(['unit' => 'g']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplements DROP CONSTRAINT IF EXISTS supplements_dosis_type_check');
            DB::statement("ALTER TABLE supplements ADD CONSTRAINT supplements_dosis_type_check CHECK (dosis_type IN ('per_kg', 'vast'))");
            DB::statement('ALTER TABLE supplements DROP CONSTRAINT IF EXISTS supplements_unit_check');
            DB::statement("ALTER TABLE supplements ADD CONSTRAINT supplements_unit_check CHECK (unit IN ('g', 'ml'))");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE supplements MODIFY dosis_type ENUM('per_kg', 'vast') NULL");
            DB::statement("ALTER TABLE supplements MODIFY unit ENUM('g', 'ml') NULL");
        }
    }
};
