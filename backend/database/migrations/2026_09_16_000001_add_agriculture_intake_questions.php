<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $payload = json_decode(file_get_contents(database_path('data/intake-questionnaire.json')), true, flags: JSON_THROW_ON_ERROR);
        $housing = collect($payload['sections'])->firstWhere('id', 'huisvesting');
        $newFields = collect($housing['fields'])->filter(fn (array $field): bool => $field['id'] === 'sec-landbouw' || str_starts_with($field['id'], 'landbouw-') || $field['id'] === 'omgeving-overig'
        );

        DB::transaction(function () use ($newFields): void {
            $questionnaire = IntakeQuestionnaire::query()->where('slug', 'protocol-intake')->first();
            $section = $questionnaire?->sections()->where('key', 'huisvesting')->first();

            // Fresh installations receive the full definition through the normal seeder.
            if (! $section) {
                return;
            }

            $order = (int) $section->fields()->max('order') + 1;
            foreach ($newFields as $field) {
                // Preserve existing admin edits, archived fields and all stored answers.
                $section->fields()->firstOrCreate(['key' => $field['id']], [
                    'order' => $order++,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'hint' => $field['hint'] ?? null,
                    'required' => $field['required'] ?? false,
                    'optional' => $field['optional'] ?? false,
                    'options' => $field['options'] ?? null,
                    'show_if' => $field['showIf'] ?? null,
                    'active' => true,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep question definitions and customer answers when rolling back code.
    }
};
