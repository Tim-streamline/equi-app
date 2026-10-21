<?php

use App\Models\IntakeQuestionnaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $questionnaire = IntakeQuestionnaire::where('slug', 'protocol-intake')->first();
            if (! $questionnaire) {
                return;
            }

            $seed = json_decode(file_get_contents(database_path('data/intake-questionnaire.json')), true, 512, JSON_THROW_ON_ERROR);
            $sectionData = collect($seed['sections'])->keyBy('id');
            $medical = $questionnaire->sections()->where('key', 'medisch')->first();
            if ($medical && $medical->fields()->where('key', 'ir-status')->exists()) {
                $wanted = collect($sectionData['medisch']['fields'])->keyBy('id');
                $before = $medical->fields()->where('key', 'ir-onderzoek-wanneer-welk')->first();
                foreach (['ir-onderzoeken', 'ir-onderzoeken-anders', 'ir-voeding-tussenkop',
                    'ir-voeding-vooraf', 'ir-voeding-vooraf-anders', 'ir-hooi-hoeveelheid',
                    'ir-laatst-gegeten', 'ir-vasten-duur'] as $key) {
                    if ($medical->fields()->where('key', $key)->exists()) {
                        continue;
                    }
                    $attributes = $this->attributes($wanted[$key]);
                    $order = $before?->order ?? $medical->fields()->max('order') + 1;
                    $medical->fields()->where('order', '>=', $order)->increment('order');
                    $medical->fields()->create(['key' => $key, 'order' => $order, 'active' => true] + $attributes);
                    $before?->refresh();
                }
                foreach (['ir-onderzoek-wanneer-welk', 'ir-onderzoek-uitslag', 'ir-uitslag-beschikbaar', 'ir-attest'] as $key) {
                    $field = $medical->fields()->where('key', $key)->first();
                    if ($field) {
                        $field->update($this->attributes($wanted[$key]));
                    }
                }
            }

            $history = $questionnaire->sections()->where('key', 'geschiedenis')->first();
            $history?->fields()->where('key', 'medische-gebeurtenissen')->first()?->update(['options' => ['Niet van toepassing']]);
            $questionnaire->update(['none_options' => array_values(array_unique(array_merge(
                $questionnaire->none_options ?? [], ['weet ik niet']
            )))]);
        });
    }

    private function attributes(array $field): array
    {
        $map = ['label' => 'label', 'type' => 'type', 'hint' => 'hint', 'required' => 'required',
            'optional' => 'optional', 'unit' => 'unit', 'placeholder' => 'placeholder',
            'options' => 'options', 'showIf' => 'show_if'];
        $result = [];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $field)) {
                $result[$target] = $field[$source];
            }
        }
        if ($field['id'] === 'ir-attest') {
            $result['optional'] = false;
        }

        return $result;
    }

    public function down(): void
    {
        // Existing responses and later admin changes stay intact.
    }
};
