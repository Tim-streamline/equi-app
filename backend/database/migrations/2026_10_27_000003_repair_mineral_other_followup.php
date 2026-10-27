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
                $showIf = [$source.'.'.$parent->key => $options];
                if ($field) {
                    // Repair only a rule that can never match the current options; keep the
                    // wording, requirement and other settings an administrator chose.
                    $rule = $field->show_if ?? [];
                    $current = (array) ($rule[$source.'.'.$parent->key] ?? $rule[$parent->key] ?? []);
                    if (! array_intersect($current, $parent->options ?? [])) $field->update(['show_if' => $showIf]);
                    continue;
                }
                $section->fields()->where('order', '>', $parent->order)->increment('order');
                $section->fields()->create(['key' => 'mineralen-toegang-anders', 'type' => 'text', 'order' => $parent->order + 1,
                    'label' => 'Licht je antwoord toe.', 'required' => true, 'optional' => false, 'active' => true, 'show_if' => $showIf]);
            }
        });
    }

    public function down(): void
    {
        // Preserve existing explanations and subsequent admin configuration.
    }
};
