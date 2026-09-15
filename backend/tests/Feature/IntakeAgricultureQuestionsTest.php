<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntakeQuestionnaire;
use Database\Seeders\IntakeQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntakeAgricultureQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_agriculture_questions_are_visible_in_admin_with_conditional_logic(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::query()->where('slug', 'protocol-intake')->firstOrFail()
            ->sections()->where('key', 'huisvesting')->firstOrFail();
        $fields = $section->fields()->orderBy('order')->get();

        $this->assertSame('omgeving-overig', $fields->last()->key);
        $this->assertSame('Omgeving & landbouwpercelen', $fields->slice(-12)->first()->label);
        $this->assertSame(['landbouw-nabij' => 'Ja', 'landbouw-bespoten' => 'Ja'], $fields->firstWhere('key', 'landbouw-middelen')->show_if);
        $this->assertSame('multi', $fields->firstWhere('key', 'landbouw-middelen')->type);
        $this->assertNull($fields->last()->show_if);

        $admin = AdminUser::query()->create([
            'name' => 'Admin', 'email' => 'agriculture@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true,
        ]);
        $this->actingAs($admin, 'admin')->get('/admin/intake-questionnaire')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('questionnaire.sections', function ($sections): bool {
                $housing = collect($sections)->firstWhere('key', 'huisvesting');

                return collect($housing['fields'])->contains('key', 'landbouw-nabij')
                    && collect($housing['fields'])->contains('key', 'omgeving-overig');
            }));
    }

    public function test_upgrade_appends_only_new_questions_and_preserves_admin_changes_on_rerun(): void
    {
        $this->seed(IntakeQuestionnaireSeeder::class);
        $section = IntakeQuestionnaire::query()->where('slug', 'protocol-intake')->firstOrFail()
            ->sections()->where('key', 'huisvesting')->firstOrFail();
        $section->fields()->where(fn ($query) => $query->where('key', 'like', 'landbouw-%')->orWhereIn('key', ['sec-landbouw', 'omgeving-overig']))->delete();
        $existing = $section->fields()->firstOrFail();
        $existing->update(['label' => 'Eigen tekst van beheerder', 'active' => false]);
        $lastOrder = $section->fields()->max('order');

        $migration = require database_path('migrations/2026_09_16_000001_add_agriculture_intake_questions.php');
        $migration->up();
        $new = $section->fields()->where('key', 'landbouw-nabij')->firstOrFail();
        $this->assertGreaterThan($lastOrder, $new->order);
        $new->update(['label' => 'Aangepaste landbouwvraag']);
        $migration->up();

        $this->assertSame('Eigen tekst van beheerder', $existing->fresh()->label);
        $this->assertFalse($existing->fresh()->active);
        $this->assertSame('Aangepaste landbouwvraag', $new->fresh()->label);
        $this->assertDatabaseCount('intake_fields', 359);
    }
}
