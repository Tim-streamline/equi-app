<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\IntakeAttachment;
use App\Models\IntakeBooking;
use App\Models\IntakeField;
use App\Models\IntakeQuestionnaire;
use App\Models\IntakeSection;
use App\Models\User;
use App\Support\IntakeQuestionnaireSnapshot;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class IntakeQuestionnaireSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private IntakeQuestionnaireSnapshot $snapshots;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IntakeQuestionnaireSeeder::class);
        // Both actual databases still have this heading; the code seeder is newer.
        IntakeField::where('key', 'sec-hoef-upload')->update(['type' => 'sectionhead']);
        $this->snapshots = app(IntakeQuestionnaireSnapshot::class);
        $this->directory = storage_path('framework/testing/intake-snapshot-'.Str::uuid());
        File::makeDirectory($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function booking(): IntakeBooking
    {
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Snapshot horse']);

        return IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id]);
    }

    private function databaseState(): array
    {
        $state = [];
        foreach (['intake_questionnaires', 'intake_sections', 'intake_fields', 'intake_answers', 'intake_attachments', 'intake_bookings'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    public function test_preview_changes_nothing_and_apply_preserves_saved_photo_answer_addresses_and_identity(): void
    {
        $source = $this->snapshots->export();
        $photo = IntakeField::where('key', 'foto-historie')->sole();
        $photo->update(['section_id' => IntakeSection::where('key', 'klacht')->sole()->id, 'answer_section_key' => null, 'label' => 'Old label', 'photo_config' => null]);
        $booking = $this->booking();
        $attachment = IntakeAttachment::create(['booking_id' => $booking->id, 'section_key' => 'klacht', 'field_key' => $photo->key, 'name' => 'old.jpg', 'path' => 'intake/old.jpg', 'mime' => 'image/jpeg']);
        $booking->answers()->create(['section_id' => 'klacht', 'field_id' => $photo->key, 'value' => json_encode(['attachment:'.$attachment->id.':old.jpg'])]);
        $booking->answers()->create(['section_id' => '__photos', 'field_id' => 'year-'.$attachment->id, 'value' => '"2023"']);
        $before = $this->databaseState();
        $preview = $this->snapshots->sync($source);
        $this->assertNotEmpty($preview['changes']);
        $this->assertNotEmpty($preview['warnings']);
        $this->assertSame($before, $this->databaseState());
        $this->assertNull($preview['backup']);

        $result = $this->snapshots->sync($source, true, $this->directory);
        $this->assertSame($source, $this->snapshots->export());
        $this->assertSame($photo->id, IntakeField::where('key', $photo->key)->sole()->id);
        $this->assertSame('klacht', $photo->fresh()->answer_section_key);
        foreach (['intake_answers', 'intake_attachments', 'intake_bookings'] as $table) {
            $this->assertSame($before[$table], $this->databaseState()[$table]);
        }
        $backup = json_decode(file_get_contents($result['backup']), true);
        $this->assertArrayHasKey('backup_records', $backup);
        $this->assertSame(0600, fileperms($result['backup']) & 0777);
        $this->assertStringNotContainsString($attachment->id, file_get_contents($result['backup']));
        $review = app(IntakeReview::class)->forBooking($booking->fresh());
        $row = collect($review['sections'][0]['rows'])->firstWhere('key', $photo->key);
        $this->assertSame($attachment->id, $row['attachments'][0]['id']);
        $this->assertSame('2023', $row['attachments'][0]['year']);
        $after = $this->databaseState();
        $this->assertEmpty($this->snapshots->sync($source, true, $this->directory)['changes']);
        $this->assertSame($after, $this->databaseState());

        // A definition backup is also a valid restore input, without rewriting answers.
        $this->snapshots->sync($backup, true, $this->directory);
        unset($backup['backup_records']);
        $this->assertSame($this->snapshots::json($backup), $this->snapshots::json($this->snapshots->export()));
        $this->assertSame($before['intake_answers'], $this->databaseState()['intake_answers']);
        $this->assertSame($before['intake_attachments'], $this->databaseState()['intake_attachments']);
    }

    public function test_archives_target_only_fields_without_deleting_responses_or_touching_other_questionnaires(): void
    {
        $source = $this->snapshots->export();
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->sole();
        $section = $q->sections()->create(['key' => 'old-section', 'title' => 'Old section', 'active' => true]);
        $field = $section->fields()->create(['key' => 'old-field', 'label' => 'Old field', 'type' => 'text', 'active' => true]);
        $booking = $this->booking();
        $answer = $booking->answers()->create(['section_id' => $section->key, 'field_id' => $field->key, 'value' => '"Keep this response"']);
        $other = IntakeQuestionnaire::create(['slug' => 'other', 'name' => 'Other', 'active' => true]);
        $otherSection = $other->sections()->create(['key' => 'other', 'title' => 'Other', 'active' => true]);
        $otherField = $otherSection->fields()->create(['key' => 'old-field', 'label' => 'Other field', 'type' => 'text', 'active' => true]);

        $result = $this->snapshots->sync($source, true, $this->directory);
        $this->assertFalse($field->fresh()->active);
        $this->assertFalse($section->fresh()->active);
        $this->assertEquals($answer->getAttributes(), $answer->fresh()->getAttributes());
        $this->assertTrue($otherField->fresh()->active);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEmpty($this->snapshots->sync($source)['changes']);
    }

    public function test_refuses_changed_answer_namespace_before_any_write(): void
    {
        $source = $this->snapshots->export();
        foreach ($source['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                if ($field['key'] === 'foto-historie') {
                    $field['answer_section_key'] = 'different';
                }
            }
        }
        $before = $this->databaseState();
        try {
            $this->snapshots->sync($source, true, $this->directory);
            $this->fail('Expected unsafe namespace refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Answer location would change', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState());
        $this->assertEmpty(File::files($this->directory));
    }

    public function test_refuses_ambiguous_existing_keys_instead_of_picking_or_deleting_one(): void
    {
        $source = $this->snapshots->export();
        IntakeSection::where('key', 'contact')->sole()->fields()->create(['key' => 'foto-historie', 'label' => 'Conflicting custom question', 'type' => 'text', 'active' => true]);
        $before = $this->databaseState();
        try {
            $this->snapshots->sync($source, true, $this->directory);
            $this->fail('Expected duplicate refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ambiguous existing field keys', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState());
    }

    public function test_invalid_incomplete_and_duplicate_snapshots_are_rejected(): void
    {
        $source = $this->snapshots->export();
        $invalid = [];
        $invalid[] = [...$source, 'sections' => []];
        $missing = $source;
        unset($missing['sections'][0]['fields'][0]['optional']);
        $invalid[] = $missing;
        $duplicate = $source;
        $duplicate['sections'][1]['fields'][] = $duplicate['sections'][0]['fields'][0];
        $invalid[] = $duplicate;
        $before = $this->databaseState();
        foreach ($invalid as $data) {
            try {
                $this->snapshots->sync($data, true, $this->directory);
                $this->fail('Expected invalid snapshot refusal');
            } catch (ValidationException|RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame($before, $this->databaseState());
    }

    public function test_removed_choice_is_preserved_in_saved_answer_and_reported(): void
    {
        $source = $this->snapshots->export();
        $field = IntakeField::where('key', 'bijvoeding-nu')->sole();
        $field->update(['options' => [...$field->options, 'Legacy option']]);
        $answer = $this->booking()->answers()->create(['section_id' => 'voer', 'field_id' => $field->key, 'value' => '"Legacy option"']);
        $before = $answer->fresh()->getAttributes();
        $result = $this->snapshots->sync($source, true, $this->directory);
        $this->assertContains('bijvoeding-nu: 1 saved answer(s) retained verbatim; changed choices, visibility or requirements may need user review.', $result['warnings']);
        $this->assertSame($before, $answer->fresh()->getAttributes());
        $this->assertNotContains('Legacy option', $field->fresh()->options);
    }

    public function test_creates_missing_sections_and_fields_and_matches_full_local_snapshot_idempotently(): void
    {
        $source = json_decode(file_get_contents(database_path('data/intake-questionnaire-local.json')), true);
        // Only delete definitions in this isolated test database to exercise creation.
        IntakeSection::where('key', 'intake-fotos')->sole()->delete();
        $result = $this->snapshots->sync($source, true, $this->directory);
        $this->assertNotEmpty($result['changes']);
        $this->assertSame($this->snapshots::json($source), $this->snapshots::json($this->snapshots->export()));
        $this->assertDatabaseCount('intake_fields', 395);
        $this->assertEmpty($this->snapshots->sync($source, true, $this->directory)['changes']);
    }

    public function test_backup_failure_rolls_back_all_definition_changes(): void
    {
        $source = $this->snapshots->export();
        $source['questionnaire']['name'] = 'Changed name';
        file_put_contents($this->directory.'/not-directory', 'file');
        $before = $this->databaseState();
        $failed = false;
        try {
            @$this->snapshots->sync($source, true, $this->directory.'/not-directory/child');
        } catch (\Throwable $e) {
            $failed = true;
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertTrue($failed, 'Expected backup failure');
        $this->assertSame($before, $this->databaseState());
    }

    public function test_type_changes_are_refused_even_without_server_answers(): void
    {
        $source = $this->snapshots->export();
        IntakeField::where('key', 'bijvoeding-nu')->update(['type' => 'text']);
        $before = $this->databaseState();
        try {
            $this->snapshots->sync($source, true, $this->directory);
            $this->fail('Expected type-change refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Field type would change', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState());
    }

    public function test_failure_during_apply_rolls_back_earlier_updates(): void
    {
        $source = $this->snapshots->export();
        $source['questionnaire']['name'] = 'Should roll back';
        IntakeField::where('key', 'bijvoeding-nu')->update(['label' => 'Old label']);
        $before = $this->databaseState();
        IntakeField::saving(function () {
            throw new RuntimeException('Simulated write failure');
        });
        try {
            $this->snapshots->sync($source, true, $this->directory);
            $this->fail('Expected write failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated write failure', $e->getMessage());
        } finally {
            IntakeField::flushEventListeners();
        }
        $this->assertSame($before, $this->databaseState());
        $this->assertCount(1, File::files($this->directory));
    }

    public function test_missing_schema_is_reported_before_writes(): void
    {
        $source = $this->snapshots->export();
        Schema::table('intake_fields', fn ($table) => $table->dropColumn('age_config'));
        $before = $this->databaseState();
        try {
            $this->snapshots->sync($source, true, $this->directory);
            $this->fail('Expected schema refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Apply the intake schema migrations first', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState());
    }

    public function test_export_excludes_customer_responses_and_does_not_overwrite_files(): void
    {
        $this->booking()->answers()->create(['section_id' => 'paard', 'field_id' => 'secret', 'value' => '"Private answer content"']);
        $before = $this->databaseState();
        $file = $this->directory.'/export.json';
        $this->artisan('intakes:export-questionnaire', ['file' => $file])->assertSuccessful();
        $json = file_get_contents($file);
        $this->assertStringNotContainsString('Private answer content', $json);
        $this->assertSame($this->snapshots::json($this->snapshots->export()), $json);
        $this->artisan('intakes:export-questionnaire', ['file' => $file])->assertFailed();
        $this->assertSame($json, file_get_contents($file));
        $this->assertSame($before, $this->databaseState());
    }

    public function test_command_defaults_to_preview_and_rejects_invalid_json(): void
    {
        $before = $this->databaseState();
        $this->artisan('intakes:sync-questionnaire')->assertSuccessful();
        $this->assertSame($before, $this->databaseState());
        file_put_contents($this->directory.'/invalid.json', '{');
        $this->artisan('intakes:sync-questionnaire', ['--file' => $this->directory.'/invalid.json', '--apply' => true])->assertFailed();
        $this->assertSame($before, $this->databaseState());
    }
}
