<?php

namespace App\Support;

use App\Models\FeedAssessment;
use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\ScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedScanRecognition
{
    public function check(FeedScanSubmission $submission): FeedScanSubmission
    {
        $token = DB::transaction(function () use ($submission) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($s->workflow_version !== 2 || $s->product_version_id || in_array($s->status, ['candidate', 'needs_photos', 'completed', 'review_required', 'processing']) ||
                ($s->status === 'checking' && $s->processing_started_at?->gt(now()->subMinutes(2)))) {
                return null;
            }
            if (empty($s->photo_ids['composition'])) {
                throw ValidationException::withMessages(['photos' => 'Voeg eerst een foto van de samenstelling toe.']);
            }
            $token = (string) Str::uuid();
            $s->update(['status' => 'checking', 'processing_token' => $token, 'processing_started_at' => now()]);

            return $token;
        });
        if (! $token) {
            return $submission->fresh();
        }
        try {
            $submission->refresh();
            $ocr = app(FeedCompositionOcr::class)->read(FeedLabelAttachment::findOrFail($submission->photo_ids['composition']));
            $match = app(FeedCompositionMatcher::class)->match($ocr, $submission->reference_product_id);
        } catch (\Throwable) {
            $ocr = ['text' => '', 'confidence' => 0, 'engine' => 'unavailable'];
            $match = ['decision' => 'uncertain', 'product_id' => $submission->reference_product_id, 'version_id' => null, 'composition_text' => null];
        }
        DB::transaction(function () use ($submission, $token, $ocr, $match) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($s->processing_token !== $token) {
                return;
            }
            $s->update(['recognition' => $match + ['ocr' => $ocr, 'photo_id' => $s->photo_ids['composition']], 'processing_token' => null,
                'reference_product_id' => $match['product_id'], 'reference_version_id' => $match['version_id'],
                'status' => $match['decision'] === 'candidate' ? 'candidate' : 'needs_photos',
                'message' => $match['decision'] === 'candidate' ? 'Deze samenstelling herkennen we. Controleer of dit jouw voer is.' :
                    ($match['decision'] === 'changed' ? 'De samenstelling lijkt gewijzigd. Voeg de analyse en voorkant toe, zodat we de nieuwe versie kunnen beoordelen.' : 'We herkennen deze samenstelling nog niet met voldoende zekerheid. Voeg de analyse en voorkant toe.')]);
            if ($s->status === 'needs_photos') {
                $this->ensureDraft($s);
            }
        });

        return $submission->fresh();
    }

    public function morePhotos(FeedScanSubmission $submission, bool $changed): FeedScanSubmission
    {
        return DB::transaction(function () use ($submission, $changed) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            abort_unless($s->workflow_version === 2 && in_array($s->status, ['candidate', 'needs_photos']), 409);
            if ($s->status === 'candidate') {
                if (! $changed) {
                    $s->reference_product_id = $s->reference_version_id = null;
                }
                $s->recognition = array_merge($s->recognition ?? [], ['decision' => $changed ? 'changed' : 'unknown', 'owner_reported_change' => $changed]);
                $s->status = 'needs_photos';
                $s->message = 'Voeg de analyse en de voorkant toe. Wij controleren de samenstelling en etiketgegevens.';
                $s->save();
                $this->ensureDraft($s);
            }

            return $s->fresh();
        });
    }

    public function confirm(FeedScanSubmission $submission): FeedScanSubmission
    {
        return DB::transaction(function () use ($submission) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if ($s->status === 'completed') {
                return $s;
            }
            abort_unless($s->status === 'candidate' && $s->recognition['photo_id'] === ($s->photo_ids['composition'] ?? null), 409);
            $product = Product::lockForUpdate()->find($s->reference_product_id);
            $version = $product ? ProductVersion::find($product->published_version_id) : null;
            if (! $product || $product->catalog_status !== 'published' || ! $version || $version->status !== 'approved' || $version->id !== $s->reference_version_id) {
                $s->update(['status' => 'needs_photos', 'message' => 'De gecontroleerde productversie is ondertussen gewijzigd. Voeg de analyse en voorkant toe.']);
                $this->ensureDraft($s);

                return $s->fresh();
            }
            $profile = DB::table('feed_assessment_profiles')->where('id', $version->profile_id)->lockForUpdate()->first();
            $assessment = $profile?->active_rule_version_id ? FeedAssessment::where('product_version_id', $version->id)->where('rule_version_id', $profile->active_rule_version_id)->latest('created_at')->latest('id')->first() : null;
            if (! $assessment) {
                try {
                    $assessment = app(FeedAssessments::class)->calculate($product, $version);
                } catch (ValidationException) { /* No AI fallback for a known recipe. */
                }
            }
            $public = FeedScanResults::make($version, $assessment);
            $scan = ScanResult::create(['user_id' => $s->user_id, 'product_id' => $product->id, 'product_name' => $public['name'], 'brand' => $public['brand'],
                'scanned_at' => now(), 'score' => $public['score'] === null ? null : (int) round((float) $public['score'] * 10), 'rating' => 'Gecontroleerd', 'advice' => $public['message']]);
            $s->update(['status' => 'completed', 'product_id' => $product->id, 'product_version_id' => $version->id, 'assessment_id' => $assessment?->id,
                'scan_result_id' => $scan->id, 'public_result' => $public, 'message' => 'Bestaande beoordeling geopend, zonder AI-aanroep.']);
            AuditLogger::log('feed_scan_known_recipe', $product, after: ['submission_id' => $s->id, 'product_version_id' => $version->id]);

            return $s->fresh();
        });
    }

    /** Own draft for this submission. Never replace the published recipe or borrow its analysis values. */
    private function ensureDraft(FeedScanSubmission $s): void
    {
        if ($s->product_version_id) {
            return;
        }
        $product = $s->reference_product_id ? Product::whereNotIn('catalog_status', ['archived', 'removed'])->lockForUpdate()->find($s->reference_product_id) : null;
        if (! $product) {
            $product = Product::create(['brand' => 'Nog te controleren', 'name' => $s->submitted_name ?: 'Onbekend voer', 'needs_review' => true]);
            $product->forceFill(['catalog_status' => 'draft'])->save();
        } else {
            $product->forceFill(['needs_review' => true])->save();
            if (! $s->reference_version_id) {
                $s->reference_version_id = $product->published_version_id ?? FeedCatalog::latestProduct($product)?->id;
            }
        }
        $data = ['brand' => $product->brand, 'name' => $s->submitted_name ?: $product->name, 'barcode' => null, 'category' => null,
            'composition_text' => null, 'ingredients' => [], 'nutrients' => [], 'recognition_enabled' => false,
            'source' => $s->reference_product_id ? 'Mogelijke receptwijziging uit gebruikersinzending; eerdere productversie blijft bewaard.' : 'Nieuwe gebruikersinzending; wacht op aanvullende etiketfoto’s.'];
        $version = FeedCatalog::productDraft($product, $data, null, false, $s->id);
        $s->update(['product_id' => $product->id, 'product_version_id' => $version->id, 'reference_version_id' => $s->reference_version_id]);
        FeedLabelAttachment::where('submission_id', $s->id)->whereNull('product_version_id')->update(['product_version_id' => $version->id]);
        AuditLogger::log('feed_recipe_review_requested', $product, after: ['submission_id' => $s->id, 'reference_version_id' => $s->reference_version_id, 'version_id' => $version->id]);
    }
}
