<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class FeedVersion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'published_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->isDirty(['data', 'number', 'content_hash', 'profile_id', 'ingredient_id', 'product_id'])) {
                throw new \LogicException('Maak een nieuwe versie om de inhoud te wijzigen.');
            }
            if (in_array($version->getOriginal('status'), ['published', 'approved', 'legacy'], true)) {
                throw new \LogicException('Deze versie is vastgelegd en kan niet worden gewijzigd.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Versiehistorie kan niet worden verwijderd.'));
    }
}
