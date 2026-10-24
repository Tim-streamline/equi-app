<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedAssessment extends FeedAssessmentRecord
{
    protected function casts(): array
    {
        return ['automatic_score' => 'decimal:2', 'score_cap' => 'decimal:2', 'is_preview' => 'boolean',
            'input_snapshot' => 'array', 'rule_snapshot' => 'array', 'result' => 'array', 'changes' => 'array'];
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FeedAssessmentOverride::class, 'assessment_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(FeedRuleVersion::class, 'rule_version_id');
    }
}
