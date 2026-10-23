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
            $section = $questionnaire?->sections()->where('key', 'medisch')->first();
            if (! $section || ! $section->fields()->where('key', 'ir-status')->exists()) {
                return;
            }

            $confirmed = ['aangetoond door dierenarts', 'Vastgesteld door dierenarts/onderzoek', 'Ja, vastgesteld door dierenarts/onderzoek'];
            $suspected = ['Ik vermoed dit', 'Niet vastgesteld, maar ik vermoed dit zelf'];
            $addAfter = static function (string $after, string $key, array $attributes) use ($section): void {
                if ($section->fields()->where('key', $key)->exists()) {
                    return;
                }
                $previous = $section->fields()->where('key', $after)->firstOrFail();
                $order = $previous->order + 1;
                $section->fields()->where('order', '>=', $order)->increment('order');
                $section->fields()->create(['key' => $key, 'order' => $order, 'active' => true] + $attributes);
            };

            $addAfter('ir-status', 'ir-onderzoek-wanneer-welk', [
                'label' => 'Wanneer en welk onderzoek is uitgevoerd?', 'type' => 'text', 'required' => true,
                'show_if' => ['ir-status' => $confirmed],
            ]);
            $addAfter('ir-onderzoek-wanneer-welk', 'ir-onderzoek-uitslag', [
                'label' => 'Wat kwam er uit het onderzoek?', 'type' => 'textarea', 'required' => true,
                'show_if' => ['ir-status' => $confirmed],
            ]);
            $addAfter('ir-onderzoek-uitslag', 'ir-uitslag-beschikbaar', [
                'label' => 'Heb je de uitslag beschikbaar?', 'type' => 'radio', 'required' => true,
                'options' => ['Ja', 'Nee'], 'show_if' => ['ir-status' => $confirmed],
            ]);
            $attest = $section->fields()->where('key', 'ir-attest')->first();
            if ($attest) {
                $attest->update([
                    'label' => 'Upload de uitslag van het onderzoek',
                    'hint' => 'Voeg de uitslag toe als je deze bij de hand hebt.',
                    'optional' => true,
                    'show_if' => ['ir-status' => $confirmed, 'ir-uitslag-beschikbaar' => 'Ja'],
                ]);
            }
            $addAfter($attest ? 'ir-attest' : 'ir-uitslag-beschikbaar', 'ir-vermoeden-toelichting', [
                'label' => 'Leg kort uit waarom je dit vermoed.', 'type' => 'text', 'required' => true,
                'show_if' => ['ir-status' => $suspected],
            ]);
        });
    }

    public function down(): void
    {
        // Preserve existing intake answers and subsequent admin edits.
    }
};
