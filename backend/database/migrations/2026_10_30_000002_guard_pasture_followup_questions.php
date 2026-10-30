<?php

use App\Models\IntakeField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // A saved "Anders" answer must not keep a followup applicable once
        // the owner changes the main answer to no pasture access.
        $conditions = [
            'weide-omschrijving-anders' => ['weide-omschrijving' => 'Anders, namelijk'],
            'weide-bodem-anders' => ['weide-bodem' => 'Anders, namelijk'],
            'weide-schuil-soort' => ['weide-schuil' => ['Ja, altijd', 'Meestal wel', 'Beperkt', 'Wisselend per seizoen']],
        ];

        foreach ($conditions as $key => $original) {
            IntakeField::where('key', $key)->whereHas('section', fn ($query) => $query->where('key', 'huisvesting'))
                ->get()->each(function (IntakeField $field) use ($original) {
                    // Preserve conditions that an administrator has customized.
                    if ($field->show_if !== $original) {
                        return;
                    }

                    $field->update(['show_if' => [
                        'komt-op-weide' => ['Ja, seizoensgebonden', 'Ja, het hele jaar door', 'Anders, namelijk'],
                        ...$original,
                    ]]);
                });
        }
    }

    public function down(): void
    {
        // Keep the repaired conditions and any subsequent administrator changes.
    }
};
