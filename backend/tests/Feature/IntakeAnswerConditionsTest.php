<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntakeQuestionnaire;
use App\Support\IntakeReview;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeAnswerConditionsTest extends TestCase
{
    use RefreshDatabase;

    private function definition(): array
    {
        $q = IntakeQuestionnaire::create(['slug' => 'keyed-test', 'name' => 'Keyed test', 'active' => true, 'none_options' => ['None']]);
        $first = $q->sections()->create(['key' => 'first', 'title' => 'First', 'active' => true]);
        $second = $q->sections()->create(['key' => 'second', 'title' => 'Second', 'active' => true]);
        $a = $first->fields()->create(['key' => 'a', 'label' => 'A', 'type' => 'multi', 'active' => true, 'options' => ['Yes', 'Other', 'None']]);
        $b = $second->fields()->create(['key' => 'b', 'label' => 'B', 'type' => 'radio', 'active' => true, 'options' => ['Yes', 'No']]);
        $child = $second->fields()->create(['key' => 'child', 'label' => 'Child', 'type' => 'text', 'active' => true]);

        return [$q, $a, $b, $child];
    }

    public function test_and_or_and_nested_conditions_reference_answers_across_questions(): void
    {
        [$q, $a, $b, $child] = $this->definition();
        $left = ['$answer' => $a->option_keys['Yes']];
        $right = ['$answer' => $b->option_keys['Yes']];
        $other = ['$answer' => $a->option_keys['Other']];
        $review = app(IntakeReview::class);
        $review->effectiveAnswers($q->sections()->with('fields')->get(), [], []);
        foreach ([
            [['$all' => [$left, $right]], ['Yes'], 'Yes', true],
            [['$all' => [$left, $right]], ['Yes'], 'No', false],
            [['$all' => [$left, $other]], ['Yes', 'Other'], 'No', true],
            [['$all' => [$left, $other]], ['Yes'], 'No', false],
            [['$any' => [$left, $right]], ['None'], 'Yes', true],
            [['$any' => [$left, $right]], ['None'], 'No', false],
            [['$all' => [$right, ['$any' => [$left, $other]]]], ['Other'], 'Yes', true],
        ] as [$rule, $aValue, $bValue, $expected]) {
            $answers = ['first' => ['a' => $aValue], 'second' => ['b' => $bValue]];
            $this->assertSame($expected, $review->visible($rule, $answers['second'], $answers, ['Yes', 'Other', 'None']), json_encode($rule));
        }
        $this->assertNotSame($a->option_keys['Yes'], $b->option_keys['Yes']);
    }

    public function test_label_edits_keep_keys_and_saved_answers_work_through_aliases(): void
    {
        [$q, $a, $b, $child] = $this->definition();
        $key = $a->option_keys['Yes'];
        $child->update(['visibility_rule' => ['$answer' => $key]]);
        $keys = $a->option_keys;
        unset($keys['Yes']);
        $keys['Absolutely'] = $key;
        $a->update(['options' => ['Absolutely', 'Other', 'None'], 'option_keys' => $keys]);
        $this->assertSame($key, $a->fresh()->option_keys['Absolutely']);
        $this->assertSame('Absolutely', $a->fresh()->answer_aliases['Yes']);
        $this->assertSame(['$answer' => $key], $child->fresh()->visibility_rule);
        $this->assertEquals(['first.a' => ['Absolutely', 'Yes']], $child->fresh()->show_if);
        $review = app(IntakeReview::class);
        $answers = ['first' => ['a' => ['Yes']]];
        $effective = $review->effectiveAnswers($q->sections()->with('fields')->get(), $answers, []);
        $this->assertSame($answers, $effective);
        $this->assertTrue($review->visible($child->fresh()->visibility_rule, [], $effective, []));
    }

    public function test_hidden_parent_answers_do_not_trigger_keyed_followups(): void
    {
        [$q, $a, $b, $child] = $this->definition();
        $a->update(['visibility_rule' => ['$answer' => $b->option_keys['Yes']]]);
        $child->update(['visibility_rule' => ['$answer' => $a->option_keys['Yes']]]);
        $answers = ['first' => ['a' => ['Yes']], 'second' => ['b' => 'No', 'child' => 'Old answer']];
        $effective = app(IntakeReview::class)->effectiveAnswers($q->sections()->with('fields')->get(), $answers, []);
        $this->assertArrayNotHasKey('a', $effective['first']);
        $this->assertArrayNotHasKey('child', $effective['second']);
        $this->assertSame('Old answer', $answers['second']['child']);
    }

    public function test_admin_saves_targeted_conditions_and_rejects_cycles_and_reused_keys(): void
    {
        [$q, $a, $b, $child] = $this->definition();
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'key-admin@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $data = fn ($f) => ['section_id' => $f->section_id, 'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'required' => false, 'optional' => true, 'tall' => false, 'active' => true, 'order' => 0, 'options_json' => json_encode($f->options), 'option_keys' => $f->option_keys];
        $url = fn ($f) => '/admin/intake-questionnaire/fields/'.$f->id;
        $this->actingAs($admin, 'admin')->put($url($a), [...$data($a), 'visibility_rule_json' => json_encode(['$answer' => $b->option_keys['Yes']])])->assertSessionHasNoErrors();
        $this->put($url($b), [...$data($b), 'visibility_rule_json' => json_encode(['$answer' => $a->option_keys['Yes']])])->assertSessionHasErrors('show_if_json');
        $this->put($url($b), [...$data($b), 'option_keys' => ['Yes' => $a->option_keys['Yes'], 'No' => $b->option_keys['No']]])->assertSessionHasErrors('option_keys');
    }

    public function test_migration_assigns_global_keys_and_preserves_existing_visibility_behavior(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $q = IntakeQuestionnaire::where('slug', 'protocol-intake')->sole();
        $before = $q->sections()->with('fields')->get();
        $migration = require database_path('migrations/2026_11_01_000002_add_keyed_intake_answer_conditions.php');
        $migration->down();
        $migration->up();
        $after = $q->sections()->with('fields')->get();
        $fields = $after->flatMap->fields;
        $keys = $fields->flatMap(fn ($f) => array_values($f->option_keys ?? []));
        $this->assertSame($keys->count(), $keys->unique()->count());
        $this->assertSame($before->flatMap->fields->sum(fn ($f) => count($f->options ?? [])), $keys->count());
        $oldReview = app(IntakeReview::class);
        $newReview = app(IntakeReview::class);
        $newReview->effectiveAnswers($after, [], $q->none_options);
        foreach ([0, 1, 2, 3] as $variation) {
            $answers = [];
            foreach ($before as $section) {
                foreach ($section->fields as $field) {
                    if (! $field->active || ! $section->active || ! $field->options) {
                        continue;
                    }
                    $options = $field->options;
                    $selected = $variation === 0 ? [$options[0]] : ($variation === 1 ? array_slice($options, -1) : ($variation === 2 ? array_slice($options, 0, 2) : $options));
                    $answers[$field->answer_section_key ?? $section->key][$field->key] = $field->type === 'multi' ? $selected : $selected[0];
                }
            }
            foreach ($before as $section) {
                foreach ($section->fields as $field) {
                    if (! $field->show_if || ! $field->active || ! $section->active) {
                        continue;
                    }
                    $current = $fields->firstWhere('id', $field->id);
                    $values = $oldReview->sectionAnswers($section, $answers);
                    $this->assertSame($oldReview->visible($field->show_if, $values, $answers, $q->none_options), $newReview->visible($current->visibility_rule, $values, $answers, $q->none_options), $field->key.' variation '.$variation);
                }
            }
        }
        $this->assertSame(0, DB::table('intake_answers')->count());
    }
}
