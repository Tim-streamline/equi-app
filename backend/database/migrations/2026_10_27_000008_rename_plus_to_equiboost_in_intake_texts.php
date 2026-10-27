<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The personal protocol is called EquiBoost towards owners; "Plus" is the former name. */
    private const FROM = 'Plus-protocol';

    private const TO = 'EquiBoost-protocol';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replace('intake_fields', ['label', 'hint', 'placeholder', 'submission_blocks']);
            $this->replace('intake_questionnaires', ['safety_config']);
        });
    }

    public function down(): void
    {
        // Keep the current wording; answers and administrator edits are untouched either way.
    }

    private function replace(string $table, array $columns): void
    {
        $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
        foreach ($columns as $column) {
            DB::table($table)->whereRaw("{$column}::text LIKE ?", ['%'.self::FROM.'%'])->orderBy('id')
                ->each(function ($row) use ($table, $column): void {
                    DB::table($table)->where('id', $row->id)->update([$column => str_replace(self::FROM, self::TO, $row->{$column})]);
                });
        }
    }
};
