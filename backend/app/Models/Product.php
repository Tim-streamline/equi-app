<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['brand', 'name', 'barcode', 'category'])]
class Product extends Model
{
    use HasUuids;
    public function scans(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }
}
