<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $patch = json_decode(file_get_contents(database_path('data/intake-complaint-physical-2026-09-30.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($patch): void {
            $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
            if (! $questionnaire) {
                return; // Fresh installs receive these definitions from the seeder.
            }

            $columns = ['showIf' => 'show_if', 'flagIf' => 'flag_if', 'sub' => 'repeater_sub'];
            $attributes = static function (array $values) use ($columns): array {
                $result = [];
                foreach ($values as $key => $value) {
                    if ($key !== 'id') {
                        $result[$columns[$key] ?? $key] = $value;
                    }
                }

                return $result;
            };
            foreach ($patch['sections'] as $key => $changes) {
                $section = $questionnaire->sections()->where('key', $key)->first();
                if (! $section) {
                    continue;
                }
                foreach ($changes['update'] as $field => $values) {
                    // Save through the model to retain JSON casts. Only requested attributes change.
                    $model = $section->fields()->where('key', $field)->first();
                    if (! $model) {
                        continue;
                    }
                    $changed = [];
                    foreach ($attributes($values) as $attribute => $value) {
                        $current = $model->getAttribute($attribute);
                        // PostgreSQL jsonb can reorder object keys; compare their values.
                        $equal = is_array($current) && is_array($value) ? $current == $value : $current === $value;
                        if (! $equal) {
                            $changed[$attribute] = $value;
                        }
                    }
                    if ($changed) {
                        $model->update($changed);
                    }
                }
                foreach ($changes['add'] as $addition) {
                    if ($section->fields()->where('key', $addition['field']['id'])->exists()) {
                        continue;
                    }
                    $previous = $section->fields()->where('key', $addition['after'])->first();
                    $order = $previous ? $previous->order + 1 : 0;
                    $section->fields()->where('order', '>=', $order)->increment('order');
                    $section->fields()->create(['key' => $addition['field']['id'], 'order' => $order, 'active' => true] + $attributes($addition['field']));
                }
                foreach ($changes['move_after'] ?? [] as $key => $after) {
                    $fields = $section->fields()->orderBy('order')->get();
                    $moving = $fields->firstWhere('key', $key);
                    if (! $moving || ! $fields->firstWhere('key', $after)) {
                        continue;
                    }
                    $ordered = [];
                    foreach ($fields as $field) {
                        if ($field->key !== $key) {
                            $ordered[] = $field;
                        }
                        if ($field->key === $after) {
                            $ordered[] = $moving;
                        }
                    }
                    foreach ($ordered as $order => $field) {
                        if ($field->order !== $order) {
                            $field->update(['order' => $order]);
                        }
                    }
                }
                // Keep stored answers and attachments; only retire their obsolete questions.
                $section->fields()->whereIn('key', $changes['retire'])->where('active', true)->update(['active' => false]);
            }
        });
    }

    public function down(): void
    {
        // Keep revised definitions and all customer answers on code rollback.
    }
};
