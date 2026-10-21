<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horses', fn (Blueprint $table) => $table->json('laminitis_safety')->nullable());
        DB::transaction(function (): void {
            $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()?->sections()->where('key', 'medisch')->first();
            if (! $section) {
                return;
            }
            $definitions = json_decode(file_get_contents(database_path('data/laminitis-2026-10-21.json')), true, flags: JSON_THROW_ON_ERROR);
            $old = $section->fields()->where('key', 'hoefbevangenheid')->first();
            $order = $old?->order ?? 100;
            $section->fields()->where('order', '>', $order)->increment('order', count($definitions));
            $section->fields()->where('key', 'like', 'hoefbevangenheid-%')->update(['active' => false]);
            foreach ($definitions as $i => $field) {
                $section->fields()->updateOrCreate(['key' => $field['id']], [
                    'order' => $order + $i, 'label' => $field['label'], 'type' => $field['type'],
                    'hint' => $field['hint'] ?? null, 'placeholder' => $field['placeholder'] ?? null,
                    'options' => $field['options'] ?? null, 'show_if' => $field['showIf'] ?? null,
                    'flag_if' => $field['flagIf'] ?? null, 'critical_if' => $field['criticalIf'] ?? null,
                    'lines' => $field['lines'] ?? null, 'optional' => false, 'required' => true, 'active' => true,
                ]);
            }
            // Initialize a conservative hold before translating legacy status labels below.
            $answers = DB::table('intake_answers')->where('section_id', 'medisch')->where('field_id', 'hoefbevangenheid')->get();
            foreach ($answers as $answer) {
                $value = json_decode($answer->value ?? 'null', true);
                $unresolved = DB::table('intake_answers')->where('response_id', $answer->response_id)->where('section_id', 'medisch')
                    ->where('field_id', 'hoefbevangenheid-hersteld')->value('value');
                if (! in_array($value, App\Support\LaminitisSafety::ACUTE, true) && ! (in_array($value, App\Support\LaminitisSafety::PAST, true) && in_array(json_decode($unresolved ?? 'null'), ['Nee', 'Weet ik niet'], true))) {
                    continue;
                }
                $horseId = DB::table('intake_bookings')->where('id', $answer->response_id)->value('horse_id');
                DB::table('horses')->where('id', $horseId)->whereNull('laminitis_safety')->update(['laminitis_safety' => json_encode([
                    'episode_id' => (string) Illuminate\Support\Str::uuid(), 'kind' => in_array($value, App\Support\LaminitisSafety::ACUTE, true) ? 'acute' : 'unresolved',
                    'reported_at' => $answer->updated_at, 'booking_id' => $answer->response_id, 'source' => 'owner_reported', 'history' => [],
                ])]);
            }
            $labels = ['nu acuut' => 'Ja, mijn paard is momenteel hoefbevangen', 'in verleden gehad' => 'Ja, mijn paard is in het verleden hoefbevangen geweest',
                'risico / vermoeden' => 'Mijn paard heeft een verhoogd risico op hoefbevangenheid', 'nooit' => 'Nee, voor zover bekend niet'];
            foreach ($answers as $answer) {
                $value = json_decode($answer->value ?? 'null', true);
                if (is_string($value) && isset($labels[$value])) {
                    DB::table('intake_answers')->where('id', $answer->id)->update(['value' => json_encode($labels[$value])]);
                }
            }
        });
    }

    public function down(): void
    {
        // Safety history and original answers intentionally survive rollback.
    }
};
