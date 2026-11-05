<?php

namespace App\Models;

use App\Enums\DoseBuildupType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['id', 'name', 'type', 'start_dose_ratio', 'days', 'schedule'])]
class DoseBuildup extends Model
{
    use HasUuids;

    public const NONE_ID = 'f5907240-5f13-4f87-a032-000000000001';

    public const SEVEN_DAYS_ID = 'f5907240-5f13-4f87-a032-000000000007';

    public const TWO_DAYS_HALF_ID = 'f5907240-5f13-4f87-a032-000000000003';

    public const DOSING_FIELDS = ['type', 'start_dose_ratio', 'days', 'schedule'];

    protected static function booted(): void
    {
        static::updating(function (self $buildup): void {
            foreach (self::DOSING_FIELDS as $field) {
                if ($buildup->isDirty($field)) {
                    throw ValidationException::withMessages([$field => 'De opbouwinstellingen staan vast. Dupliceer de dosisopbouw om een ander schema te maken.']);
                }
            }
        });
    }

    public function matchesDosingSettings(array $definition): bool
    {
        return $this->only(self::DOSING_FIELDS) == (new self($definition))->only(self::DOSING_FIELDS);
    }

    protected function casts(): array
    {
        return ['type' => DoseBuildupType::class, 'start_dose_ratio' => 'float', 'days' => 'integer', 'schedule' => 'array'];
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(Supplement::class);
    }

    public function snapshot(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type->value,
            'start_dose_ratio' => $this->start_dose_ratio, 'days' => $this->days, 'schedule' => $this->schedule];
    }
}
