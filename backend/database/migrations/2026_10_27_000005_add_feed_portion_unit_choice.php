<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $hint = 'Weet je het alleen in scheppen? Weeg één schep een keer af.';
        $unit = ['id' => 'eenheid', 'label' => 'Eenheid', 'type' => 'radio', 'options' => ['gram', 'kilo']];
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
        foreach ($q?->sections ?? [] as $section) {
            foreach ($section->fields->whereIn('key', ['huidige-bijvoeding', 'bijvoeding-historie']) as $field) {
                $sub = $field->repeater_sub ?? [];
                $hasUnit = collect($sub)->contains(fn ($column) => ($column['id'] ?? null) === 'eenheid');
                $next = [];
                foreach ($sub as $column) {
                    if (($column['id'] ?? null) !== 'hoeveelheid') {
                        $next[] = $column;
                        continue;
                    }
                    // The owner now picks gram or kilo per product instead of a fixed kg.
                    unset($column['unit']);
                    $next[] = [...$column, 'type' => 'number', 'unitField' => 'eenheid', 'hint' => ($column['hint'] ?? '') ?: $hint];
                    if (! $hasUnit) $next[] = $unit;
                }
                if ($next !== $sub) $field->update(['repeater_sub' => $next]);
            }
        }
    }

    public function down(): void
    {
        // Preserve chosen units, saved quantities and later administrator settings.
    }
};
