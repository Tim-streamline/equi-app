<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()?->sections()->where('key', 'paard')->first();
        $field = $section?->fields()->where('key', 'sinds-bezit')->first();
        if ($field) {
            $field->update(['date_config' => array_replace($field->date_config ?? [], ['allow_unknown' => false])]);
        }
    }

    public function down(): void
    {
        // Preserve existing answers and subsequent admin configuration changes.
    }
};
