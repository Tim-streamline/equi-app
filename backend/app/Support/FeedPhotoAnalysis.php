<?php

namespace App\Support;

use App\Models\FeedAnalysisPrompt;
use App\Models\FeedLabelAttachment;
use App\Models\FeedScanSubmission;
use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Independent readers: OCR never supplies or corrects Luna's extraction. */
class FeedPhotoAnalysis
{
    public function analyse(FeedScanSubmission $submission, string $slot, bool $retryFailed = true): void
    {
        $prefix = FeedScanSubmission::photoPrefix($slot);
        $claim = DB::transaction(function () use ($submission, $slot, $prefix, $retryFailed) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            $photo = $s->photo($slot);
            if (! $photo || ($s->{$prefix.'analysis_token'} && $s->{$prefix.'analysis_started_at'}?->gt(now()->subMinutes(2)))) {
                return null;
            }
            $ocr = $s->photoOcr($slot);
            $luna = $s->photoLuna($slot);
            $needsOcr = ! $ocr || ($retryFailed && $ocr['status'] !== 'completed');
            $needsLuna = ! $luna || ($retryFailed && $luna['status'] !== 'completed');
            if (! $needsOcr && ! $needsLuna) {
                return null;
            }
            $token = (string) Str::uuid();
            $s->forceFill([$prefix.'analysis_token' => $token, $prefix.'analysis_started_at' => now()])->save();

            return compact('photo', 'token', 'needsOcr', 'needsLuna');
        });
        if (! $claim) {
            return;
        }
        $photo = $claim['photo'];
        // No database locks are held while either reader works. Persist each result
        // separately so even a terminated provider request cannot discard OCR.
        if ($claim['needsOcr']) {
            $ocr = $this->ocr($photo);
            $this->save($submission, $slot, $claim, fn ($s) => $s->setPhotoOcr($slot, $ocr));
        }
        if ($claim['needsLuna']) {
            $luna = $this->luna($photo);
            $this->save($submission, $slot, $claim, fn ($s) => $s->setPhotoLuna($slot, $luna));
        }
        $this->save($submission, $slot, $claim, fn ($s) => $s->forceFill([$prefix.'analysis_token' => null, $prefix.'analysis_started_at' => null]));
    }

    private function save(FeedScanSubmission $submission, string $slot, array $claim, callable $apply): void
    {
        DB::transaction(function () use ($submission, $slot, $claim, $apply) {
            $s = FeedScanSubmission::lockForUpdate()->findOrFail($submission->id);
            $prefix = FeedScanSubmission::photoPrefix($slot);
            if ($s->{$prefix.'analysis_token'} !== $claim['token'] || $s->{$prefix.'id'} !== $claim['photo']->id || $s->{$prefix.'checksum'} !== $claim['photo']->checksum) {
                return;
            }
            $apply($s);
            $s->save();
        });
    }

    public function ocr(FeedLabelAttachment $photo): array
    {
        try {
            return app(FeedCompositionOcr::class)->read($photo) + ['status' => 'completed', 'processed_at' => now()->toISOString()];
        } catch (\Throwable) {
            return ['text' => '', 'confidence' => 0, 'engine' => 'unavailable', 'status' => 'failed', 'processed_at' => now()->toISOString()];
        }
    }

    public function luna(FeedLabelAttachment $photo): array
    {
        $prompt = FeedAnalysisPrompt::findOrFail($photo->purpose);
        $result = ['type' => $photo->purpose, 'model' => 'gpt-6-luna', 'prompt' => $prompt->prompt, 'prompt_version' => $prompt->version];
        try {
            $data = app(FeedLabelReader::class)->read([
                ['mime' => $photo->mime_type, 'bytes' => Storage::disk($photo->disk)->get($photo->path), 'purpose' => $photo->purpose],
            ], null, $result);
            $usage = isset($data['usage']) ? array_intersect_key($data['usage'], array_flip(['input_tokens', 'output_tokens', 'total_tokens', 'input_tokens_details', 'output_tokens_details'])) : null;
            unset($data['usage']);

            return $result + ['status' => 'completed', 'data' => $data, 'usage' => $usage, 'error_code' => null, 'processed_at' => now()->toISOString()];
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['reader_not_configured', 'reader_unavailable', 'reader_incomplete', 'reader_invalid_output']) ? $e->getMessage() : 'reader_unavailable';

            return $result + ['status' => 'failed', 'data' => null, 'usage' => null, 'error_code' => $code, 'processed_at' => now()->toISOString()];
        }
    }

    public function attachment(FeedLabelAttachment $photo): void
    {
        if (! in_array($photo->mime_type, ['image/jpeg', 'image/png', 'image/webp'])) {
            return;
        }
        $photo->update(['ocr' => $this->ocr($photo)]);
        $photo->update(['luna' => $this->luna($photo)]);
        DB::transaction(function () use ($photo) {
            $source = ProductVersion::findOrFail($photo->product_version_id);
            $product = Product::lockForUpdate()->findOrFail($source->product_id);
            $source->refresh();
            // A concurrent draft may have copied this immutable file while its
            // analysis was pending. Complete that evidence, without changing facts.
            FeedLabelAttachment::whereIn('product_version_id', ProductVersion::where('product_id', $product->id)->select('id'))
                ->where('disk', $photo->disk)->where('path', $photo->path)->where('checksum', $photo->checksum)
                ->where('purpose', $photo->purpose)->whereNull('luna')
                ->update(['ocr' => $photo->ocr, 'luna' => $photo->luna]);
            $version = FeedCatalog::latestProduct($product);
            // Only traverse automatic revisions. A manual edit or review is a barrier.
            if ($photo->luna['status'] !== 'completed' || $product->catalog_status === 'archived'
                || ! $version || ! $this->automaticDescendant($version, $source)) {
                return;
            }
            $fields = match ($photo->purpose) {
                'composition' => ['composition_text', 'ingredients', 'recognition_enabled'],
                'analysis' => ['nutrients'],
                default => ['brand', 'name', 'barcode', 'category'],
            };
            // Combine different analysis types, but do not replace a competing
            // result for this same type just because its request finished earlier.
            if (collect($version->data)->only($fields)->all() !== collect($source->data)->only($fields)->all()) {
                return;
            }
            $raw = $photo->luna['data'];
            $extracted = app(FeedScanProcessor::class)->normalize($raw, $product, null);
            $data = $version->data;
            if ($photo->purpose === 'composition') {
                if (filled($extracted['composition_text'])) {
                    $data['composition_text'] = $extracted['composition_text'];
                }
                if ($extracted['ingredients']) {
                    $data['ingredients'] = $extracted['ingredients'];
                }
                $data['recognition_enabled'] = false;
            } elseif ($photo->purpose === 'analysis') {
                if ($extracted['nutrients']) {
                    $data['nutrients'] = $extracted['nutrients'];
                }
            } else {
                foreach (['brand', 'name', 'barcode', 'category'] as $field) {
                    if (filled($raw[$field]) && ($field !== 'category' || $raw['category_confidence'] === 'high') && $raw[$field] !== 'unknown') {
                        $data[$field] = $raw[$field];
                    }
                }
            }
            if ($data === $version->data) {
                return;
            }
            $data['source'] = 'Etiketfoto, uitgelezen met GPT-6 Luna; nog te controleren.';
            $draft = FeedCatalog::productDraft($product, $data, $photo->uploaded_by, sourceVersion: $version);
            $draft->update(['photo_analysis_parent_id' => $version->id]);
            $product->forceFill(['needs_review' => true])->save();
            AuditLogger::log('feed_photo_extracted', $product, after: ['attachment_id' => $photo->id, 'version_id' => $draft->id]);
        });
    }

    private function automaticDescendant(ProductVersion $version, ProductVersion $source): bool
    {
        while (in_array($version->status, ['draft', 'needs_info']) && ! $version->superseded_by_product_id) {
            if ($version->id === $source->id) {
                return true;
            }
            if (! $version->photo_analysis_parent_id) {
                return false;
            }
            $version = ProductVersion::where('product_id', $source->product_id)->find($version->photo_analysis_parent_id);
            if (! $version) {
                return false;
            }
        }

        return false;
    }

    /** Merge only the fields owned by each analysis type, never OCR or another photo's guesses. */
    public function extraction(FeedScanSubmission $s): array
    {
        $results = [];
        foreach ($s->currentPhotos() as $slot => $photo) {
            $luna = $s->photoLuna($slot);
            if (($luna['status'] ?? null) !== 'completed') {
                throw new \RuntimeException($luna['error_code'] ?? 'reader_incomplete');
            }
            $results[$slot] = $luna['data'];
        }
        $composition = $results['composition'];
        $analysis = $results['analysis'];
        $identity = $results['front'] ?? $composition;

        return [
            'brand' => $identity['brand'], 'name' => $identity['name'], 'barcode' => $identity['barcode'],
            'category' => $identity['category'], 'category_confidence' => $identity['category_confidence'],
            'composition_text' => $composition['composition_text'], 'composition_confidence' => $composition['composition_confidence'],
            'ingredients' => $composition['ingredients'], 'nutrients' => $analysis['nutrients'],
            'unreadable_fields' => array_values(array_unique(array_merge(...array_column($results, 'unreadable_fields')))),
            'reader_model' => 'gpt-6-luna', 'reader_version' => 2,
        ];
    }
}
