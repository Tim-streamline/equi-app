<?php

namespace App\Models;

use App\Models\Concerns\HasProtocolAdvicePresentation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'layout', 'icon'])]
class ManagementAdvies extends Model
{
    use HasProtocolAdvicePresentation, HasUuids;

    protected $table = 'management_adviezen';

    public function protocolAdviezen(): HasMany
    {
        return $this->hasMany(ProtocolManagementAdvies::class);
    }
}
