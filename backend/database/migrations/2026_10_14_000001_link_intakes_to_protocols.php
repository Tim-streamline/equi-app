<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Draft protocols exist from the start of an intake, before a therapist chooses a template.
        DB::statement('ALTER TABLE protocols ALTER COLUMN protocol_template_id DROP NOT NULL');
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->foreignUuid('protocol_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });

        // Data reconciliation belongs to the enforcement transaction. If it fails,
        // this additive schema remains usable by the previous application release.
    }

    public function down(): void
    {
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('protocol_id'));
        // Keep nullable templates: the intake drafts and their history must survive a rollback.
    }
};
