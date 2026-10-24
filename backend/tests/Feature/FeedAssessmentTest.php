<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\FeedAssessment;
use App\Models\FeedAssessmentOverride;
use App\Models\FeedRuleVersion;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\FeedAssessmentEngine;
use App\Support\FeedCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeedAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create(['name' => 'Policy admin', 'email' => 'policy@example.com', 'password' => 'password', 'role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function version(string $sugar = '4', string $starch = '10', string $profile = 'standard', string $basis = 'product', array $ingredients = []): ProductVersion
    {
        $product = Product::create(['brand' => 'Example', 'name' => 'Feed']);
        $data = ['brand' => 'Example', 'name' => 'Feed', 'barcode' => null, 'category' => $profile === 'balancer' ? 'balancer' : 'complementary',
            'composition_text' => 'Samenstelling volgens het etiket', 'ingredients' => $ingredients,
            'nutrients' => [['code' => 'sugar', 'value' => $sugar, 'unit' => '%', 'basis' => $basis, 'qualifier' => 'exact'],
                ['code' => 'starch', 'value' => $starch, 'unit' => '%', 'basis' => $basis, 'qualifier' => 'exact']]];
        $version = ProductVersion::create(['product_id' => $product->id, 'number' => 1, 'status' => 'approved', 'data' => $data,
            'profile_id' => DB::table('feed_assessment_profiles')->where('code', $profile)->value('id'), 'content_hash' => hash('sha256', json_encode($data))]);
        $product->forceFill(['catalog_status' => 'published', 'published_version_id' => $version->id])->save();

        return $version;
    }

    private function activate(string $code = 'standard'): FeedRuleVersion
    {
        $profileId = DB::table('feed_assessment_profiles')->where('code', $code)->value('id');
        $rules = FeedRuleVersion::where('profile_id', $profileId)->orderByDesc('number')->firstOrFail();
        $this->post("/admin/feed-scanner/profiles/$profileId/publish", ['version_id' => $rules->id])->assertRedirect()->assertSessionHasNoErrors();

        return $rules->fresh();
    }

    private function calculate(ProductVersion $version): FeedAssessment
    {
        $this->post("/admin/feed-scanner/products/{$version->product_id}/assess", ['version_id' => $version->id, 'automatic_score' => 9.9])->assertRedirect()->assertSessionHasNoErrors();

        return FeedAssessment::where('product_version_id', $version->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    private function canonical(array $value): array
    {
        // JSONB may reorder object keys; list order and value types must remain exact.
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    public function test_migration_appends_configurable_caps_without_rewriting_or_activating_old_policies(): void
    {
        $profile = DB::table('feed_assessment_profiles')->where('code', 'standard')->sole();
        $versions = FeedRuleVersion::where('profile_id', $profile->id)->orderBy('number')->get();
        $this->assertCount(2, $versions);
        $this->assertArrayNotHasKey('sugar_starch_caps', $versions[0]->data);
        $this->assertEquals(20, $versions[1]->data['sugar_starch_caps'][0]['threshold']);
        $this->assertTrue($versions[1]->data['sugar_starch_caps'][0]['inclusive']);
        $this->assertNull($profile->active_rule_version_id);
        $version = $this->version();
        $this->post("/admin/feed-scanner/products/{$version->product_id}/assess", ['version_id' => $version->id])->assertSessionHasErrors('assessment');
        $this->assertSame(0, FeedAssessment::count());
    }

    public function test_published_policy_generates_reproducible_zero_and_ignores_client_scores(): void
    {
        $policy = $this->activate();
        $version = $this->version('6.6', '26.4');
        $assessment = $this->calculate($version);
        $this->assertSame('0.00', $assessment->automatic_score);
        $this->assertSame('0.00', $assessment->score_cap);
        $this->assertFalse($assessment->is_preview);
        $this->assertSame('product', $assessment->scope);
        $this->assertNull($assessment->horse_id);
        $this->assertSame($policy->id, $assessment->rule_version_id);
        $this->assertSame($policy->data, $assessment->rule_snapshot);
        $this->assertSame($this->canonical($version->data), $this->canonical($assessment->input_snapshot));
        $this->assertSame($this->canonical($assessment->result), $this->canonical((new FeedAssessmentEngine)->evaluate($assessment->input_snapshot, $assessment->rule_snapshot)));
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 1, 'reason' => 'Mag niet boven het plafond'])->assertSessionHasErrors('score');
        $this->assertSame(0, FeedAssessmentOverride::count());
        try {
            $assessment->update(['automatic_score' => 8]);
            $this->fail('Assessment history must be immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('onveranderlijk', $e->getMessage());
        }
    }

    public function test_positive_cap_is_not_a_score_and_manual_assessments_are_reasoned_and_capped(): void
    {
        $this->activate();
        $assessment = $this->calculate($this->version('8', '8'));
        $this->assertNull($assessment->automatic_score);
        $this->assertSame('4.00', $assessment->score_cap);
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 4])->assertSessionHasErrors('reason');
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 4.01, 'reason' => 'Te hoog'])->assertSessionHasErrors('score');
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 4, 'reason' => 'Eerste beoordeling door eigenaar'])->assertSessionHasNoErrors();
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 3.5, 'reason' => 'Bijgesteld na inhoudelijke controle'])->assertSessionHasNoErrors();
        $this->assertNull($assessment->fresh()->automatic_score);
        $this->assertCount(2, $assessment->fresh()->overrides);
        $this->assertSame('3.50', $assessment->fresh()->overrides[0]->corrected_score);
        $this->assertSame('Policy admin', $assessment->fresh()->overrides[0]->actor_name);
    }

    public function test_selenium_cap_cannot_be_overridden_and_uncertain_data_cannot_get_a_manual_final_score(): void
    {
        $this->activate();
        $version = $this->version('3', '3', ingredients: [['selenium_form' => 'organic', 'evidence' => 'Fabrikant bevestigt seleniumgist']]);
        $assessment = $this->calculate($version);
        $this->assertSame('3.00', $assessment->score_cap);
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 8, 'reason' => 'Geen uitzondering op selenium'])->assertSessionHasErrors('score');
        $missing = $this->calculate($this->version('4.8', '1.86', basis: 'unknown'));
        $this->assertSame('insufficient_data', $missing->status);
        $this->post("/admin/feed-scanner/assessments/{$missing->id}/correct", ['score' => 8, 'reason' => 'Alleen een referentiecijfer'])->assertSessionHasErrors('score');
    }

    public function test_recalculation_uses_only_active_policies_and_keeps_changes_and_old_overrides(): void
    {
        $original = $this->activate();
        $version = $this->version();
        $this->calculate($version);
        $data = $original->data;
        $data['sugar_starch_caps'][2]['max_score'] = 4;
        $this->post("/admin/feed-scanner/profiles/{$original->profile_id}/rules", $data + ['expected_number' => $original->number])->assertSessionHasNoErrors();
        $oldPolicyCalculation = $this->calculate($version);
        $this->assertSame($original->id, $oldPolicyCalculation->rule_version_id);
        $this->assertSame('5.00', $oldPolicyCalculation->score_cap);
        $this->assertFalse($oldPolicyCalculation->changes['sugar_starch_changed']);
        $this->post("/admin/feed-scanner/assessments/{$oldPolicyCalculation->id}/correct", ['score' => 4.5, 'reason' => 'Beoordeling bij de toen actieve regels'])->assertSessionHasNoErrors();
        $newPolicy = $this->activate();
        $this->post("/admin/feed-scanner/assessments/{$oldPolicyCalculation->id}/correct", ['score' => 5, 'reason' => 'Oude regels gebruiken'])->assertSessionHasErrors('score');
        $new = $this->calculate($version);
        $this->assertSame($newPolicy->id, $new->rule_version_id);
        $this->assertSame('4.00', $new->score_cap);
        $this->assertTrue($new->changes['score_cap_changed']);
        $this->assertFalse($new->changes['sugar_starch_changed']);
        $this->assertSame('4.50', $new->changes['previous_manual_score']);
        $this->assertCount(0, $new->overrides);
        $this->assertCount(1, $oldPolicyCalculation->fresh()->overrides);
        $this->assertSame('5.00', $oldPolicyCalculation->fresh()->score_cap);
    }

    public function test_balancer_and_standard_profiles_and_product_ownership_are_not_interchangeable(): void
    {
        $standard = $this->activate();
        $balancer = $this->activate('balancer');
        $version = $this->version('4', '10', 'balancer');
        $assessment = $this->calculate($version);
        $this->assertSame($balancer->id, $assessment->rule_version_id);
        $this->assertNotSame($standard->id, $assessment->rule_version_id);
        $other = $this->version();
        $this->post("/admin/feed-scanner/products/{$other->product_id}/assess", ['version_id' => $version->id])->assertNotFound();
        $this->assertSame(1, FeedAssessment::count());
    }

    public function test_new_product_versions_keep_old_results_and_are_clearly_previews(): void
    {
        $this->activate();
        $oldVersion = $this->version();
        $old = $this->calculate($oldVersion);
        $product = Product::findOrFail($oldVersion->product_id);
        $data = $oldVersion->data;
        $data['nutrients'][1]['value'] = '20';
        $newVersion = DB::transaction(fn () => FeedCatalog::productDraft($product, $data, $this->admin->id));
        $new = $this->calculate($newVersion);
        $this->assertTrue($new->is_preview);
        $this->assertSame('0.00', $new->automatic_score);
        $this->assertSame('5.00', $old->fresh()->score_cap);
        $this->assertSame($old->id, $new->previous_assessment_id);
        $this->assertSame($oldVersion->id, $new->changes['previous_product_version_id']);
        $this->post("/admin/feed-scanner/assessments/{$old->id}/correct", ['score' => 4, 'reason' => 'Oude versie'])->assertSessionHasErrors('score');
    }

    public function test_editor_cannot_activate_or_correct_and_support_cannot_calculate(): void
    {
        $policy = $this->activate();
        $version = $this->version();
        $assessment = $this->calculate($version);
        $this->admin->update(['role' => 'content_editor']);
        $this->post("/admin/feed-scanner/profiles/{$policy->profile_id}/publish", ['version_id' => $policy->id])->assertForbidden();
        $this->post("/admin/feed-scanner/assessments/{$assessment->id}/correct", ['score' => 4, 'reason' => 'Niet bevoegd'])->assertForbidden();
        $this->admin->update(['role' => 'support']);
        $this->post("/admin/feed-scanner/products/{$version->product_id}/assess", ['version_id' => $version->id])->assertForbidden();
    }

    public function test_failed_audit_rolls_back_the_calculation(): void
    {
        $this->activate();
        $version = $this->version();
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated assessment audit failure'));
        try {
            $this->post("/admin/feed-scanner/products/{$version->product_id}/assess", ['version_id' => $version->id])->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame(0, FeedAssessment::count());
    }
}
