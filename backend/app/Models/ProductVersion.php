<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVersion extends FeedVersion
{
    public function assessments(): HasMany
    {
        return $this->hasMany(FeedAssessment::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FeedLabelAttachment::class);
    }
}
