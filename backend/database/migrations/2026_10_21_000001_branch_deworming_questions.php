<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dewormed = [
            'Op vaste momenten, volgens een standaardschema',
            'Op basis van mestonderzoek',
            'Een combinatie van beide',
        ];
        $sectionIds = DB::table('intake_sections')->where('key', 'medisch')
            ->whereIn('questionnaire_id', DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->select('id'))
            ->pluck('id');

        DB::transaction(function () use ($sectionIds, $dewormed): void {
            DB::table('intake_fields')->whereIn('section_id', $sectionIds)->where('key', 'ontworming-methode')
                ->update([
                    'label' => 'Hoe wordt bepaald óf en wanneer je paard ontworming nodig heeft?',
                    'options' => json_encode([...$dewormed, 'Mijn paard wordt niet ontwormd', 'Weet ik niet'], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

            foreach (['ontworming-laatst', 'ontworming-middel', 'ontworming-volgende', 'ontworming-reactie'] as $key) {
                DB::table('intake_fields')->whereIn('section_id', $sectionIds)->where('key', $key)
                    ->update(['show_if' => json_encode(['ontworming-methode' => $dewormed], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            foreach (['ontworming-volgende-detail' => 'ontworming-volgende', 'ontworming-reactie-toelichting' => 'ontworming-reactie'] as $key => $parent) {
                $answer = $parent === 'ontworming-volgende' ? 'ja' : 'Ja, anders, namelijk';
                DB::table('intake_fields')->whereIn('section_id', $sectionIds)->where('key', $key)
                    ->update(['show_if' => json_encode(['ontworming-methode' => $dewormed, $parent => $answer], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }

            // Keep existing answers selected after rewording the two stored option values.
            $renamed = [
                'Op vaste momenten / standaard schema' => $dewormed[0],
                'Combinatie van beide' => $dewormed[2],
            ];
            DB::table('intake_answers')->where('section_id', 'medisch')->where('field_id', 'ontworming-methode')
                ->orderBy('id')->chunk(100, function ($answers) use ($renamed): void {
                    foreach ($answers as $answer) {
                        $old = json_decode($answer->value ?? 'null', true);
                        if (is_string($old) && isset($renamed[$old])) {
                            DB::table('intake_answers')->where('id', $answer->id)->update([
                                'value' => json_encode($renamed[$old], JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        // Preserve answers and any later admin edits.
    }
};
