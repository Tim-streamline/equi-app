<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class FeedScanSubmission extends Model
{
    use HasUuids;

    public const PHOTO_SLOTS = ['composition' => 'ingredients', 'analysis' => 'nutrition', 'front' => 'product'];

    public const PHOTO_FIELDS = ['id', 'disk', 'path', 'original_name', 'mime_type', 'checksum', 'size_bytes', 'uploaded_at'];

    public const OCR_FIELDS = ['text', 'confidence', 'words', 'low_confidence_words', 'engine', 'status', 'processed_at'];

    public const LUNA_FIELDS = ['status', 'data', 'model', 'prompt', 'prompt_version', 'usage', 'error_code', 'processed_at'];

    public const RECOGNITION_FIELDS = ['decision', 'photo_id', 'product_id', 'version_id', 'name', 'brand', 'composition_text', 'reference_composition', 'owner_reported_change'];

    protected $guarded = [];

    protected $appends = ['photo_ids', 'recognition', 'photos', 'suggested_name'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attachments(): HasMany
    {
        // Historical/product-version evidence only. Active scan photos live on this row.
        return $this->hasMany(FeedLabelAttachment::class, 'submission_id');
    }

    public static function photoPrefix(string $slot): string
    {
        if (! isset(self::PHOTO_SLOTS[$slot])) {
            throw new \InvalidArgumentException('Unknown scan photo slot');
        }

        return self::PHOTO_SLOTS[$slot].'_photo_';
    }

    /** Unsaved attachment value object for the existing file/OCR readers. */
    public function photo(string $slot): ?FeedLabelAttachment
    {
        $prefix = self::photoPrefix($slot);
        if (! $this->{$prefix.'id'}) {
            return null;
        }
        $data = ['submission_id' => $this->id, 'submitted_by' => $this->user_id, 'purpose' => $slot === 'front' ? 'packaging' : $slot];
        foreach (self::PHOTO_FIELDS as $field) {
            if ($field !== 'uploaded_at') {
                $data[$field] = $this->{$prefix.$field};
            }
        }

        return new FeedLabelAttachment($data + ['ocr' => $this->photoOcr($slot), 'luna' => $this->photoLuna($slot)]);
    }

    public function currentPhotos(): Collection
    {
        return collect(array_keys(self::PHOTO_SLOTS))->mapWithKeys(fn ($slot) => [$slot => $this->photo($slot)])->filter();
    }

    public function getPhotoIdsAttribute(): array
    {
        return $this->currentPhotos()->map(fn ($photo) => $photo->id)->all();
    }

    public function getPhotosAttribute(): array
    {
        return $this->currentPhotos()->map(function ($photo, $slot) {
            $prefix = self::photoPrefix($slot);
            $ocr = $this->{$prefix.'ocr_status'} ? collect(self::OCR_FIELDS)->mapWithKeys(fn ($field) => [$field => $this->{$prefix.'ocr_'.$field}])->all() : null;

            return ['id' => $photo->id, 'slot' => $slot, 'purpose' => $photo->purpose, 'original_name' => $photo->original_name, 'ocr' => $ocr];
        })->values()->all();
    }

    public function getRecognitionAttribute(): ?array
    {
        if (! $this->recognition_decision) {
            return null;
        }
        $data = collect(self::RECOGNITION_FIELDS)->mapWithKeys(fn ($field) => [$field => $this->{'recognition_'.$field}])->all();
        if ($this->recognition_photo_id === $this->ingredients_photo_id && $this->ingredients_photo_ocr_status) {
            $data['ocr'] = collect(self::OCR_FIELDS)->mapWithKeys(fn ($field) => [$field => $this->{'ingredients_photo_ocr_'.$field}])->all();
        }

        return $data;
    }

    /** Public name suggestion from the current front photo; keep the full analysis private. */
    public function getSuggestedNameAttribute(): ?string
    {
        $luna = $this->photoLuna('front');
        if (! $this->product_photo_id || $this->reference_product_id || $this->recognition_decision === 'candidate' || ($luna['status'] ?? null) !== 'completed') {
            return null;
        }
        $name = trim($luna['data']['name'] ?? '');
        if ($name === '' || mb_strtolower($name) === 'unknown') {
            return null;
        }
        $brand = trim($luna['data']['brand'] ?? '');
        if ($brand !== '' && mb_strtolower($brand) !== 'unknown' && mb_strtolower($name) !== mb_strtolower($brand)
            && ! str_starts_with(mb_strtolower($name), mb_strtolower($brand).' ')) {
            $name = $brand.' '.$name;
        }

        return mb_substr($name, 0, 255);
    }

    public function setRecognitionData(?array $recognition): void
    {
        foreach (self::RECOGNITION_FIELDS as $field) {
            $this->{'recognition_'.$field} = $recognition[$field] ?? ($field === 'owner_reported_change' ? false : null);
        }
    }

    public function setPhotoOcr(string $slot, ?array $ocr): void
    {
        $prefix = self::photoPrefix($slot).'ocr_';
        foreach (self::OCR_FIELDS as $field) {
            $this->{$prefix.$field} = $ocr[$field] ?? null;
        }
        if ($ocr !== null) {
            $this->{$prefix.'status'} = ($ocr['engine'] ?? '') === 'unavailable' ? 'failed' : 'completed';
            $this->{$prefix.'processed_at'} = now();
        }
    }

    public function photoOcr(string $slot): ?array
    {
        $prefix = self::photoPrefix($slot).'ocr_';

        return $this->{$prefix.'status'} ? collect(self::OCR_FIELDS)->mapWithKeys(fn ($field) => [$field => $this->{$prefix.$field}])->all() : null;
    }

    public function photoLuna(string $slot): ?array
    {
        $prefix = self::photoPrefix($slot).'luna_';

        return $this->{$prefix.'status'} ? collect(self::LUNA_FIELDS)->mapWithKeys(fn ($field) => [$field => $this->{$prefix.$field}])->all() : null;
    }

    public function setPhotoLuna(string $slot, ?array $luna): void
    {
        $prefix = self::photoPrefix($slot).'luna_';
        foreach (self::LUNA_FIELDS as $field) {
            $this->{$prefix.$field} = $luna[$field] ?? null;
        }
    }

    public function adminPhotos(): array
    {
        return array_map(fn ($photo) => $photo + ['luna' => $this->photoLuna($photo['slot'])], $this->photos);
    }

    public function getHidden(): array
    {
        $fields = ['photo_upload_count', 'photo_uploaded_bytes'];
        foreach (self::PHOTO_SLOTS as $prefix) {
            foreach ([...self::PHOTO_FIELDS, 'analysis_token', 'analysis_started_at', ...array_map(fn ($f) => 'luna_'.$f, self::LUNA_FIELDS), ...array_map(fn ($f) => 'ocr_'.$f, self::OCR_FIELDS)] as $field) {
                $fields[] = $prefix.'_photo_'.$field;
            }
        }

        return [...parent::getHidden(), ...$fields, ...array_map(fn ($f) => 'recognition_'.$f, self::RECOGNITION_FIELDS)];
    }

    protected function casts(): array
    {
        $casts = ['extraction' => 'array', 'public_result' => 'array', 'workflow_version' => 'integer', 'processing_started_at' => 'datetime', 'recognition_owner_reported_change' => 'boolean'];
        foreach (self::PHOTO_SLOTS as $prefix) {
            foreach (['analysis_started_at' => 'datetime', 'luna_data' => 'array', 'luna_usage' => 'array', 'luna_prompt_version' => 'integer', 'luna_processed_at' => 'datetime', 'ocr_confidence' => 'float', 'ocr_words' => 'integer', 'ocr_low_confidence_words' => 'integer', 'uploaded_at' => 'datetime', 'ocr_processed_at' => 'datetime', 'size_bytes' => 'integer'] as $field => $cast) {
                $casts[$prefix.'_photo_'.$field] = $cast;
            }
        }

        return $casts;
    }
}
