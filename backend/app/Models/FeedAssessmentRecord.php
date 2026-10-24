<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class FeedAssessmentRecord extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Maak een nieuwe beoordeling of correctie. De historie is onveranderlijk.'));
        static::deleting(fn () => throw new \LogicException('Beoordelingshistorie kan niet worden verwijderd.'));
    }
}
