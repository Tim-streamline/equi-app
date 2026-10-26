<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FeedScanSubmission extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['photo_ids' => 'array', 'extraction' => 'array', 'public_result' => 'array', 'processing_started_at' => 'datetime'];
    }
}
