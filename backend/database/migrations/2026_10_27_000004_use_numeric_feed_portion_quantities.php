<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
        foreach ($q?->sections ?? [] as $section) {
            foreach ($section->fields->whereIn('key', ['huidige-bijvoeding', 'bijvoeding-historie']) as $field) {
                $sub = $field->repeater_sub ?? [];
                foreach ($sub as &$column) {
                    if (($column['id'] ?? null) === 'hoeveelheid') {
                        $column['type'] = 'number';
                        $column['unit'] = 'kg';
                    }
                }
                unset($column);
                $field->update(['repeater_sub' => $sub]);
            }
        }
    }

    public function down(): void
    {
        // Preserve saved quantities and later administrator settings.
    }
};
