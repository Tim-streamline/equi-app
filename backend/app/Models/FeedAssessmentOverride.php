<?php

namespace App\Models;

class FeedAssessmentOverride extends FeedAssessmentRecord
{
    protected function casts(): array
    {
        return ['corrected_score' => 'decimal:2'];
    }
}
