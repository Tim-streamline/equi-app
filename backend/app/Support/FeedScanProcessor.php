<?php

namespace App\Support;

use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedScanProcessor
{
    public function analyse(FeedScanSubmission $submission): FeedScanSubmission
    {
        $token = DB::transaction(function () use ($submission) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            if (in_array($s->status, ['completed', 'review_required']) || ($s->status === 'processing' && $s->processing_started_at?->gt(now()->subMinutes(3)))) {
                return null;
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
                // A reviewer may already have edited the submission while AI was reading.
                if (FeedCatalog::latestProduct($product)?->id !== $s->product_version_id) {
                    $s->update(['status' => 'review_required', 'extraction' => $extraction, 'message' => 'Je foto’s zijn ontvangen. De beheerder beoordeelt dit product al.']);

                    return;
                }
                $data = $this->normalize($extraction, $product);
                $version = FeedCatalog::productDraft($product, $data, null);
                $assessment = null;
                $missing = $extraction['unreadable_fields'];
                try {
                    $assessment = app(FeedAssessments::class)->calculate($product, $version);
                } catch (ValidationException) {
                    $missing[] = 'De voercategorie of gepubliceerde beoordelingsregels ontbreken. De beheerder controleert dit.';
                }
                $result = $assessment?->result ?? ['automatic_score' => null, 'score_cap' => null, 'findings' => [], 'missing_data' => []];
                $missing = array_merge($missing, $result['missing_data']);
                foreach ($data['ingredients'] as $i) {
                    if ($i['match_status'] !== 'confirmed') {
                        $missing[] = 'Nog geen eenduidige gepubliceerde kenniskaart voor: '.$i['label'];
                    }
                }
                $public = ['name' => $data['name'], 'brand' => $data['brand'], 'provisional' => true,
                    'score' => $result['automatic_score'], 'score_cap' => $result['score_cap'],
                    'findings' => array_merge($result['findings'], array_map(fn ($c) => ['message' => $c['message']], $result['applied_caps'] ?? [])),
                    'missing_data' => array_values(array_unique($missing)), 'ingredients' => $data['ingredients'], 'nutrients' => $data['nutrients'],
                    'product_version_id' => $version->id, 'rule_version_id' => $assessment?->rule_version_id,
                    'calibration_pending' => $result['automatic_score'] === null,
                    'message' => $result['automatic_score'] === null ? 'Voorlopige bevindingen. De volledige puntenweging is nog niet gekalibreerd; er wordt geen cijfer geschat. Dit product staat ter beoordeling.' : 'Voorlopige beoordeling op basis van het etiket en de gepubliceerde regels. Dit product staat ter beoordeling.'];
                $scan = ScanResult::create(['user_id' => $s->user_id, 'product_id' => $product->id, 'product_name' => $data['name'], 'brand' => $data['brand'],
                    'scanned_at' => now(), 'score' => $public['score'] === null ? null : (int) round((float) $public['score'] * 10),
                    'rating' => 'Voorlopig', 'advice' => $public['message']]);
                $s->update(['status' => 'completed', 'product_version_id' => $version->id, 'assessment_id' => $assessment?->id,
                    'scan_result_id' => $scan->id, 'extraction' => $extraction, 'public_result' => $public, 'processing_token' => null]);
                AuditLogger::log('feed_scan_processed', $product, after: ['submission_id' => $s->id, 'product_version_id' => $version->id, 'user_id' => $s->user_id]);
            });
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['reader_not_configured', 'reader_unavailable', 'reader_incomplete', 'reader_invalid_output']) ? $e->getMessage() : 'processing_failed';
            FeedScanSubmission::whereKey($submission->id)->where('processing_token', $token)->update(['status' => 'failed', 'processing_token' => null,
                'error_code' => $code, 'message' => $code === 'reader_not_configured' ? 'Je foto’s zijn ontvangen en staan ter beoordeling. Automatisch uitlezen is nog niet geactiveerd.' : 'Je foto’s zijn veilig ontvangen. Automatisch uitlezen is niet gelukt. Probeer opnieuw of wacht op beoordeling.']);
        }

        return $submission->fresh();
    }

    private function normalize(array $raw, Product $product): array
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

        return ['brand' => $raw['brand'] ?: $product->brand, 'name' => $raw['name'] ?: $product->name, 'barcode' => $raw['barcode'],
            'category' => $raw['category_confidence'] === 'high' && isset(FeedCatalog::CATEGORIES[$raw['category']]) ? $raw['category'] : null,
            'composition_text' => $raw['composition_confidence'] === 'high' ? $raw['composition_text'] : null, 'ingredients' => $ingredients,
            'nutrients' => array_values(array_filter($raw['nutrients'], fn ($n) => $n['confidence'] === 'high' && filled($n['source']) && $n['value'] !== null)),
            'source' => 'Gebruikersinzending; automatisch uitgelezen, nog te beoordelen.'];
    }
}
