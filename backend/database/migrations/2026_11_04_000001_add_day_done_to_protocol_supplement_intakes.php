<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Released app versions show one checkbox per product per day. They receive
 * only the first-dose row of each product-day through PowerSync, with `day_done`
 * (every scheduled dose of that day done) as its checkmark. See LegacyProtocolClient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocol_supplement_intakes', fn (Blueprint $table) => $table->boolean('day_done')->default(false));
        Schema::create('protocol_intake_client_ids', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->foreignUuid('intake_id')->constrained('protocol_supplement_intakes')->cascadeOnDelete();
            $table->primary(['user_id', 'client_id']);
        });
        // An evening-only row must also have a first-dose row for the legacy projection.
        DB::statement(<<<'SQL'
            INSERT INTO protocol_supplement_intakes
                (protocol_phase_supplement_id, horse_id, date, dosage, administration, done, created_at, updated_at)
            SELECT DISTINCT ON (i.protocol_phase_supplement_id, i.date)
                i.protocol_phase_supplement_id, i.horse_id, i.date, i.dosage, 1, false, now(), now()
            FROM protocol_supplement_intakes i
            WHERE NOT EXISTS (SELECT 1 FROM protocol_supplement_intakes first_dose
                WHERE first_dose.protocol_phase_supplement_id = i.protocol_phase_supplement_id
                    AND first_dose.date = i.date AND first_dose.administration = 1)
        SQL);
        // Never infer a missing dose was taken. Historical snapshots retain their own
        // frequency; otherwise use the live plan, just like today's dashboard.
        DB::statement(<<<'SQL'
            WITH expected AS (
                SELECT DISTINCT i.protocol_phase_supplement_id, i.date,
                    GREATEST(1, COALESCE(NULLIF((
                        SELECT count(*) FROM protocol_day_snapshots snap,
                            json_array_elements(snap.items) entry
                        WHERE snap.protocol_id = phase.protocol_id AND snap.date = i.date
                            AND entry->>'id' = selection.id::text
                    ), 0), catalog.administrations_per_day, selection.administrations_per_day, 1)) AS doses
                FROM protocol_supplement_intakes i
                JOIN protocol_phase_supplements selection ON selection.id = i.protocol_phase_supplement_id
                JOIN protocol_phases phase ON phase.id = selection.protocol_phase_id
                LEFT JOIN supplements catalog ON catalog.id = selection.supplement_id
            ), grouped AS (
                SELECT expected.protocol_phase_supplement_id, expected.date,
                    count(*) FILTER (WHERE i.done AND i.administration BETWEEN 1 AND expected.doses) = expected.doses AS all_done
                FROM expected JOIN protocol_supplement_intakes i
                    ON i.protocol_phase_supplement_id = expected.protocol_phase_supplement_id AND i.date = expected.date
                GROUP BY expected.protocol_phase_supplement_id, expected.date, expected.doses
            )
            UPDATE protocol_supplement_intakes AS intake SET day_done = grouped.all_done
            FROM grouped
            WHERE grouped.protocol_phase_supplement_id = intake.protocol_phase_supplement_id
              AND grouped.date = intake.date
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_intake_client_ids');
        Schema::table('protocol_supplement_intakes', fn (Blueprint $table) => $table->dropColumn('day_done'));
    }
};
