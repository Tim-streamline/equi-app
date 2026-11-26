<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntakeField;
use App\Support\IntakeLabels;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntakeLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_use_current_options_and_keep_independent_labels_after_reordering(): void
    {
        $labels = new IntakeLabels;
        $field = ['type' => 'multi', 'label' => 'Actuele adminvraag', 'options' => ['<4 uur', '>4 uur', 'Ja!', 'Ja?']];
        $first = $labels->complete($field, 'huisvesting_stal', null);
        $labels->validate($first);
        $this->assertCount(4, array_unique(array_column($first[0]['answers'], 'label')));
        $first[0]['label'] = 'eigen_vraag';
        $first[0]['answers'][0]['label'] = 'eigen_antwoord';
        $field['options'] = ['Nieuwe optie', '>4 uur', '<4 uur'];
        $updated = $labels->complete($field, 'andere_sectie', $first);
        $this->assertSame('eigen_vraag', $updated[0]['label']);
        $this->assertSame('eigen_antwoord', $updated[0]['answers'][2]['label']);
        $this->assertCount(3, $updated[0]['answers']);
    }

    public function test_nested_questions_and_options_receive_labels_but_headings_do_not(): void
    {
        $labels = new IntakeLabels;
        $config = $labels->complete(['type' => 'repeater', 'sub' => [
            ['id' => 'middel', 'label' => 'Middel', 'type' => 'text'],
            ['id' => 'gebruik', 'label' => 'Gebruik', 'type' => 'radio', 'options' => ['Ja', 'Nee']],
        ]], 'medicatie', null);
        $this->assertCount(3, $config);
        $this->assertCount(2, $config[2]['answers']);
        $this->assertSame([], $labels->complete(['type' => 'sectionhead'], 'kop', null));
    }

    public function test_repeater_defaults_reserve_question_and_custom_labels_across_the_field(): void
    {
        $labels = new IntakeLabels;
        $field = ['type' => 'repeater', 'sub' => [
            ['id' => 'gebruik', 'type' => 'radio', 'options' => ['Ja', 'Nee']],
            ['id' => 'gebruik_ja', 'type' => 'text'],
            ['id' => 'ander', 'type' => 'text'],
        ]];
        $custom = [['key' => 'ander', 'label' => 'middel_gebruik_nee', 'answers' => []]];
        $config = $labels->complete($field, 'middel', $custom);
        $labels->validate($config);
        $this->assertSame(['middel_gebruik_ja_2', 'middel_gebruik_nee_2'], array_column($config[1]['answers'], 'label'));
        $this->assertSame('middel_gebruik_ja', $config[2]['label']);
        $this->assertSame('middel_gebruik_nee', $config[3]['label']);
        $this->assertSame($config, $labels->complete($field, 'middel', $config));
    }

    public function test_duplicate_labels_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new IntakeLabels)->validate([['label' => 'zelfde', 'answers' => [['answer' => 'Ja', 'label' => 'zelfde']]]]);
    }

    public function test_legacy_repeater_choices_round_trip_with_one_label_per_exact_string(): void
    {
        $labels = new IntakeLabels;
        $field = ['type' => 'repeater', 'sub' => [
            'invalid child', ['type' => 'text'],
            ['id' => 'use', 'options' => ['Ja', ['value' => 'Nee'], 'Ja', 'Ja ', ' ', '01', '1'], 'sub' => 'invalid'],
        ]];
        $legacy = [['key' => 'use', 'answers' => [
            ['answer' => 'Ja', 'label' => 'first_choice'], ['answer' => 'Ja', 'label' => 'duplicate_choice'],
            ['answer' => '01', 'label' => 'leading_zero'], ['answer' => '1', 'label' => 'one'],
        ]]];
        $config = $labels->complete($field, 'medicatie', $legacy);
        $labels->validate($config);
        $this->assertSame(['', 'use'], array_column($config, 'key'));
        $this->assertSame(['Ja', 'Ja ', '01', '1'], array_column($config[1]['answers'], 'answer'));
        $this->assertSame(['first_choice', 'medicatie_use_ja', 'leading_zero', 'one'], array_column($config[1]['answers'], 'label'));
        $this->assertSame($config, $labels->complete($field, 'medicatie', $config));
    }

    public function test_admin_can_label_two_distinct_repeater_answer_strings(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'sub@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $field = IntakeField::where('type', 'repeater')->firstOrFail();
        $subfields = [['id' => 'keuze', 'label' => 'Keuze', 'type' => 'radio', 'options' => ['Ja', 'Ja ']]];
        $answers = [['answer' => 'Ja', 'label' => 'eerste'], ['answer' => 'Ja ', 'label' => 'tweede']];
        $this->actingAs($admin, 'admin')->put('/admin/intake-questionnaire/fields/'.$field->id, [
            'section_id' => $field->section_id, 'key' => $field->key, 'label' => $field->label, 'type' => 'repeater',
            'required' => true, 'optional' => false, 'tall' => false, 'active' => true, 'order' => $field->order,
            'repeater_sub_json' => json_encode($subfields),
            'label_config' => [['key' => 'keuze', 'label' => 'keuze', 'answers' => $answers]],
        ])->assertSessionHasNoErrors();
        $field->refresh();
        $this->assertSame(['Ja', 'Ja '], $field->repeater_sub[0]['options']);
        $this->assertEquals($answers, $field->label_config[1]['answers']);
    }

    public function test_admin_can_save_labels_without_changing_question_or_answer_values(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'labels@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $field = IntakeField::where('type', 'radio')->firstOrFail();
        $data = ['section_id' => $field->section_id, 'key' => $field->key, 'label' => 'Actuele tekst uit admin', 'type' => 'radio',
            'required' => true, 'optional' => false, 'tall' => false, 'active' => true, 'order' => $field->order,
            'options_json' => json_encode(['Nieuwe keuze', 'Andere keuze']),
            'label_config' => [['key' => '', 'label' => 'mijn_vraag', 'answers' => [['answer' => ' Nieuwe keuze ', 'label' => 'mijn_antwoord']]]]];
        $url = '/admin/intake-questionnaire/fields/'.$field->id;
        $this->actingAs($admin, 'admin')->put($url, $data)->assertSessionHasNoErrors();
        $saved = $field->fresh();
        $this->assertSame('Actuele tekst uit admin', $saved->label);
        $this->assertSame(['Nieuwe keuze', 'Andere keuze'], $saved->options);
        $this->assertSame('mijn_antwoord', $saved->label_config[0]['answers'][0]['label']);
        unset($data['label_config']);
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertSame($saved->label_config, $field->fresh()->label_config);
        $data['label_config'] = [['key' => '', 'label' => 'ONGELDIG LABEL', 'answers' => []]];
        $this->put($url, $data)->assertSessionHasErrors('label_config');
        $this->assertSame($saved->label_config, $field->fresh()->label_config);
    }
}
