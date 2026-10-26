<?php

namespace App\Support;

use App\Models\FeedAssessment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\ScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedScanReviewCompletion
{
    public function complete(FeedScanSubmission $submission): FeedScanSubmission
    {
        // Completed scans are historical snapshots. Reconcile only pending work
        // for which this submission has an approved version, never another user's.
        if ($submission->status === 'completed' || ! $submission->product_id ||
            ! ProductVersion::where('submission_id', $submission->id)->where('status', 'approved')->exists()) {
            return $submission;
        }

        return DB::transaction(function () use ($submission) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($s->status === 'completed') {
                return $s;
            }
            // Match the scanner's submission -> product -> profile lock order.
            $product = Product::lockForUpdate()->findOrFail($s->product_id);
            $version = ProductVersion::where('product_id', $product->id)->where('submission_id', $s->id)->latest('number')->first();
            if ($product->catalog_status !== 'published' || $version?->status !== 'approved') {
                return $s;
            }
            $profile = DB::table('feed_assessment_profiles')->where('id', $version->profile_id)->lockForUpdate()->first();
            $assessment = $profile?->active_rule_version_id
                ? FeedAssessment::where('product_version_id', $version->id)->where('rule_version_id', $profile->active_rule_version_id)->latest('created_at')->latest('id')->first()
                : null;
            if (! $assessment) {
                try {
                    $assessment = app(FeedAssessments::class)->calculate($product, $version);
                } catch (ValidationException) {
                    // Approved facts remain available when no published scoring rules exist.
                }
            }
            $public = FeedScanResults::make($version, $assessment);
            $scan = ScanResult::create(['user_id' => $s->user_id, 'product_id' => $product->id,
                'product_name' => $public['name'], 'brand' => $public['brand'], 'scanned_at' => now(),
                'score' => $public['score'] === null ? null : (int) round((float) $public['score'] * 10),
                'rating' => 'Gecontroleerd', 'advice' => $public['message']]);
            $s->update(['status' => 'completed', 'product_version_id' => $version->id, 'assessment_id' => $assessment?->id,
                'scan_result_id' => $scan->id, 'public_result' => $public, 'processing_token' => null,
                'processing_started_at' => null, 'error_code' => null, 'message' => 'Je inzending is beoordeeld. De gecontroleerde beoordeling staat klaar.']);
            AuditLogger::log('feed_scan_review_completed', $product, after: ['submission_id' => $s->id,
                'product_version_id' => $version->id, 'scan_result_id' => $scan->id]);

            return $s->fresh();
        });
    }
}
