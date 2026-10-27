<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_items', fn (Blueprint $t) => $t->string('equiboost_access', 16)->nullable());
        Schema::table('protocols', fn (Blueprint $t) => $t->timestamp('equiboost_started_at', 6)->nullable());
        DB::statement('UPDATE protocols SET equiboost_started_at = published_at WHERE published_at IS NOT NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION preserve_equiboost_start() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.equiboost_started_at IS NOT NULL THEN
        NEW.equiboost_started_at := OLD.equiboost_started_at;
    ELSIF NEW.status = 'active' AND NEW.published_at IS NOT NULL THEN
        NEW.equiboost_started_at := greatest(NEW.published_at, timezone('UTC', statement_timestamp()));
    ELSE
        NEW.equiboost_started_at := NULL;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER preserve_equiboost_start BEFORE INSERT OR UPDATE ON protocols FOR EACH ROW EXECUTE FUNCTION preserve_equiboost_start();
ALTER TABLE library_items ADD CONSTRAINT library_equiboost_mode CHECK (equiboost_access IS NULL OR equiboost_access IN ('only', 'included'));
SQL);
        DB::unprepared(file_get_contents(database_path('sql/library-equiboost-access.sql')));
        foreach (['library_items' => 'INSERT OR DELETE OR UPDATE OF published_at, credit_cost, is_plus, equiboost_access',
            'protocols' => 'INSERT OR DELETE OR UPDATE OF horse_id, status, published_at, equiboost_started_at',
            'horses' => 'DELETE OR UPDATE OF owner_id, status'] as $table => $events) {
            DB::unprepared("DROP TRIGGER IF EXISTS library_access_lock ON {$table}; DROP TRIGGER IF EXISTS library_access_refresh ON {$table};
                CREATE TRIGGER library_access_lock BEFORE {$events} ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION lock_library_access_sources();
                CREATE TRIGGER library_access_refresh AFTER {$events} ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION refresh_library_access_trigger();");
        }
        DB::statement('SELECT refresh_library_access()');
    }

    public function down(): void
    {
        // Unpublish these items on rollback: older code cannot enforce their access policy.
        DB::table('library_items')->whereNotNull('equiboost_access')->update(['published_at' => null]);
        foreach (['library_items', 'protocols', 'horses'] as $table) DB::unprepared("DROP TRIGGER IF EXISTS library_access_lock ON {$table}; DROP TRIGGER IF EXISTS library_access_refresh ON {$table}");
        DB::unprepared('DROP TRIGGER preserve_equiboost_start ON protocols; DROP FUNCTION preserve_equiboost_start()');
        DB::unprepared(file_get_contents(database_path('sql/library-subscription-access.sql')));
        DB::unprepared('CREATE TRIGGER library_access_lock BEFORE INSERT OR DELETE OR UPDATE OF published_at, credit_cost, is_plus ON library_items FOR EACH STATEMENT EXECUTE FUNCTION lock_library_access_sources();
            CREATE TRIGGER library_access_refresh AFTER INSERT OR DELETE OR UPDATE OF published_at, credit_cost, is_plus ON library_items FOR EACH STATEMENT EXECUTE FUNCTION refresh_library_access_trigger()');
        Schema::table('library_items', fn (Blueprint $t) => $t->dropColumn('equiboost_access'));
        Schema::table('protocols', fn (Blueprint $t) => $t->dropColumn('equiboost_started_at'));
        DB::statement('SELECT refresh_library_access()');
    }
};
