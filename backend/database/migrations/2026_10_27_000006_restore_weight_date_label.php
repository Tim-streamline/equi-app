<?php

use App\Models\IntakeField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Restore the preferred wording; leave any label an administrator chose afterwards.
        IntakeField::where('key', 'gewicht-methode-wanneer')->where('label', 'Wanneer was dat?')
            ->update(['label' => 'Wanneer is het gewicht bepaald?']);
    }

    public function down(): void
    {
        // Keep the current wording and administrator changes.
    }
};
