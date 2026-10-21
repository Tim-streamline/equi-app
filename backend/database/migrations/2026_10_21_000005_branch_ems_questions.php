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
            if (! $section || ! $section->fields()->where('key', 'ems-status')->exists()) {
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

            $addAfter('ems-status', 'ems-diagnose-basis', [
                'label' => 'Waarop is de diagnose EMS gebaseerd?', 'type' => 'multi', 'required' => true,
                'options' => [
                    'Vastgestelde insulinedysregulatie / insulineresistentie',
                    'Overgewicht of plaatselijke vetophopingen',
                    'Eerdere of terugkerende hoefbevangenheid',
                    'Beoordeling door een dierenarts',
                    'Anders, namelijk',
                    'Weet ik niet',
                ],
                'show_if' => ['ems-status' => $confirmed],
            ]);
            $addAfter('ems-diagnose-basis', 'ems-diagnose-basis-anders', [
                'label' => 'Anders, namelijk — licht kort toe', 'type' => 'text', 'required' => true,
                'show_if' => ['ems-status' => $confirmed, 'ems-diagnose-basis' => 'Anders, namelijk'],
            ]);
            $addAfter('ems-diagnose-basis-anders', 'ems-vermoeden-redenen', [
                'label' => 'Waarom vermoed je dat je paard EMS heeft?', 'type' => 'multi', 'required' => true,
                'options' => [
                    'Mijn paard heeft overgewicht',
                    'Mijn paard heeft plaatselijke vetophopingen, bijvoorbeeld een harde vetkam',
                    'Mijn paard valt moeilijk af',
                    'Mijn paard is eerder hoefbevangen geweest',
                    'Mijn paard is gevoelig voor suikerrijk gras of voer',
                    'Anders, namelijk',
                ],
                'show_if' => ['ems-status' => $suspected],
            ]);
            $addAfter('ems-vermoeden-redenen', 'ems-vermoeden-anders', [
                'label' => 'Anders, namelijk — licht kort toe', 'type' => 'text', 'required' => true,
                'show_if' => ['ems-status' => $suspected, 'ems-vermoeden-redenen' => 'Anders, namelijk'],
            ]);
        });
    }

    public function down(): void
    {
        // Preserve existing answers and subsequent admin edits.
    }
};
