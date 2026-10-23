<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->first()?->sections()->where('key', 'voer')->first();
            if (! $section) return;
            $oldKeys = ['hooi-pauze-incl-nacht', 'hooi-pauze-uren', 'hooi-pauze-langste'];
            $old = $section->fields()->whereIn('key', $oldKeys)->orderBy('order')->first();
            if (! $old) return;
            $fields = json_decode(file_get_contents(database_path('data/roughage-gaps-2026-10-21.json')), true, flags: JSON_THROW_ON_ERROR);
            $mainKey = $fields[0]['id'];
            if (! $section->fields()->where('key', $mainKey)->exists()) {
                $section->fields()->where('order', '>=', $old->order)->increment('order', count($fields));
                foreach ($fields as $i => $field) {
                    $section->fields()->create(['key' => $field['id'], 'order' => $old->order + $i,
                        'label' => $field['label'], 'type' => $field['type'], 'lines' => $field['lines'] ?? null,
                        'options' => $field['options'] ?? null, 'show_if' => $field['showIf'] ?? null,
                        'flag_if' => $field['flagIf'] ?? null, 'required' => true, 'optional' => false, 'active' => true]);
                }
            }
            $section->fields()->whereIn('key', $oldKeys)->update(['active' => false]);
            // Preserve every original answer. Never infer frequency from 'sometimes' or 'regularly'.
            $bookings = DB::table('intake_answers')->where('section_id', 'voer')->whereIn('field_id', $oldKeys)->distinct()->pluck('response_id');
            foreach ($bookings as $bookingId) {
                if (DB::table('intake_answers')->where('response_id', $bookingId)->where('section_id', 'voer')->where('field_id', $mainKey)->exists()) continue;
                $answers = DB::table('intake_answers')->where('response_id', $bookingId)->where('section_id', 'voer')->whereIn('field_id', $oldKeys)->get()->keyBy('field_id');
                $presence = json_decode($answers->get('hooi-pauze-incl-nacht')?->value ?? 'null', true);
                $hours = json_decode($answers->get('hooi-pauze-langste')?->value ?? 'null', true);
                $choice = null;
                if ((is_int($hours) || is_float($hours) || (is_string($hours) && is_numeric($hours))) && is_finite((float) $hours) && (float) $hours >= 0) {
                    $n = (float) $hours;
                    // Contradictory legacy answers require the owner to check the new question.
                    if (!(($presence === 'Nee, nooit' && $n > 0) || (in_array($presence, ['Ja, soms', 'Ja, regelmatig'], true) && $n === 0.0))) {
                        $choice = $n === 0.0 ? $fields[0]['options'][0] : ($n < 2 ? 'Minder dan 2 uur' : ($n <= 4 ? '2 tot 4 uur' : ($n <= 6 ? '4 tot 6 uur' : 'Meer dan 6 uur')));
                    }
                } elseif ($presence === 'Nee, nooit' && ($hours === null || $hours === '')) {
                    $choice = $fields[0]['options'][0];
                }
                if ($choice === null) continue;
                $date = $answers->get('hooi-pauze-langste')?->updated_at ?? $answers->first()->updated_at;
                DB::table('intake_answers')->insert(['id' => (string) Illuminate\Support\Str::uuid(), 'response_id' => $bookingId,
                    'section_id' => 'voer', 'field_id' => $mainKey, 'value' => json_encode($choice, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $date, 'updated_at' => $date]);
            }
        });
    }

    public function down(): void
    {
        // Retain original and merged answers, including later owner edits.
    }
};
