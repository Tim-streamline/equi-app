<?php

use App\Models\IntakeField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        IntakeField::where('key', 'andere-diersoorten-contact')
            ->whereHas('section', fn ($query) => $query->where('key', 'sociaal'))
            ->get()->each(function (IntakeField $field) {
                // Preserve visibility conditions customized in the administration.
                if (! empty($field->show_if)) {
                    return;
                }

                $field->update(['show_if' => ['andere-diersoorten' => 'any-checked']]);
            });
    }

    public function down(): void
    {
        // Keep the repaired condition and subsequent administrator changes.
    }
};
