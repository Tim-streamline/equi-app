<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $sections = DB::table('intake_sections')->where('key', 'intake-fotos')
                ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))->get();
            foreach ($sections as $section) {
                $fields = DB::table('intake_fields')->where('section_id', $section->id)
                    ->whereIn('key', ['sec-hoef-upload', 'foto-hoef-lv', 'foto-hoef-rv', 'foto-hoef-la', 'foto-hoef-ra'])->get()->keyBy('key');
                $heading = $fields->get('sec-hoef-upload');
                if (! $heading || $heading->type === 'photo') {
                    continue;
                }
                $groups = [];
                $slots = [];
                foreach (['foto-hoef-lv', 'foto-hoef-rv', 'foto-hoef-la', 'foto-hoef-ra'] as $key) {
                    $hoof = $fields->get($key);
                    if (! $hoof) {
                        continue 2;
                    }
                    $original = json_decode($hoof->photo_config ?? '{}', true) ?? [];
                    $views = array_map(fn ($slot) => ['id' => $slot['id'], 'label' => $slot['label']], $original['slots'] ?? []);
                    $groups[] = ['field' => $key, 'label' => $hoof->label, 'slots' => $views];
                    foreach ($views as $view) {
                        $slots[] = ['id' => $key.'-'.$view['id'], 'label' => $hoof->label.' — '.$view['label'],
                            'answer_field' => $key, 'answer_slot' => $view['id']];
                    }
                }
                $config = json_decode($heading->photo_config ?? '{}', true) ?? [];
                $config['allow_na'] = true;
                $config['na_key'] = 'extra-hoeven-na';
                $config['legacy_field'] = 'foto-hoef-instructie';
                $config['grouped_hooves'] = $groups;
                $config['slots'] = $slots;
                DB::table('intake_fields')->where('id', $heading->id)->update([
                    'type' => 'photo', 'answer_section_key' => 'fysiek', 'optional' => false, 'required' => true,
                    'photo_config' => json_encode($config, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                DB::table('intake_fields')->where('section_id', $section->id)
                    ->whereIn('key', ['foto-hoef-lv', 'foto-hoef-rv', 'foto-hoef-la', 'foto-hoef-ra', 'foto-hoef-instructie'])
                    ->update(['active' => false, 'updated_at' => now()]);
                // Saved answers, attachments and per-photo view metadata retain their original keys.
            }
        });
    }

    public function down(): void
    {
        // Preserve existing intake answers and administrator edits.
    }
};
