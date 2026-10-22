<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeQuestionnaireSnapshot;
use App\Support\IntakeReview;
use App\Support\IntakeSafetySettings;
use App\Support\LaminitisSafety;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfigurableIntakeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkflow(): array
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::firstOrFail();
        $config = json_decode(file_get_contents(base_path('tests/Fixtures/intake-safety.json')), true);
        app(IntakeSafetySettings::class)->validate($config);
        $q->update(['safety_config' => $config]);
        $section = $q->sections()->create(['key' => $config['section'], 'title' => 'Custom safety', 'order' => 90, 'minutes' => 1, 'active' => true]);
        foreach (['condition' => ['urgent', 'previous', 'none'], 'resolved' => ['confirmed', 'no'], 'stable' => ['approved', 'no'], 'recovered' => ['recovered', 'not yet', 'uncertain']] as $key => $options) {
            $section->fields()->create(['key' => $key, 'type' => 'radio', 'label' => 'Question '.$key, 'options' => $options, 'order' => 1, 'active' => true]);
        }
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Config test']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);

        return [$q, $booking, app(LaminitisSafety::class), $config];
    }

    private function answer(IntakeBooking $booking, string $key, string $value): void
    {
        $booking->answers()->updateOrCreate(['section_id' => 'custom-safety', 'field_id' => $key], ['value' => json_encode($value)]);
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('laminitis', $e->errors());
        }
    }

    public function test_custom_keys_values_and_actions_drive_the_workflow_and_new_episodes_reject_stale_releases(): void
    {
        [$q, $booking, $safety, $config] = $this->setupWorkflow();
        $this->answer($booking, 'condition', 'none');
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
        $this->answer($booking, 'condition', 'urgent');
        $state = $booking->horse->fresh()->laminitis_safety;
        $this->assertSame('urgent', $state['kind']);
        $this->assertSame($config, $state['configuration']);
        $this->reject(fn () => $safety->assertAllowed($booking->horse->fresh(), 'submit'));
        $this->answer($booking, 'resolved', 'Ja');
        $this->answer($booking, 'stable', 'approved');
        $this->reject(fn () => $safety->release($booking, $state['episode_id'], $booking->user_id));
        $this->answer($booking, 'resolved', 'confirmed');
        $safety->release($booking, $state['episode_id'], $booking->user_id);
        $this->assertFalse($safety->blocked($booking->horse->fresh()));
        $this->answer($booking, 'condition', 'none');
        $this->answer($booking, 'condition', 'urgent');
        $this->reject(fn () => $safety->release($booking, $state['episode_id'], $booking->user_id));
        $this->assertCount(1, $booking->horse->fresh()->laminitis_safety['history']);
    }

    public function test_existing_hold_and_recovery_contract_survive_settings_changes_and_submitted_answers_remain_unchanged(): void
    {
        [$q, $booking, $safety] = $this->setupWorkflow();
        $this->answer($booking, 'condition', 'urgent');
        $state = $booking->horse->fresh()->laminitis_safety;
        $before = $booking->answers()->pluck('value', 'id')->all();
        $booking->forceFill(['submitted_at' => now(), 'completed_at' => now()])->save();
        $q->update(['safety_config' => null]);
        IntakeField::where('key', 'resolved')->update(['options' => json_encode(['changed']), 'label' => 'Changed']);
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
        $this->assertSame(['confirmed', 'no'], collect($state['recovery_fields'])->firstWhere('id', 'resolved')['options']);
        $this->reject(fn () => $safety->release($booking, $state['episode_id'], $booking->user_id, ['resolved' => 'changed', 'stable' => 'approved']));
        $safety->release($booking, $state['episode_id'], $booking->user_id, ['resolved' => 'confirmed', 'stable' => 'approved']);
        $this->assertSame($before, $booking->answers()->pluck('value', 'id')->all());
    }

    public function test_conditional_recovery_remains_required_after_answers_are_deleted_or_a_different_intake_is_opened(): void
    {
        [$q, $booking, $safety] = $this->setupWorkflow();
        $this->answer($booking, 'condition', 'previous');
        $this->answer($booking, 'recovered', 'uncertain');
        $state = $booking->horse->fresh()->laminitis_safety;
        $booking->answers()->delete();
        $other = IntakeBooking::create(['user_id' => $booking->user_id, 'horse_id' => $booking->horse_id]);
        $this->answer($other, 'resolved', 'confirmed');
        $this->answer($other, 'stable', 'approved');
        $this->reject(fn () => $safety->release($other, $state['episode_id'], $other->user_id));
        $this->answer($other, 'recovered', 'recovered');
        $safety->release($other, $state['episode_id'], $other->user_id);
        $this->assertFalse($safety->blocked($other->horse->fresh()));
    }

    public function test_migration_keeps_answers_timestamps_and_all_existing_hold_metadata_and_is_repeatable(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::firstOrFail();
        $q->update(['safety_config' => null]);
        $user = User::factory()->create();
        $legacy = ['episode_id' => fake()->uuid(), 'kind' => 'acute', 'booking_id' => null, 'reported_at' => '2026-10-21', 'history' => [['episode_id' => 'older', 'released_at' => '2026-09-01']]];
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Legacy']);
        $horse->forceFill(['laminitis_safety' => $legacy])->save();
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $booking->answers()->create(['section_id' => 'medisch', 'field_id' => 'ontworming-methode', 'value' => json_encode('Combinatie van beide')]);
        $answers = DB::table('intake_answers')->get()->toJson();
        $timestamp = $horse->fresh()->updated_at->toISOString();
        $migration = require database_path('migrations/2026_10_22_000001_configure_intake_safety_rules.php');
        $migration->up();
        $state = $horse->fresh()->laminitis_safety;
        $this->assertSame($legacy, array_intersect_key($state, $legacy));
        $this->assertSame($timestamp, $horse->fresh()->updated_at->toISOString());
        $this->assertSame($answers, DB::table('intake_answers')->get()->toJson());
        $this->assertTrue(app(LaminitisSafety::class)->blocked($horse->fresh()));
        $migration->up();
        $this->assertSame($state, $horse->fresh()->laminitis_safety);
        $review = app(IntakeReview::class)->forBooking($booking);
        $this->assertSame('Combinatie van beide', $review['answers']['medisch']['ontworming-methode']);
        $this->assertSame('Een combinatie van beide', collect($review['sections'])->firstWhere('id', 'medisch')['rows'][array_search('ontworming-methode', array_column(collect($review['sections'])->firstWhere('id', 'medisch')['rows'], 'key'))]['value']);
    }

    public function test_settings_validation_rejects_empty_recovery_and_alias_cycles(): void
    {
        [, , , $config] = $this->setupWorkflow();
        $config['recovery']['require'] = [];
        try {
            app(IntakeSafetySettings::class)->validate($config);
            $this->fail('Expected invalid config');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recovery.require', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(IntakeSafetySettings::class)->validateAliases(['old' => 'new', 'new' => 'old']);
    }

    public function test_admin_edits_rules_and_old_forms_preserve_them_and_reject_invalid_rules(): void
    {
        [$q, $booking, $safety, $config] = $this->setupWorkflow();
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'safety-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $base = ['name' => $q->name, 'none_options_json' => json_encode($q->none_options)];
        $config['actions'] = ['protocol'];
        $this->actingAs($admin, 'admin')->put('/admin/intake-questionnaire/'.$q->id, $base + ['safety_config_json' => json_encode($config)])->assertSessionHasNoErrors();
        $this->assertSame($config, $q->fresh()->safety_config);
        $this->put('/admin/intake-questionnaire/'.$q->id, $base)->assertSessionHasNoErrors();
        $this->assertSame($config, $q->fresh()->safety_config);
        $this->answer($booking, 'condition', 'urgent');
        $safety->assertAllowed($booking->horse->fresh(), 'submit');
        $this->reject(fn () => $safety->assertAllowed($booking->horse->fresh(), 'protocol'));
        $config['recovery']['require'] = [];
        $this->put('/admin/intake-questionnaire/'.$q->id, $base + ['safety_config_json' => json_encode($config)])->assertSessionHasErrors('safety_config_json');
    }

    public function test_older_snapshots_preserve_new_settings_and_aliases_without_touching_holds(): void
    {
        [$q, $booking, $safety, $config] = $this->setupWorkflow();
        $this->answer($booking, 'condition', 'urgent');
        $state = $booking->horse->fresh()->laminitis_safety;
        $snapshots = app(IntakeQuestionnaireSnapshot::class);
        $snapshot = $snapshots->export();
        unset($snapshot['questionnaire']['safety_config']);
        foreach ($snapshot['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                unset($field['answer_aliases']);
            }
        }
        unset($section, $field);
        $this->assertEmpty($snapshots->sync($snapshot, true)['changes']);
        $this->assertSame($config, $q->fresh()->safety_config);
        $this->assertSame('Een combinatie van beide', IntakeField::where('key', 'ontworming-methode')->firstOrFail()->answer_aliases['Combinatie van beide']);
        $this->assertSame($state, $booking->horse->fresh()->laminitis_safety);
    }

    public function test_repeated_unresolved_reports_acknowledge_the_latest_offline_marker_without_replacing_the_hold(): void
    {
        [, $booking, $safety] = $this->setupWorkflow();
        $this->answer($booking, '_event', 'first');
        $this->answer($booking, 'condition', 'previous');
        $this->answer($booking, 'recovered', 'uncertain');
        $episode = $booking->horse->fresh()->laminitis_safety['episode_id'];
        $this->answer($booking, '_event', 'second');
        $this->answer($booking, 'recovered', 'not yet');
        $state = $booking->horse->fresh()->laminitis_safety;
        $this->assertSame($episode, $state['episode_id']);
        $this->assertSame('second', $state['client_marker']);
        $this->assertTrue($safety->blocked($booking->horse->fresh()));
    }
}
