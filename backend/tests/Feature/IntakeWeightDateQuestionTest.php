<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\User;
use App\Support\IntakeAnswersPdf;
use App\Support\IntakeProgress;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntakeWeightDateQuestionTest extends TestCase
{
    use RefreshDatabase;

    public static function placements(): array
    {
        return [['paard'], ['gezondheid']];
    }

    public function test_weight_date_branch_handles_current_admin_labels_and_preserves_saved_answers(): void
    {
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Test', 'active' => true]);
        $section = $q->sections()->create(['key' => 'paard', 'title' => 'Over je paard', 'active' => true]);
        $options = ['Geschat op zicht', 'Gewogen op weegbrug', 'Gemeten met gewichtslint', 'Rapport dierenarts'];
        $section->fields()->create(['key' => 'gewicht-methode', 'type' => 'radio', 'label' => 'Hoe is dit gewicht bepaald?', 'options' => $options, 'order' => 7, 'active' => true]);
        $date = $section->fields()->create(['key' => 'gewicht-methode-wanneer', 'type' => 'text', 'label' => 'Oude datumvraag', 'order' => 8, 'optional' => true, 'active' => true,
            'show_if' => ['paard.gewicht-methode' => ['rapport dierenarts']]]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
        $method = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode', 'value' => json_encode($options[3])]);
        $answer = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode-wanneer', 'value' => json_encode('mei 2026')]);
        $original = $answer->value;
        $migration = require database_path('migrations/2026_10_26_150000_repair_weight_measurement_date_branch.php');
        $migration->up();
        $migration->up();
        $this->assertSame($original, $answer->fresh()->value);
        $this->assertSame('Wanneer was dat?', $date->fresh()->label);
        $this->assertSame('month_year', $date->fresh()->type);
        $this->assertFalse($date->fresh()->optional);
        $this->assertSame(1, $section->fields()->where('key', $date->key)->count());
        $answer->update(['value' => null]);
        foreach (array_slice($options, 1) as $option) {
            $method->update(['value' => json_encode($option)]);
            $this->assertSame([['section' => 'paard', 'field' => 'gewicht-methode-wanneer']], app(IntakeProgress::class)->forBooking($booking->fresh())['missing']);
            $rows = collect(app(IntakeReview::class)->forBooking($booking->fresh())['sections'])->flatMap(fn ($s) => $s['rows']);
            $this->assertNotNull($rows->firstWhere('key', 'gewicht-methode-wanneer'));
        }
        $method->update(['value' => json_encode($options[0])]);
        $this->assertSame([], app(IntakeProgress::class)->forBooking($booking->fresh())['missing']);
        $rows = collect(app(IntakeReview::class)->forBooking($booking->fresh())['sections'])->flatMap(fn ($s) => $s['rows']);
        $this->assertNull($rows->firstWhere('key', 'gewicht-methode-wanneer'));
    }

    #[DataProvider('placements')]
    public function test_migration_reuses_saved_dates_and_reviews_follow_the_question_location(string $sectionKey): void
    {
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Test', 'active' => true]);
        $section = $q->sections()->create(['key' => $sectionKey, 'title' => 'Test section', 'active' => true]);
        $method = $section->fields()->create(['key' => 'gewicht-methode', 'label' => 'Hoe is dit gewicht bepaald?',
            'answer_section_key' => $sectionKey === 'paard' ? null : 'paard', 'order' => 8,
            'type' => 'radio', 'options' => ['geschat op zicht', 'gewogen op weegbrug', 'gemeten met gewichts-lint', 'rapport dierenarts'],
            'active' => true]);
        $next = $section->fields()->create(['key' => 'custom', 'label' => 'Custom question', 'type' => 'text',
            'order' => 9, 'optional' => true, 'active' => true]);
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id,
            'intake_status' => 'submitted', 'submitted_at' => now(), 'completed_at' => now()]);
        $methodAnswer = $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode', 'value' => '"rapport dierenarts"']);
        $booking->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht-methode-wanneer', 'value' => '"mei 2026"']);
        $originalAnswers = $booking->answers()->orderBy('id')->get()->toArray();
        $originalBooking = $booking->fresh()->getAttributes();

        $migration = require database_path('migrations/2026_10_20_000002_add_weight_measurement_date_question.php');
        $migration->up();
        $date = $section->fields()->where('key', 'gewicht-methode-wanneer')->sole();
        $this->assertSame('paard', $date->answer_section_key);
        $this->assertSame('text', $date->type);
        $this->assertTrue($date->required);
        $this->assertFalse($date->optional);
        $this->assertSame(9, $date->order);
        $this->assertSame(10, $next->fresh()->order);
        $this->assertSame('Custom question', $next->fresh()->label);
        $this->assertSame($originalAnswers, $booking->answers()->orderBy('id')->get()->toArray());
        $this->assertSame($originalBooking, $booking->fresh()->getAttributes());
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($booking->fresh())['stage']);

        // The date can move independently too; its visibility and value still
        // come from the original saved answer namespace.
        $destination = $q->sections()->create(['key' => 'metingen', 'title' => 'Metingen', 'active' => true]);
        $date->update(['section_id' => $destination->id, 'label' => 'Eigen datumvraag']);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $rows = collect($review['sections'])->flatMap(fn ($s) => $s['rows']);
        $this->assertSame('rapport dierenarts', $rows->firstWhere('key', $method->key)['value']);
        $dateRow = $rows->firstWhere('key', $date->key);
        $this->assertSame('mei 2026', $dateRow['value']);
        $this->assertSame('metingen', $dateRow['section']);
        $this->assertSame('paard.gewicht-methode-wanneer', $dateRow['id']);
        $html = app(IntakeAnswersPdf::class)->html($booking->fresh());
        $this->assertStringContainsString('Eigen datumvraag', $html);
        $this->assertStringContainsString('mei 2026', $html);
        $this->assertSame('ready', app(IntakeProgress::class)->forBooking($booking->fresh())['stage']);

        $migration->up();
        $migration->down();
        $this->assertSame(1, $q->sections()->withCount(['fields' => fn ($query) => $query->where('key', $date->key)])->get()->sum('fields_count'));
        $this->assertSame('Eigen datumvraag', $date->fresh()->label);
        $this->assertSame($destination->id, $date->fresh()->section_id);
        $this->assertSame(10, $next->fresh()->order);
        $this->assertSame($originalAnswers, $booking->answers()->orderBy('id')->get()->toArray());

        $methodAnswer->update(['value' => '"geschat op zicht"']);
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $this->assertFalse(collect($review['sections'])->flatMap(fn ($s) => $s['rows'])->contains('key', $date->key));
        $this->assertStringNotContainsString('mei 2026', app(IntakeAnswersPdf::class)->html($booking->fresh()));
        $this->assertSame('"mei 2026"', $booking->answers()->where('field_id', $date->key)->sole()->value);
    }

    public function test_seed_and_sync_snapshot_define_the_same_conditional_date_question(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::where('slug', 'protocol-intake')->sole()->sections()->where('key', 'paard')->sole();
        $method = $section->fields()->where('key', 'gewicht-methode')->sole();
        $date = $section->fields()->where('key', 'gewicht-methode-wanneer')->sole();
        $this->assertSame($method->order + 1, $date->order);
        $snapshot = json_decode(file_get_contents(database_path('data/intake-questionnaire-local.json')), true);
        $fields = collect(collect($snapshot['sections'])->firstWhere('key', 'paard')['fields']);
        $definition = $fields->firstWhere('key', $date->key);
        foreach (['answer_section_key', 'label', 'type', 'placeholder', 'show_if', 'required', 'optional', 'active'] as $key) {
            $this->assertSame($definition[$key], $date->$key, $key);
        }
        $this->assertSame($fields->firstWhere('key', $method->key)['order'] + 1, $definition['order']);
        $id = $date->id;
        (require database_path('migrations/2026_10_20_000002_add_weight_measurement_date_question.php'))->up();
        $this->assertSame($id, $section->fields()->where('key', $date->key)->sole()->id);
    }
}
