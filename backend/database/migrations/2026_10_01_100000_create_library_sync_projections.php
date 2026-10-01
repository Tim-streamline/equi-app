<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_item_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->string('reason', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at');
            $table->unique(['user_id', 'item_id']);
            $table->index('item_id');
        });
        Schema::create('library_contents', function (Blueprint $table) {
            $table->foreignUuid('id')->primary()->constrained('library_items')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->jsonb('chapters');
            $table->jsonb('attachments');
            $table->timestamp('updated_at');
        });
        DB::unprepared(file_get_contents(database_path('sql/library-sync.sql')));
        foreach (['users', 'library_items', 'library_unlocks', 'subscriptions', 'plans'] as $table) {
            // User profile and catalog text edits do not change authorization.
            $events = match ($table) {
                'users' => 'INSERT OR DELETE OR UPDATE OF disabled_at',
                'library_items' => 'INSERT OR DELETE OR UPDATE OF published_at, credit_cost, is_plus',
                default => 'INSERT OR UPDATE OR DELETE',
            };
            DB::unprepared("CREATE TRIGGER library_access_lock BEFORE {$events} ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION lock_library_access_sources()");
            DB::unprepared("CREATE TRIGGER library_access_refresh AFTER {$events} ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION refresh_library_access_trigger()");
        }
        foreach (['library_items', 'library_chapters', 'library_attachments'] as $table) {
            // Serialize projection writers to keep simultaneous body/chapter edits coherent.
            DB::unprepared("CREATE TRIGGER library_content_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION lock_library_access_sources()");
            DB::unprepared("CREATE TRIGGER library_content_refresh AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION refresh_library_content_trigger()");
        }
        DB::statement('SELECT refresh_library_content(id) FROM library_items');
        DB::statement('SELECT refresh_library_access()');
    }

    public function down(): void
    {
        foreach (['users', 'library_items', 'library_unlocks', 'subscriptions', 'plans'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS library_access_lock ON {$table}; DROP TRIGGER IF EXISTS library_access_refresh ON {$table}");
        }
        foreach (['library_items', 'library_chapters', 'library_attachments'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS library_content_lock ON {$table}; DROP TRIGGER IF EXISTS library_content_refresh ON {$table}");
        }
        DB::unprepared('DROP FUNCTION refresh_library_content_trigger(), refresh_library_content(uuid), refresh_library_access_trigger(), lock_library_access_sources(), refresh_library_access(timestamp)');
        Schema::dropIfExists('library_contents');
        Schema::dropIfExists('library_item_access');
    }
};
