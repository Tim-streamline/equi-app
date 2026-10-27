<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
            foreach ($q?->sections ?? [] as $section) {
                $parent = $section->fields->firstWhere('key', 'mineralen-toegang');
                if (! $parent) continue;
                $field = $section->fields->firstWhere('key', 'mineralen-toegang-anders');
                $options = array_values(array_filter($parent->options ?? [], fn ($option) => preg_match('/^anders(?:$|[,\s.])/iu', trim($option))));
                $options = array_values(array_unique(['anders', 'Anders', 'anders, namelijk', 'Anders, namelijk', ...$options]));
                $source = $parent->answer_section_key ?? $section->key;
                if (! $field) {
                    $section->fields()->where('order', '>', $parent->order)->increment('order');
                    $field = $section->fields()->make(['key' => 'mineralen-toegang-anders', 'type' => 'text', 'order' => $parent->order + 1]);
                }
                $field->fill(['label' => 'Licht je antwoord toe.', 'required' => true, 'optional' => false, 'active' => true,
                    'show_if' => [$source.'.'.$parent->key => $options]])->save();
            }
        });
    }

    public function down(): void
    {
        // Preserve existing explanations and subsequent admin configuration.
    }
};
