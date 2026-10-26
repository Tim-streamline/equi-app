<?php

namespace App\Support;

use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\ScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedScanProcessor
{
    public function analyse(FeedScanSubmission $submission): FeedScanSubmission
    {
        $submission = app(FeedScanReviewCompletion::class)->complete($submission->fresh());
        $token = DB::transaction(function () use ($submission) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if (in_array($s->status, ['completed', 'review_required']) || ($s->status === 'processing' && $s->processing_started_at?->gt(now()->subMinutes(3)))) {
                return null;
            }
            if ($s->workflow_version === 2 && (! in_array($s->status, ['needs_photos', 'failed', 'processing']) || ! $s->product_version_id || ! isset($s->photo_ids['front']))) {
                throw ValidationException::withMessages(['photos' => 'Controleer eerst de samenstelling en voeg daarna de analyse en voorkant toe.']);
            }
            if (! isset($s->photo_ids['composition'], $s->photo_ids['analysis'])) {
                throw ValidationException::withMessages(['photos' => 'Voeg een foto van de samenstelling en de analyse toe.']);
            }
            $token = (string) Str::uuid();
            $s->update(['status' => 'processing', 'processing_token' => $token, 'processing_started_at' => now(), 'error_code' => null, 'message' => null]);

            return $token;
        });
        if (! $token) {
            return $submission->fresh();
        }
        try {
            $submission->refresh();
            $images = FeedLabelAttachment::where('submission_id', $submission->id)->whereIn('id', array_values($submission->photo_ids))->get()
                ->map(fn ($a) => ['mime' => $a->mime_type, 'bytes' => Storage::disk($a->disk)->get($a->path), 'purpose' => $a->purpose])->all();
            $extraction = app(FeedLabelReader::class)->read($images);
            DB::transaction(function () use ($submission, $token, $extraction) {
                $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
                if ($s->processing_token !== $token) {
                    return;
                }
                $product = Product::lockForUpdate()->findOrFail($s->product_id);
                // Other submissions have independent drafts. Protect only this
                // submission's versions that a reviewer has edited or reviewed.
                if ($this->hasReviewerChanges($s)) {
                    $s->update(['status' => 'review_required', 'extraction' => $extraction, 'processing_token' => null, 'message' => 'Je foto’s zijn ontvangen. De beheerder beoordeelt dit product al.']);

                    return;
                }
                $data = $this->normalize($extraction, $product, $s->submitted_name);
                $originalProduct = null;
                // The first photo may contain no identity. The front label can later
                // identify a possible revision of an existing product (never a known-score match).
                if ($s->workflow_version === 2 && ! $s->reference_product_id && $product->catalog_status === 'draft' && ! $product->published_version_id && filled($extraction['brand']) && filled($extraction['name'])) {
                    $brand = FeedCompositionMatcher::canonical($extraction['brand']);
                    $name = $this->identityName($extraction['name'], $extraction['brand']);
                    $matches = Product::where('catalog_status', 'published')->whereNotNull('published_version_id')->get()->filter(fn ($p) => FeedCompositionMatcher::canonical($p->brand) === $brand && $this->identityName($p->name, $p->brand) === $name);
                    if ($matches->count() === 1) {
                        $originalProduct = $product;
                        $product = Product::lockForUpdate()->findOrFail($matches->first()->id);
                        if ($product->catalog_status !== 'published' || FeedCompositionMatcher::canonical($product->brand) !== $brand || $this->identityName($product->name, $product->brand) !== $name) {
                            abort(409);
                        }
                        $s->reference_product_id = $product->id;
                        $s->reference_version_id = $product->published_version_id;
                        $product->forceFill(['needs_review' => true])->save();
                    }
                }
                $version = FeedCatalog::productDraft($product, $data, null, false, $s->id);
                foreach (FeedLabelAttachment::where('submission_id', $s->id)->whereIn('id', array_values($s->photo_ids))->get() as $attachment) {
                    $copy = $attachment->replicate();
                    $copy->product_version_id = $version->id;
                    $copy->save();
                }
                if ($originalProduct) {
                    // Retain the original evidence/version as an archived intake shell.
                    $originalProduct->forceFill(['catalog_status' => 'archived', 'needs_review' => false])->save();
                    AuditLogger::log('feed_scan_identity_linked_for_review', $product, after: ['submission_id' => $s->id, 'original_product_id' => $originalProduct->id, 'reference_version_id' => $s->reference_version_id]);
                }
                $assessment = null;
                $missing = $extraction['unreadable_fields'];
                try {
                    $assessment = app(FeedAssessments::class)->calculate($product, $version);
                } catch (ValidationException) {
                    $missing[] = 'De voercategorie of gepubliceerde beoordelingsregels ontbreken. De beheerder controleert dit.';
                }
                $public = FeedScanResults::make($version, $assessment, $missing);
                $scan = ScanResult::create(['user_id' => $s->user_id, 'product_id' => $product->id, 'product_name' => $data['name'], 'brand' => $data['brand'],
                    'scanned_at' => now(), 'score' => $public['score'] === null ? null : (int) round((float) $public['score'] * 10),
                    'rating' => 'Voorlopig', 'advice' => $public['message']]);
                $s->update(['status' => 'completed', 'product_id' => $product->id, 'reference_product_id' => $s->reference_product_id, 'reference_version_id' => $s->reference_version_id, 'product_version_id' => $version->id, 'assessment_id' => $assessment?->id,
                    'scan_result_id' => $scan->id, 'extraction' => $extraction, 'public_result' => $public, 'processing_token' => null,
                    'message' => 'De foto’s zijn uitgelezen. Deze productversie staat ter beoordeling.']);
                AuditLogger::log('feed_scan_processed', $product, after: ['submission_id' => $s->id, 'product_version_id' => $version->id, 'user_id' => $s->user_id]);
            });
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['reader_not_configured', 'reader_unavailable', 'reader_incomplete', 'reader_invalid_output']) ? $e->getMessage() : 'processing_failed';
            FeedScanSubmission::whereKey($submission->id)->where('processing_token', $token)->update(['status' => 'failed', 'processing_token' => null,
                'error_code' => $code, 'message' => $code === 'reader_not_configured' ? 'Je foto’s zijn ontvangen en staan ter beoordeling. Automatisch uitlezen is nog niet geactiveerd.' : 'Je foto’s zijn veilig ontvangen. Automatisch uitlezen is niet gelukt. Probeer opnieuw of wacht op beoordeling.']);
        }

        return $submission->fresh();
    }

    private function hasReviewerChanges(FeedScanSubmission $submission): bool
    {
        return ProductVersion::where('product_id', $submission->product_id)
            ->where(function ($query) use ($submission) {
                $query->where('submission_id', $submission->id)->orWhere('id', $submission->product_version_id)
                    // Older versions with mixed evidence cannot be backfilled unambiguously.
                    ->orWhere(fn ($legacy) => $legacy->whereNull('submission_id')
                        ->whereHas('attachments', fn ($photos) => $photos->where('submission_id', $submission->id)));
            })
            ->where(function ($query) {
                $query->whereNotNull('created_by')->orWhereNotNull('reviewed_by')->orWhere('status', '!=', 'draft')
                    ->orWhereHas('attachments', fn ($attachments) => $attachments->whereNotNull('uploaded_by'));
            })->exists();
    }

    private function identityName(string $name, string $brand): string
    {
        $name = FeedCompositionMatcher::canonical($name);
        $brand = FeedCompositionMatcher::canonical($brand);

        return str_starts_with($name, $brand.' ') ? substr($name, strlen($brand) + 1) : $name;
    }

    private function normalize(array $raw, Product $product, ?string $submittedName): array
    {
        $cards = Ingredient::where('catalog_status', 'published')->whereNotNull('published_version_id')->get();
        $revisions = IngredientRevision::whereIn('id', $cards->pluck('published_version_id'))->get()->keyBy('id');
        $ingredients = [];
        foreach ($raw['ingredients'] as $order => $row) {
            if ($row['confidence'] !== 'high' || empty($row['evidence'])) {
                continue;
            }
            $matches = [];
            foreach ($cards as $card) {
                $revision = $revisions[$card->published_version_id] ?? null;
                if (! $revision || $revision->status !== 'published') {
                    continue;
                }
                $terms = array_merge([['term' => $revision->data['name'], 'kind' => 'exact']], $revision->data['terms'] ?? []);
                foreach ($terms as $term) {
                    if ($term['kind'] === 'exact' && FeedCatalog::normalize($term['term']) === FeedCatalog::normalize($row['label'])) {
                        $matches[$card->id] = $revision;
                    }
                }
            }
            $revision = count($matches) === 1 ? reset($matches) : null;
            $ingredients[] = ['label' => $row['label'], 'order' => $order, 'amount' => $row['amount'], 'evidence' => $row['evidence'],
                'added_selenium' => $row['added_selenium'], 'selenium_form' => $row['selenium_form'],
                'match_status' => $revision ? 'confirmed' : 'unknown', 'ingredient_id' => $revision?->ingredient_id,
                'ingredient_revision_id' => $revision?->id, 'explanation' => $revision?->data['explanation'] ?? null,
                'assessment' => $revision?->data['assessment'] ?? 'unrated', 'exceptions' => $revision?->data['exceptions'] ?? null];
        }

        return ['brand' => $raw['brand'] ?: $product->brand,
            'name' => filled($raw['name']) ? $raw['name'] : (filled($submittedName) ? trim($submittedName) : $product->name), 'barcode' => $raw['barcode'],
            'category' => $raw['category_confidence'] === 'high' && isset(FeedCatalog::CATEGORIES[$raw['category']]) ? $raw['category'] : null,
            'composition_text' => $raw['composition_confidence'] === 'high' ? $raw['composition_text'] : null, 'ingredients' => $ingredients,
            'nutrients' => array_values(array_filter($raw['nutrients'], fn ($n) => $n['confidence'] === 'high' && filled($n['source']) && $n['value'] !== null)),
            'recognition_enabled' => false,
            'source' => 'Gebruikersinzending; automatisch uitgelezen, nog te beoordelen.'];
    }
}
