<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORIGINAL_UNITS = ['g', 'ml', 'theelepel', 'eetlepel', 'druppels', 'pillen'];

    private const EXPANDED_UNITS = [...self::ORIGINAL_UNITS, 'capsules'];

    public function up(): void
    {
        $this->replaceUnitConstraint(self::EXPANDED_UNITS);
    }

    public function down(): void
    {
        DB::table('supplements')
            ->where('unit', 'capsules')
            ->update(['unit' => null]);

        $this->replaceUnitConstraint(self::ORIGINAL_UNITS);
    }

    /** @param array<int, string> $units */
    private function replaceUnitConstraint(array $units): void
    {
        $driver = DB::getDriverName();
        $quotedUnits = implode(', ', array_map(fn (string $unit): string => DB::getPdo()->quote($unit), $units));

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE supplements DROP CONSTRAINT IF EXISTS supplements_unit_check');
            DB::statement("ALTER TABLE supplements ADD CONSTRAINT supplements_unit_check CHECK (unit IN ({$quotedUnits}))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE supplements MODIFY COLUMN unit ENUM({$quotedUnits}) NULL");
        }
    }
};
