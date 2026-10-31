<?php

use App\Models\IntakeField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        IntakeField::where('key', 'wegjagen-frequentie')
            ->whereHas('section', fn ($query) => $query->where('key', 'sociaal'))
            ->get()->each(function (IntakeField $field) {
                $options = array_values(array_filter($field->options ?? [], fn ($option) => $option !== 'Nooit'));
                $field->update(['options' => ['Nooit', ...$options]]);
            });

        $guard = ['sociaal.wegjagen-frequentie' => 'Nooit'];
        IntakeField::whereIn('key', [
            'wegjagen-gedrag', 'wegjagen-gedrag-anders',
            'wegjagen-reactie', 'wegjagen-reactie-anders',
            'wegjagen-momenten', 'wegjagen-momenten-anders',
        ])->whereHas('section', fn ($query) => $query->where('key', 'sociaal'))
            ->get()->each(function (IntakeField $field) use ($guard) {
                $condition = $field->show_if ?? [];
                if (($condition['$not'] ?? null) === $guard
                    || in_array(['$not' => $guard], $condition['$all'] ?? [], true)) {
                    return;
                }

                // Retain all existing visibility conditions, including admin changes.
                $field->update(['show_if' => isset($condition['$not'])
                    ? ['$all' => [$condition, ['$not' => $guard]]]
                    : ['$not' => $guard, ...$condition]]);
            });
    }

    public function down(): void
    {
        // Retain the new answer option and conditions for saved responses.
    }
};
