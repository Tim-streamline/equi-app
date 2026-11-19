<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpuFollowupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_uses_admin_options_preserves_answers_and_adds_labeled_editable_followups(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()->sections()->where('key', 'medisch')->firstOrFail();
        $status = $section->fields()->where('key', 'kpu-status')->firstOrFail();
        $options = ['Ja, aangetoond met urineonderzoek', 'Niet vastgesteld, maar ik vermoed dit zelf', 'Nee, voor zover bekend niet', 'Weet ik niet'];
        $status->update(['options' => $options]);
        $legacy = $section->fields()->create(['key' => 'kpu-onderzoek-uitslag', 'label' => 'Oude uitslag', 'type' => 'text', 'order' => 100, 'active' => true]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'KPU']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $answer = $booking->answers()->create(['section_id' => 'medisch', 'field_id' => $legacy->key, 'value' => json_encode('Indikan 10, KPU 20')]);
        $migration = require database_path('migrations/2026_11_19_000001_expand_kpu_urine_followups.php');
        $migration->up();
        $keys = $section->fields()->orderBy('order')->pluck('key')->all();
        $this->assertSame(['kpu-status', 'kpu-onderzoek-datum', 'kpu-indikan-waarde', 'kpu-kryptopyrrolurie-waarde', 'kpu-hertest', 'kpu-hertest-datum'], array_slice($keys, array_search('kpu-status', $keys, true), 6));
        $date = $section->fields()->where('key', 'kpu-onderzoek-datum')->firstOrFail();
        $this->assertSame('month_year', $date->type);
        $this->assertTrue($date->date_config['allow_unknown']);
        $this->assertContains($options[0], $date->show_if['kpu-status']);
        $retest = $section->fields()->where('key', 'kpu-hertest-datum')->firstOrFail();
        $this->assertSame('Ja', $retest->show_if['kpu-hertest']);
        $this->assertContains($options[0], $retest->show_if['kpu-status']);
        $this->assertNotEmpty($retest->label_config);
        $this->assertSame($options, $status->fresh()->options);
        $this->assertFalse($legacy->fresh()->active);
        $this->assertSame(json_encode('Indikan 10, KPU 20'), $answer->fresh()->value);
        $migration->up();
        $this->assertSame($keys, $section->fields()->orderBy('order')->pluck('key')->all());
    }
}
