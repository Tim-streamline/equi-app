<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntakeSection;
use App\Models\IntakeField;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminIntakeQuestionnaireTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::query()->create([
            'name' => 'Intake Admin',
            'email' => 'intake-admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_submission_blocks_are_editable_and_require_a_message(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'hoefbevangenheid')->firstOrFail();
        $data = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => $field->label,
            'type' => 'radio', 'required' => true, 'optional' => false, 'tall' => false, 'active' => true,
            'order' => $field->order, 'options_json' => json_encode($field->options),
            'submission_blocks' => [['answer' => $field->options[0], 'message' => 'Neem eerst contact op.']]];
        $url = '/admin/intake-questionnaire/fields/'.$field->id;
        $this->actingAs($this->admin, 'admin')->put($url, $data)->assertSessionHasNoErrors();
        $this->assertSame($data['submission_blocks'], $field->fresh()->submission_blocks);
        $oldForm = $data;
        unset($oldForm['submission_blocks']);
        $this->put($url, $oldForm)->assertSessionHasNoErrors();
        $this->assertSame($data['submission_blocks'], $field->fresh()->submission_blocks);
        $invalid = $data;
        $invalid['submission_blocks'][0]['message'] = '';
        $this->put($url, $invalid)->assertSessionHasErrors('submission_blocks');
        $this->assertSame($data['submission_blocks'], $field->fresh()->submission_blocks);
        $data['submission_blocks'] = [];
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertSame([], $field->fresh()->submission_blocks);
    }

    public function test_section_presentation_is_editable_and_validated(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $s = IntakeSection::where('key', 'intake-fotos')->sole();
        $data = ['questionnaire_id' => $s->questionnaire_id, 'key' => $s->key, 'title' => 'Custom photos', 'intro' => 'Custom intro', 'subtitle' => 'Custom subtitle', 'order' => 0, 'minutes' => 1, 'active' => true,
            'presentation' => ['intro_style' => 'card', 'offline_notice' => 'Custom offline message']];
        $this->actingAs($this->admin, 'admin')->put('/admin/intake-questionnaire/sections/'.$s->id, $data)->assertSessionHasNoErrors();
        $this->assertSame($data['presentation'], $s->fresh()->presentation);
        $data['presentation']['intro_style'] = 'arbitrary';
        $this->put('/admin/intake-questionnaire/sections/'.$s->id, $data)->assertSessionHasErrors('presentation.intro_style');
    }

    public function test_current_hard_coded_questionnaire_is_seeded_completely(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);

        $this->assertDatabaseCount('intake_questionnaires', 1);
        $this->assertDatabaseCount('intake_sections', 13);
        $seed = json_decode(file_get_contents(database_path('data/intake-questionnaire.json')), true);
        $this->assertDatabaseCount('intake_fields', array_sum(array_map(fn ($section) => count($section['fields']), $seed['sections'])));
        $this->assertDatabaseHas('intake_fields', [
            'key' => 'merrie-vruchtbaarheid',
            'type' => 'radio',
            'label' => 'Zijn er ooit vruchtbaarheids- of voortplantingsproblemen geweest?',
        ]);

        $field = DB::table('intake_fields')->where('key', 'merrie-vruchtbaarheid')->firstOrFail();
        $this->assertSame(['paard.geslacht' => 'merrie'], json_decode($field->show_if, true));

        $questionnaire = DB::table('intake_questionnaires')->firstOrFail();
        $this->assertContains('geen', json_decode($questionnaire->none_options, true));
    }

    public function test_admin_can_manage_sections_and_every_field_logic_setting(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $questionnaireId = DB::table('intake_questionnaires')->value('id');

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/intake-questionnaire')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('IntakeQuestionnaire/Index')
                ->where('questionnaire.slug', 'protocol-intake')
                ->has('questionnaire.sections', 13));

        $this->actingAs($this->admin, 'admin')
            ->put("/admin/intake-questionnaire/{$questionnaireId}", [
                'name' => 'Protocol-intake',
                'disclaimer_short' => 'Korte tekst',
                'disclaimer_long' => 'Lange tekst',
                'none_options_json' => '["geen","niet van toepassing"]',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['geen', 'niet van toepassing'],
            json_decode(DB::table('intake_questionnaires')->value('none_options'), true),
        );

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/intake-questionnaire/sections', [
                'questionnaire_id' => $questionnaireId,
                'key' => 'extra',
                'title' => 'Extra intake',
                'intro' => 'Configureerbare extra vragen.',
                'subtitle' => 'Extra',
                'icon' => 'sparkles',
                'minutes' => 2,
                'order' => 12,
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $sectionId = DB::table('intake_sections')->where('key', 'extra')->value('id');

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/intake-questionnaire/fields', [
                'section_id' => $sectionId,
                'key' => 'extra-vraag',
                'label' => 'Extra vraag',
                'type' => 'radio',
                'hint' => 'Beheerbaar vanuit de backend.',
                'required' => true,
                'optional' => false,
                'unit' => null,
                'step' => null,
                'tall' => false,
                'lines' => null,
                'placeholder' => null,
                'order' => 0,
                'active' => true,
                'options_json' => '["Ja","Nee"]',
                'show_if_json' => '{"paard.geslacht":"merrie"}',
                'flag_if_json' => '["Ja"]',
                'critical_if_json' => '["Ja"]',
                'protocol_if_json' => '{"route":"manual-review"}',
                'link_json' => '{"text":"Meer informatie","url":"https://example.test"}',
                'repeater_sub_json' => '[{"id":"naam","label":"Naam","type":"text"}]',
            ])
            ->assertSessionHasNoErrors();

        $field = DB::table('intake_fields')->where('key', 'extra-vraag')->firstOrFail();
        $this->assertSame(['Ja', 'Nee'], json_decode($field->options, true));
        $this->assertSame(['paard.geslacht' => 'merrie'], json_decode($field->show_if, true));
        $this->assertSame(['Ja'], json_decode($field->critical_if, true));
        $this->assertEquals([['id' => 'naam', 'label' => 'Naam', 'type' => 'text']], json_decode($field->repeater_sub, true));

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/intake-questionnaire/fields', [
                'section_id' => $sectionId,
                'key' => 'ongeldige-opties',
                'label' => 'Ongeldige opties',
                'type' => 'radio',
                'required' => true,
                'optional' => false,
                'tall' => false,
                'order' => 1,
                'active' => true,
                'options_json' => '{"ja":true}',
            ])
            ->assertSessionHasErrors('options_json');
    }

    public function test_owner_signal_vocabulary_is_admin_editable_and_older_forms_preserve_it(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $field = IntakeField::where('key', 'ir-vermoeden-toelichting')->firstOrFail();
        $data = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => 'Eigen toelichting',
            'type' => 'text', 'required' => true, 'optional' => false, 'tall' => false, 'order' => $field->order,
            'active' => true, 'placeholder' => 'Eigen voorbeeld',
            'signal_config_json' => json_encode(['condition' => 'IR', 'signals' => ['eigen_signaal' => 'Eigen omschrijving']])];
        $this->actingAs($this->admin, 'admin')->put('/admin/intake-questionnaire/fields/'.$field->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(['eigen_signaal' => 'Eigen omschrijving'], $field->fresh()->signal_config['signals']);
        unset($data['signal_config_json']);
        $this->put('/admin/intake-questionnaire/fields/'.$field->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(['eigen_signaal' => 'Eigen omschrijving'], $field->fresh()->signal_config['signals']);
        $data['signal_config_json'] = json_encode(['condition' => 'IR', 'source' => 'diagnosed', 'signals' => ['vetkam' => 'Vetkam']]);
        $this->put('/admin/intake-questionnaire/fields/'.$field->id, $data)->assertSessionHasErrors('signal_config_json');
    }
}
