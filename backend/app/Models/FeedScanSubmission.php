<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedScanSubmission extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['photo_ids' => 'array', 'extraction' => 'array', 'public_result' => 'array', 'recognition' => 'array', 'workflow_version' => 'integer', 'processing_started_at' => 'datetime'];
    }
}
