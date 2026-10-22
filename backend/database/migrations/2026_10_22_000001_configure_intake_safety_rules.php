<?php

use App\Support\IntakeSafetySettings;
use App\Support\LaminitisSafety;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_questionnaires', 'safety_config')) {
            Schema::table('intake_questionnaires', fn (Blueprint $t) => $t->json('safety_config')->nullable());
        }
        if (! Schema::hasColumn('intake_fields', 'answer_aliases')) {
            Schema::table('intake_fields', fn (Blueprint $t) => $t->json('answer_aliases')->nullable());
        }
        DB::transaction(function (): void {
            $q = DB::table('intake_questionnaires')->where('slug', 'protocol-intake')->lockForUpdate()->first();
            if (! $q) {
                return;
            }
            $config = json_decode(file_get_contents(database_path('data/intake-safety-2026-10-22.json')), true, flags: JSON_THROW_ON_ERROR);
            $sections = DB::table('intake_sections')->where('questionnaire_id', $q->id)->pluck('id');
            $fields = DB::table('intake_fields')->whereIn('section_id', $sections)->get()->keyBy('key');
            // Preserve locally configured acute and past answer values when moving the existing rules.
            $acute = json_decode($fields->get('hoefbevangenheid')?->critical_if ?? 'null', true);
            $past = json_decode($fields->get('hoefbevangenheid-hersteld')?->show_if ?? 'null', true)['hoefbevangenheid'] ?? [];
            $config['triggers'][0]['when']['hoefbevangenheid'] = array_values(array_unique([...$config['triggers'][0]['when']['hoefbevangenheid'], ...(is_array($acute) ? $acute : ($acute ? [$acute] : []))]));
            $past = array_values(array_unique([...$config['triggers'][1]['when']['hoefbevangenheid'], ...(is_array($past) ? $past : [$past])]));
            $config['triggers'][1]['when']['hoefbevangenheid'] = $past;
            $config['recovery']['conditional'][0]['when']['hoefbevangenheid'] = $past;
            if ($q->safety_config === null) {
                DB::table('intake_questionnaires')->where('id', $q->id)->update(['safety_config' => json_encode($config)]);
            } else {
                $config = json_decode($q->safety_config, true, flags: JSON_THROW_ON_ERROR);
            }
            DB::table('intake_fields')->whereIn('section_id', $sections)->where('key', 'ontworming-methode')->whereNull('answer_aliases')->update(['answer_aliases' => json_encode([
                'Op vaste momenten / standaard schema' => 'Op vaste momenten, volgens een standaardschema',
                'Combinatie van beide' => 'Een combinatie van beide',
            ])]);
            // Add only the rule snapshot. Keep episode IDs, release state, history and activity timestamps.
            DB::table('horses')->whereNotNull('laminitis_safety')->orderBy('id')->lockForUpdate()->chunkById(200, function ($horses) use ($config): void {
                foreach ($horses as $horse) {
                    $state = json_decode($horse->laminitis_safety, true, flags: JSON_THROW_ON_ERROR);
                    if (! empty($state['episode_id']) && ! isset($state['configuration'])) {
                        $state['configuration'] = $config;
                        $state['recovery_fields'] = app(LaminitisSafety::class)->recoveryFields($config);
                        $answers = DB::table('intake_answers')->where('response_id', $state['booking_id'] ?? null)->where('section_id', $config['section'])->get()->mapWithKeys(fn ($a) => [$a->field_id => json_decode($a->value, true)])->all();
                        $state['requirements'] = app(IntakeSafetySettings::class)->requirements($config, $answers);
                        DB::table('horses')->where('id', $horse->id)->update(['laminitis_safety' => json_encode($state)]);
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // Never discard workflow configuration needed to release a saved safety hold.
    }
};
