<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\FeedAssessment;
use App\Models\FeedAssessmentOverride;
use App\Models\FeedRuleVersion;
use App\Models\Product;
use App\Models\ProductVersion;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedAssessments
{
    public function calculate(Product $product, ProductVersion $version, AdminUser $actor): FeedAssessment
    {
        return DB::transaction(function () use ($product, $version, $actor) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            abort_if($product->catalog_status === 'archived', 409, 'Dit product is gearchiveerd.');
            abort_unless($version->product_id === $product->id, 404);
            // Same product -> profile lock order as catalog product approval.
            $profile = DB::table('feed_assessment_profiles')->where('id', $version->profile_id)->lockForUpdate()->first();
            if (! $profile?->active_rule_version_id) {
                throw ValidationException::withMessages(['assessment' => 'Activeer eerst de scoreplafonds bij Beoordelingsregels & kennis voor dit productprofiel.']);
            }
            $rules = FeedRuleVersion::findOrFail($profile->active_rule_version_id);
            if ($rules->status !== 'published' || $rules->profile_id !== $profile->id || $rules->schema_version !== FeedPolicy::SCHEMA_VERSION) {
                throw ValidationException::withMessages(['assessment' => 'Er is geen geldige actieve regelversie voor dit profiel.']);
            }
            FeedPolicy::validate($rules->data);
            $result = (new FeedAssessmentEngine)->evaluate($version->data, $rules->data);
            $previous = FeedAssessment::whereIn('product_version_id', ProductVersion::where('product_id', $product->id)->select('id'))
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $changes = $previous ? [
                'previous_product_version_id' => $previous->product_version_id, 'previous_rule_version_id' => $previous->rule_version_id,
                'previous_automatic_score' => $previous->automatic_score, 'previous_score_cap' => $previous->score_cap,
                'previous_sugar_starch' => $previous->result['sugar_starch'],
                'automatic_score_changed' => ! $this->sameScore($previous->automatic_score, $result['automatic_score']),
                'score_cap_changed' => ! $this->sameScore($previous->score_cap, $result['score_cap']),
                // PostgreSQL JSONB can reorder object keys without changing the interval.
                'sugar_starch_changed' => $previous->result['sugar_starch'] != $result['sugar_starch'],
                'previous_manual_score' => $previous->overrides()->first()?->corrected_score,
            ] : null;
            $assessment = FeedAssessment::create(['product_version_id' => $version->id, 'rule_version_id' => $rules->id,
                'engine_version' => FeedAssessmentEngine::VERSION, 'scope' => 'product', 'horse_id' => null,
                'is_preview' => $version->status !== 'approved', 'status' => $result['status'],
                'automatic_score' => $result['automatic_score'], 'score_cap' => $result['score_cap'],
                'input_snapshot' => $version->data, 'input_hash' => hash('sha256', json_encode($version->data, JSON_THROW_ON_ERROR)),
                'rule_snapshot' => $rules->data, 'result' => $result, 'changes' => $changes, 'previous_assessment_id' => $previous?->id,
                'created_by' => $actor->id]);
            AuditLogger::log('feed_assessment_created', $assessment, after: ['product_version_id' => $version->id, 'rule_version_id' => $rules->id,
                'automatic_score' => $result['automatic_score'], 'score_cap' => $result['score_cap']]);

            return $assessment;
        });
    }

    public function correct(FeedAssessment $assessment, string $score, string $reason, AdminUser $actor): FeedAssessmentOverride
    {
        return DB::transaction(function () use ($assessment, $score, $reason, $actor) {
            $version = ProductVersion::findOrFail($assessment->product_version_id);
            $product = Product::lockForUpdate()->findOrFail($version->product_id);
            $profile = DB::table('feed_assessment_profiles')->where('id', $version->profile_id)->lockForUpdate()->first();
            $latest = FeedAssessment::where('product_version_id', $version->id)->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($product->catalog_status === 'archived' || FeedCatalog::latestProduct($product)?->id !== $version->id || $latest?->id !== $assessment->id || $profile?->active_rule_version_id !== $assessment->rule_version_id) {
                throw ValidationException::withMessages(['score' => 'Bereken eerst de nieuwste productversie met de actieve regels. Een oude beoordeling kan niet worden aangepast.']);
            }
            if ($assessment->automatic_score === null && $assessment->result['missing_data'] !== []) {
                throw ValidationException::withMessages(['score' => 'Vul eerst de ontbrekende of onzekere gegevens aan en bereken opnieuw.']);
            }
            if ($assessment->score_cap !== null && BigRational::of($score)->isGreaterThan($assessment->score_cap)) {
                throw ValidationException::withMessages(['score' => 'Deze score overschrijdt het geldende plafond van '.$assessment->score_cap.'/10.']);
            }
            $override = FeedAssessmentOverride::create(['assessment_id' => $assessment->id, 'corrected_score' => $score,
                'reason' => $reason, 'created_by' => $actor->id, 'actor_name' => $actor->name]);
            AuditLogger::log('feed_assessment_corrected', $override, after: ['assessment_id' => $assessment->id, 'automatic_score' => $assessment->automatic_score,
                'corrected_score' => $score], reason: $reason);

            return $override;
        });
    }

    private function sameScore(?string $a, ?string $b): bool
    {
        return $a === null || $b === null ? $a === $b : BigRational::of($a)->isEqualTo($b);
    }
}
