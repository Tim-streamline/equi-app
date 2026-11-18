<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProtocolEvaluationQuestion extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function ($question) { $question->id ??= (string) Str::uuid(); });
    }

    protected $fillable = ['label', 'order', 'active'];

    protected function casts(): array
    {
        return ['order' => 'integer', 'active' => 'boolean'];
    }
}
