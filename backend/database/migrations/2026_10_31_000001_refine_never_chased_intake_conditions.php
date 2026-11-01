<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Databases that ran the first version of the 'Nooit' migration get its current conditions. */
    public function up(): void
    {
        (require __DIR__.'/2026_10_30_000004_add_never_chased_intake_option.php')->up();
    }

    public function down(): void
    {
        // Retain the conditions for saved responses.
    }
};
