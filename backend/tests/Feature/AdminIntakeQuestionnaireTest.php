<?php

namespace Tests\Feature;

use App\Models\AdminUser;
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

    public function test_current_hard_coded_questionnaire_is_seeded_completely(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);

        $this->assertDatabaseCount('intake_questionnaires', 1);
        $this->assertDatabaseCount('intake_sections', 12);
        $this->assertDatabaseCount('intake_fields', 359);
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
                ->has('questionnaire.sections', 12));

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
}
