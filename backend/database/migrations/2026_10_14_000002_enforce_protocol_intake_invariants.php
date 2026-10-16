<?php

use App\Support\ProtocolIntakeIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(fn () => $this->reconcileAndEnforce());
    }

    private function reconcileAndEnforce(): void
    {
        DB::statement('LOCK TABLE horses, protocols, intake_bookings, intake_answers, intake_attachments IN ACCESS EXCLUSIVE MODE');
        (require __DIR__.'/support/protocol-intake-backfill.php')->run();
        $issues = app(ProtocolIntakeIntegrity::class)->issues();
        if ($issues !== []) {
            throw new RuntimeException('Protocol/intake migration requires reviewed legacy repairs. Run php artisan protocols:audit-intakes. '.json_encode($issues));
        }
        DB::statement('ALTER TABLE intake_bookings ALTER COLUMN protocol_id SET NOT NULL');
        DB::statement('ALTER TABLE intake_bookings DROP CONSTRAINT intake_bookings_horse_id_foreign');
        DB::statement('ALTER TABLE intake_bookings ADD CONSTRAINT intake_bookings_horse_id_foreign FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE intake_bookings ALTER COLUMN horse_id SET NOT NULL');
        DB::statement('ALTER TABLE protocols ADD CONSTRAINT protocols_id_horse_unique UNIQUE (id, horse_id)');
        DB::statement('ALTER TABLE intake_bookings ADD CONSTRAINT intake_protocol_horse_fk FOREIGN KEY (protocol_id, horse_id) REFERENCES protocols (id, horse_id) ON DELETE CASCADE');
        DB::statement("CREATE UNIQUE INDEX protocols_one_active_per_horse ON protocols (horse_id) WHERE status = 'active'");
        // A unique required FK gives at most one intake. This deferred check also enforces at least one,
        // while allowing the two rows to be created/deleted together inside a transaction.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_protocol_has_intake() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid;
BEGIN
    IF TG_TABLE_NAME = 'protocols' THEN target := NEW.id;
    ELSE target := OLD.protocol_id;
    END IF;
    IF EXISTS (SELECT 1 FROM protocols WHERE id = target)
       AND NOT EXISTS (SELECT 1 FROM intake_bookings WHERE protocol_id = target) THEN
        RAISE EXCEPTION 'Protocol % must have exactly one intake', target USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER protocol_requires_intake AFTER INSERT OR UPDATE ON protocols
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_protocol_has_intake();
CREATE CONSTRAINT TRIGGER intake_preserves_protocol AFTER DELETE OR UPDATE ON intake_bookings
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_protocol_has_intake();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS intake_preserves_protocol ON intake_bookings; DROP TRIGGER IF EXISTS protocol_requires_intake ON protocols; DROP FUNCTION IF EXISTS check_protocol_has_intake()');
        DB::statement('DROP INDEX protocols_one_active_per_horse');
        DB::statement('ALTER TABLE intake_bookings DROP CONSTRAINT intake_protocol_horse_fk');
        DB::statement('ALTER TABLE protocols DROP CONSTRAINT protocols_id_horse_unique');
        DB::statement('ALTER TABLE intake_bookings ALTER COLUMN protocol_id DROP NOT NULL');
        DB::statement('ALTER TABLE intake_bookings ALTER COLUMN horse_id DROP NOT NULL');
        DB::statement('ALTER TABLE intake_bookings DROP CONSTRAINT intake_bookings_horse_id_foreign');
        DB::statement('ALTER TABLE intake_bookings ADD CONSTRAINT intake_bookings_horse_id_foreign FOREIGN KEY (horse_id) REFERENCES horses(id) ON DELETE SET NULL');
    }
};
