<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_fields', 'submission_blocks')) {
            Schema::table('intake_fields', fn (Blueprint $t) => $t->json('submission_blocks')->nullable());
        }
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->with('sections.fields')->first();
        $config = $q?->safety_config;
        if (! $config) return;
        $section = $q->sections->firstWhere('key', $config['section']);
        if (! $section) throw new RuntimeException('Cannot migrate safety rules: the configured section is missing.');
        $fields = $section->fields->keyBy('key');
        foreach ($config['triggers'] as $trigger) {
            // The last condition is the specific answer; retain any other
            // conditions so conversion never broadens an existing trigger.
            $when = $trigger['when'];
            $key = array_key_last($when);
            $field = $fields->get($key);
            if (! $field) throw new RuntimeException('Cannot migrate safety rules: a trigger question is missing.');
            $answers = $when[$key];
            unset($when[$key]);
            $notice = $fields->get($trigger['notice']);
            $message = trim($notice?->hint ?? '') ?: trim($notice?->label ?? '') ?: 'Je kunt de intake met dit antwoord nog niet versturen. Neem contact op met je behandelaar.';
            $rules = $field->submission_blocks ?? [];
            foreach ($answers as $answer) {
                $rule = ['answer' => $answer, 'message' => $message];
                if ($when) $rule['when'] = $when;
                if (! in_array($rule, $rules, true)) $rules[] = $rule;
            }
            $field->update(['submission_blocks' => $rules]);
        }
        // Retire only workflow-specific questions, not ordinary medical questions.
        $retired = [$config['recovery']['intro'], ...array_keys($config['recovery']['require'])];
        foreach ($retired as $key) {
            $field = $fields->get($key);
            if ($field && isset(($field->show_if ?? [])[$config['state_field']])) $field->update(['active' => false]);
        }
        $q->update(['safety_config' => null]);
        // Keep all answers, attachments and historical report/release evidence.
        // No historical report is treated as an active recovery workflow anymore.
    }

    public function down(): void
    {
        // Preserve configured messages and historical evidence.
    }
};
