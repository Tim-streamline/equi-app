<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

trait HasProtocolAdvicePresentation
{
    protected function initializeHasProtocolAdvicePresentation(): void
    {
        $this->append('icon_url');
    }

    protected function iconUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->icon
            ? Storage::disk('public')->url($this->icon)
            : null);
    }
}
