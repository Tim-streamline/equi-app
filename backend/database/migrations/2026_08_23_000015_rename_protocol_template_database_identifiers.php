<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameIdentifiers('protocol_type', 'protocol_template');
    }

    public function down(): void
    {
        $this->renameIdentifiers('protocol_template', 'protocol_type');
    }

    private function renameIdentifiers(string $from, string $to): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = DB::select(<<<'SQL'
            select constraints.conname, tables.relname as table_name
            from pg_constraint as constraints
            inner join pg_class as tables on tables.oid = constraints.conrelid
            inner join pg_namespace as schemas on schemas.oid = tables.relnamespace
            where schemas.nspname = current_schema()
              and constraints.conname like ?
            order by constraints.conname
            SQL, ["%{$from}%"]);

        foreach ($constraints as $constraint) {
            $newName = str_replace($from, $to, $constraint->conname);

            DB::statement(sprintf(
                'alter table %s rename constraint %s to %s',
                $this->quoteIdentifier($constraint->table_name),
                $this->quoteIdentifier($constraint->conname),
                $this->quoteIdentifier($newName),
            ));
        }

        $indexes = DB::select(<<<'SQL'
            select indexname
            from pg_indexes
            where schemaname = current_schema()
              and indexname like ?
            order by indexname
            SQL, ["%{$from}%"]);

        foreach ($indexes as $index) {
            $newName = str_replace($from, $to, $index->indexname);

            DB::statement(sprintf(
                'alter index %s rename to %s',
                $this->quoteIdentifier($index->indexname),
                $this->quoteIdentifier($newName),
            ));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
