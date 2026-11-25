<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeQuestionnaireSnapshot;
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

    public function test_moved_questions_keep_their_presentation_order_and_answer_namespace(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $sections = IntakeQuestionnaire::where('slug', 'protocol-intake')->firstOrFail()->sections()->get()->keyBy('key');
        $medical = $sections['medisch'];
        $status = $medical->fields()->where('key', 'kpu-status')->firstOrFail();
        $status->update(['section_id' => $sections['paard']->id, 'answer_section_key' => 'medisch', 'order' => 77]);
        $date = $medical->fields()->where('key', 'kpu-onderzoek-datum')->firstOrFail();
        $date->update(['section_id' => $sections['voer']->id, 'answer_section_key' => 'medisch', 'order' => 78, 'type' => 'text']);
        // An admin can arrange questions in the opposite order to their database IDs.
        [$lowId, $highId] = $sections['paard']->fields()->whereKeyNot($status->id)->reorder('id')->limit(2)->get()->all();
        $lowId->update(['order' => 81]);
        $highId->update(['order' => 76]);
        $medical->fields()->where('key', 'kpu-indikan-waarde')->delete();
        $migration = require database_path('migrations/2026_11_19_000001_expand_kpu_urine_followups.php');
        $migration->up();
        $migration->up();
        $date->refresh();
        $this->assertSame([$sections['voer']->id, 78, 'month_year'], [$date->section_id, $date->order, $date->type]);
        $this->assertSame(1, IntakeField::where('key', $date->key)->count());
        $this->assertContains($status->options[0], $date->show_if['medisch.kpu-status']);
        $new = $sections['paard']->fields()->where('key', 'kpu-indikan-waarde')->firstOrFail();
        $this->assertSame('medisch', $new->answer_section_key);
        $this->assertSame($status->fresh()->order + 1, $new->order);
        $this->assertSame($date->show_if, $new->show_if);
        $this->assertLessThan($status->fresh()->order, $highId->fresh()->order);
        $this->assertGreaterThan($new->order, $lowId->fresh()->order);
    }

    public function test_the_shipped_local_snapshot_already_matches_the_migration_so_a_sync_does_not_undo_it(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $snapshots = app(IntakeQuestionnaireSnapshot::class);
        $file = json_decode(file_get_contents(database_path('data/intake-questionnaire-local.json')), true);
        $directory = storage_path('framework/testing/kpu-snapshot-'.\Illuminate\Support\Str::uuid());
        try {
            $snapshots->sync($file, true, $directory);
            $user = User::factory()->create();
            $horse = Horse::create(['owner_id' => $user->id, 'name' => 'KPU']);
            $answer = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id])->answers()
                ->create(['section_id' => 'medisch', 'field_id' => 'kpu-onderzoek-uitslag', 'value' => json_encode('Indikan 10, KPU 20')]);
            // The migration on a destination that matches the snapshot changes nothing, and the README's
            // manual sync after it finds nothing to change either.
            (require database_path('migrations/2026_11_19_000001_expand_kpu_urine_followups.php'))->up();
            $this->assertSame([], $snapshots->sync($file)['changes']);
            $this->assertSame([], $snapshots->sync($file, true, $directory)['changes']);
            $fields = IntakeField::where('key', 'like', 'kpu-%')->orderBy('order')->get()->keyBy('key');
            $this->assertSame(['kpu-status', 'kpu-onderzoek-datum', 'kpu-indikan-waarde', 'kpu-kryptopyrrolurie-waarde', 'kpu-hertest', 'kpu-hertest-datum'], $fields->keys()->take(6)->all());
            $this->assertFalse($fields['kpu-onderzoek-uitslag']->active);
            $this->assertContains('Ja, aangetoond met urineonderzoek', $fields['kpu-attest']->show_if['kpu-status']);
            $this->assertSame(json_encode('Indikan 10, KPU 20'), $answer->fresh()->value);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($directory);
        }
    }
}
