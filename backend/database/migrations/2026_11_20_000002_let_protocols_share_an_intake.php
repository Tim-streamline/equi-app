<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several protocols can use one intake. Every protocol names the intake it uses in intake_booking_id: its own
 * (the booking whose protocol_id is this protocol) or, for a copy, the intake of the protocol it was copied from.
 * intake_bookings.protocol_id stays the one protocol an intake was created for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', fn (Blueprint $table) => $table->uuid('intake_booking_id')->nullable()->index());
        // No cascade: the database refuses to remove an intake that a protocol still uses. Deleting a protocol still
        // removes its own intake in the same statement (intake_bookings.protocol_id cascades).
        DB::statement('ALTER TABLE protocols ADD CONSTRAINT protocols_intake_booking_id_foreign FOREIGN KEY (intake_booking_id) REFERENCES intake_bookings (id)');
        // After every ALTER TABLE: this update queues the deferred protocol check for each existing protocol, and a
        // table with pending trigger events cannot be altered.
        DB::statement('UPDATE protocols SET intake_booking_id = i.id FROM intake_bookings i WHERE i.protocol_id = protocols.id');
        // Deferred as before: a protocol and its own intake are created together inside one transaction.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_protocol_has_intake() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target uuid;
BEGIN
    IF TG_TABLE_NAME = 'protocols' THEN target := NEW.id;
    ELSIF TG_OP = 'DELETE' THEN target := OLD.protocol_id;
    ELSE target := NEW.protocol_id;
    END IF;
    -- Every protocol uses an intake, and the protocol an intake was created for uses that one.
    IF EXISTS (SELECT 1 FROM protocols p WHERE p.id = target AND (p.intake_booking_id IS NULL
            OR EXISTS (SELECT 1 FROM intake_bookings b WHERE b.protocol_id = p.id AND b.id <> p.intake_booking_id))) THEN
        RAISE EXCEPTION 'Protocol % must use an intake, and its own intake when it has one', target USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
SQL);
    }

    public function down(): void
    {
        // Copies share an intake and cannot satisfy the old one-intake-per-protocol rule.
        if (DB::table('protocols')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('intake_bookings')->whereColumn('protocol_id', 'protocols.id'))->exists()) {
            throw new RuntimeException('Delete the protocols that share another protocol\'s intake before rolling back.');
        }
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
SQL);
        Schema::table('protocols', function (Blueprint $table) {
            $table->dropForeign('protocols_intake_booking_id_foreign');
            $table->dropColumn('intake_booking_id');
        });
    }
};
