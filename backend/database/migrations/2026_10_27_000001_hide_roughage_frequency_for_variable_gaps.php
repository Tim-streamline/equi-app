<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
        foreach ($questionnaire?->sections ?? [] as $section) {
            foreach ($section->fields->where('key', 'hooi-zonder-ruwvoer-frequentie') as $field) {
                $conditions = $field->show_if ?? [];
                foreach (['hooi-zonder-ruwvoer-langste', 'voer.hooi-zonder-ruwvoer-langste'] as $key) {
                    if (! array_key_exists($key, $conditions)) continue;
                    $options = is_array($conditions[$key]) ? $conditions[$key] : [$conditions[$key]];
                    $conditions[$key] = array_values(array_filter($options, fn ($option) => mb_strtolower(trim($option)) !== 'wisselend'));
                }
                if ($conditions !== ($field->show_if ?? [])) $field->update(['show_if' => $conditions]);
            }
        }
    }

    public function down(): void
    {
        // Preserve saved answers and later administrator changes.
    }
};
