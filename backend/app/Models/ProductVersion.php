<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVersion extends FeedVersion
{
    public function attachments(): HasMany
    {
        return $this->hasMany(FeedLabelAttachment::class);
    }
}
