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
            if (! $section || ! $section->fields()->where('key', 'kpu-status')->exists()) {
                return;
            }

            $positive = ['aangetoond uit urine onderzoek', 'Aangetoond met urineonderzoek', 'Ja, positieve uitslag bij urineonderzoek'];
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

            $addAfter('kpu-status', 'kpu-onderzoek-datum', [
                'label' => 'Wanneer is het urineonderzoek uitgevoerd?', 'type' => 'date', 'required' => true,
                'hint' => 'Een exacte datum is niet nodig. Een geschatte maand, seizoen of periode mag ook.',
                'placeholder' => 'dd-mm-jjjj of bijvoorbeeld voorjaar 2025',
                'show_if' => ['kpu-status' => $positive],
            ]);
            $addAfter('kpu-onderzoek-datum', 'kpu-onderzoek-uitslag', [
                'label' => 'Wat was de uitslag van het urineonderzoek?', 'type' => 'text', 'required' => true,
                'placeholder' => 'Vul de gemeten waarde(n), eenheid en eventuele referentiewaarden in.',
                'show_if' => ['kpu-status' => $positive],
            ]);
            $attest = $section->fields()->where('key', 'kpu-attest')->first();
            if ($attest) {
                $attest->update([
                    'label' => 'Heb je de uitslag van het urineonderzoek beschikbaar?',
                    'hint' => 'Optioneel. Upload het bestand indien beschikbaar.',
                    'optional' => true,
                    'show_if' => ['kpu-status' => $positive],
                ]);
            }
            $addAfter($attest ? 'kpu-attest' : 'kpu-onderzoek-uitslag', 'kpu-vermoeden-toelichting', [
                'label' => 'Waarom vermoed je dat je paard KPU heeft?', 'type' => 'text', 'required' => true,
                'show_if' => ['kpu-status' => $suspected],
            ]);
        });
    }

    public function down(): void
    {
        // Preserve saved answers and subsequent admin edits.
    }
};
