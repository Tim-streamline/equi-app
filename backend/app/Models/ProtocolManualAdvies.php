<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Advice written for one protocol in the editor, outside the measure library. */
#[Fillable(['protocol_id', 'category', 'title', 'description', 'management_category', 'action', 'applies_to', 'sort_order'])]
class ProtocolManualAdvies extends Model
{
    use HasUuids;

    public const CATEGORIES = ['voeding', 'management', 'beweging'];

    public const MANAGEMENT_CATEGORIES = ['environment', 'care', 'monitoring'];

    protected $table = 'protocol_manual_adviezen';

    protected $casts = ['sort_order' => 'integer'];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function phases(): BelongsToMany
    {
        return $this->belongsToMany(ProtocolPhase::class, 'protocol_manual_advies_phase', 'protocol_manual_advies_id', 'protocol_phase_id')
            ->orderBy('protocol_phases.order');
    }
}
