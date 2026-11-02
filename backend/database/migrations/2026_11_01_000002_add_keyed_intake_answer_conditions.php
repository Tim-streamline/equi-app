<?php

use App\Models\IntakeQuestionnaire;
use App\Support\IntakeAnswerConditions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->jsonb('option_keys')->nullable();
            $table->jsonb('visibility_rule')->nullable();
        });
        $conditions = new IntakeAnswerConditions;
        foreach (IntakeQuestionnaire::with('sections.fields')->get() as $q) {
            foreach ($q->sections as $section) {
                foreach ($section->fields as $field) {
                    $keys = $conditions->initialKeys($field->options ?? [], $q->slug.'/'.($field->answer_section_key ?? $section->key).'/'.$field->key);
                    DB::table('intake_fields')->where('id', $field->id)->update(['option_keys' => json_encode($keys, JSON_THROW_ON_ERROR)]);
                    $field->option_keys = $keys;
                }
            }
            $catalog = $conditions->catalog($q->sections);
            $references = $conditions->references($catalog);
            foreach ($q->sections as $section) {
                foreach ($section->fields as $field) {
                    if ($field->show_if === null) {
                        continue;
                    }
                    $rule = $conditions->migrate($field->show_if, $section->key, $catalog, $q->none_options ?? []);
                    DB::table('intake_fields')->where('id', $field->id)->update([
                        'visibility_rule' => json_encode($rule, JSON_THROW_ON_ERROR),
                        // Old clients receive an equivalent rule using explicit choices too.
                        'show_if' => json_encode($conditions->compile($rule, $references), JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // show_if remains a valid, explicit rule for clients without answer-key support.
        Schema::table('intake_fields', function (Blueprint $table) {
            $table->dropColumn(['option_keys', 'visibility_rule']);
        });
    }
};
