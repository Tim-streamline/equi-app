<?php

use App\Models\IntakeAnswer;
use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intake_fields', 'signal_config')) {
            Schema::table('intake_fields', fn (Blueprint $table) => $table->json('signal_config')->nullable());
        }

        DB::transaction(function (): void {
            $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
            $section = $questionnaire?->sections()->where('key', 'medisch')->first();
            if (! $section) {
                return;
            }
            $suspected = ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf'];
            $positive = ['aangetoond uit urine onderzoek', 'Aangetoond met urineonderzoek', 'Ja, positieve uitslag bij urineonderzoek'];
            $placeholder = 'Beschrijf kort welke klachten, kenmerken of eerdere onderzoeken aanleiding geven tot je vermoeden.';
            $signals = [
                'vetkam' => 'Een vetkam genoemd door de eigenaar',
                'moeite_met_afvallen' => 'Moeite met afvallen genoemd door de eigenaar',
                'hoefbevangenheid_verleden' => 'Een eerdere episode van hoefbevangenheid genoemd door de eigenaar',
                'overgewicht' => 'Overgewicht genoemd door de eigenaar',
                'plaatselijke_vetophopingen' => 'Plaatselijke vetophopingen genoemd door de eigenaar',
                'eerdere_onderzoeken' => 'Eerdere onderzoeken genoemd door de eigenaar',
            ];
            $addAfter = static function (string $after, string $key, array $attributes) use ($section): void {
                if ($section->fields()->where('key', $key)->exists()) {
                    return;
                }
                $previous = $section->fields()->where('key', $after)->first();
                if (! $previous) {
                    return;
                }
                $order = $previous->order + 1;
                $section->fields()->where('order', '>=', $order)->increment('order');
                $section->fields()->create(['key' => $key, 'order' => $order, 'active' => true] + $attributes);
            };
            $addAfter('ems-diagnose-basis-anders', 'ems-vermoeden-toelichting', [
                'label' => 'Leg kort uit waarom je dit vermoedt.', 'type' => 'text', 'required' => true,
                'show_if' => ['ems-status' => $suspected],
            ]);
            foreach (['ir' => 'Leg kort uit waarom je vermoedt dat je paard IR heeft.',
                'ems' => 'Leg kort uit waarom je dit vermoedt.',
                'kpu' => 'Waarom vermoed je dat je paard KPU heeft?'] as $condition => $label) {
                $field = $section->fields()->where('key', $condition.'-vermoeden-toelichting')->first();
                $field?->update([
                    'label' => $label, 'type' => 'text', 'required' => true, 'optional' => false,
                    'placeholder' => $placeholder, 'show_if' => [$condition.'-status' => $suspected],
                    'signal_config' => ['condition' => strtoupper($condition), 'signals' => $signals],
                    'protocol_if' => null,
                ]);
            }
            $section->fields()->whereIn('key', ['ems-vermoeden-redenen', 'ems-vermoeden-anders'])->update(['active' => false]);

            // Preserve the original selections, while offering their literal text in the replacement field.
            IntakeAnswer::where('section_id', 'medisch')->where('field_id', 'ems-vermoeden-redenen')
                ->orderBy('id')->chunk(100, function ($answers): void {
                    foreach ($answers as $answer) {
                        if (IntakeAnswer::where('response_id', $answer->response_id)->where('section_id', 'medisch')
                            ->where('field_id', 'ems-vermoeden-toelichting')->exists()) {
                            continue;
                        }
                        $value = json_decode($answer->value ?? 'null', true);
                        $parts = is_array($value) ? array_values(array_filter($value, 'is_string')) : (is_string($value) ? [$value] : []);
                        $other = IntakeAnswer::where('response_id', $answer->response_id)->where('section_id', 'medisch')
                            ->where('field_id', 'ems-vermoeden-anders')->first();
                        $detail = json_decode($other?->value ?? 'null', true);
                        if (in_array('Anders, namelijk', $parts, true) && is_string($detail) && trim($detail) !== '') {
                            $parts[] = $detail;
                        }
                        if ($parts) {
                            // Data backfills must not invoke runtime observers whose schema comes later.
                            IntakeAnswer::withoutEvents(fn () => IntakeAnswer::create(['response_id' => $answer->response_id, 'section_id' => 'medisch',
                                'field_id' => 'ems-vermoeden-toelichting', 'value' => json_encode(implode("\n", $parts), JSON_THROW_ON_ERROR)]));
                        }
                    }
                });

            $addAfter('kpu-onderzoek-uitslag', 'kpu-uitslag-beschikbaar', [
                'label' => 'Heb je de uitslag beschikbaar?', 'type' => 'radio', 'required' => true,
                'options' => ['Ja', 'Nee'], 'show_if' => ['kpu-status' => $positive],
            ]);
            $section->fields()->where('key', 'kpu-attest')->first()?->update([
                'label' => 'Upload de uitslag van het onderzoek', 'required' => true, 'optional' => false,
                'hint' => 'Upload minimaal één PDF of afbeelding.',
                'show_if' => ['kpu-status' => $positive, 'kpu-uitslag-beschikbaar' => 'Ja'],
            ]);
            // Reuse existing uploads without making the owner reselect availability.
            IntakeAnswer::where('section_id', 'medisch')->where('field_id', 'kpu-attest')
                ->orderBy('id')->chunk(100, function ($answers): void {
                    foreach ($answers as $answer) {
                        $refs = json_decode($answer->value ?? 'null', true);
                        if (! is_array($refs) || ! array_filter($refs, fn ($ref) => is_string($ref) && str_starts_with($ref, 'attachment:'))) {
                            continue;
                        }
                        IntakeAnswer::withoutEvents(fn () => IntakeAnswer::firstOrCreate(['response_id' => $answer->response_id, 'section_id' => 'medisch',
                            'field_id' => 'kpu-uitslag-beschikbaar'], ['value' => json_encode('Ja')]));
                    }
                });
        });
    }

    public function down(): void
    {
        // Keep saved responses, provenance settings and subsequent admin edits.
    }
};
