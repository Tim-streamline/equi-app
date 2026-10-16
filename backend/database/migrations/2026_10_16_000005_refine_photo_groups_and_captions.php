<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definition = json_decode(file_get_contents(__DIR__.'/support/intake-photos-opt132-revision.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($definition): void {
            $sections = DB::table('intake_sections')->where('key', 'intake-fotos')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                foreach ($definition['changes'] as $key => $changes) {
                    $query = DB::table('intake_fields')->where('section_id', $section->id)->where('key', $key);
                    $field = (clone $query)->first();
                    if (! $field) {
                        continue;
                    }
                    $update = ['updated_at' => now()];
                    foreach ($changes as $column => $value) {
                        if ($column === 'photoConfig') {
                            $config = json_decode($field->photo_config ?? '{}', true) ?? [];
                            // Captions augment existing settings; the grouped body
                            // question no longer has the old single-image limit.
                            if ($key === 'foto-zijaanzicht-links') {
                                unset($config['max']);
                            }
                            $update['photo_config'] = json_encode(array_replace($config, $value), JSON_THROW_ON_ERROR);
                        } else {
                            $update[$column] = $value;
                        }
                    }
                    $query->update($update);
                }
                DB::table('intake_fields')->where('section_id', $section->id)->whereIn('key', $definition['retired'])
                    ->update(['active' => false, 'updated_at' => now()]);
                // Source answer keys remain in the grouped slots. Keep every
                // answer, attachment and metadata row, including offline retries.
            }
        });
    }

    public function down(): void
    {
        // Preserve editable content and existing answers; restore a backup to roll back.
    }
};
