<?php

use App\Models\IntakeField;
use App\Support\IntakeConditions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const FREQUENCY = 'sociaal.wegjagen-frequentie';

    /** Earlier answers saying the horse itself is chased; 'Nooit' does not hide the details then. */
    private const CHASED = [
        ['sociaal.sociaal-spanning' => ['Mijn paard wordt regelmatig weggejaagd / gepest']],
        ['sociaal.sociaal-positie' => ['…zelf wordt weggejaagd', '…soms wegjaagt en soms wordt weggejaagd']],
        ['huisvesting.rust-weggejaagd' => ['Af en toe', 'Regelmatig']],
    ];

    /** Repeatable: also upgrades the guard written by the first version of this migration. */
    public function up(): void
    {
        $never = 'Nooit';
        IntakeField::where('key', 'wegjagen-frequentie')
            ->whereHas('section', fn ($query) => $query->where('key', 'sociaal'))
            ->get()->each(function (IntakeField $field) use (&$never) {
                $options = $field->options ?? [];
                // Reuse a 'never' option an administrator already added, in whatever spelling.
                $existing = array_values(array_filter($options, fn ($option) => is_string($option) && preg_match('/^nooit\b/iu', trim($option))));
                if ($existing) {
                    $never = $existing[0];

                    return;
                }
                $field->update(['options' => ['Nooit', ...$options]]);
            });

        $guard = ['$not' => ['$all' => [[self::FREQUENCY => $never], ['$not' => ['$any' => self::CHASED]]]]];
        // The follow-ups repeat the guard: app versions without effective answers do not hide
        // the follow-ups of a hidden question and would otherwise require them.
        $this->fields([
            'wegjagen-gedrag', 'wegjagen-gedrag-anders',
            'wegjagen-reactie', 'wegjagen-reactie-anders',
            'wegjagen-momenten', 'wegjagen-momenten-anders',
        ], function (?array $rule) use ($guard) {
            // Null or plain rules (customized in the administration) stay as they are: a group
            // would hide the question in older apps and stop it counting as required.
            return app(IntakeConditions::class)->grouped($rule) ? $this->withGuard($rule, $guard) : $rule;
        });
    }

    private function fields(array $keys, callable $rule): void
    {
        IntakeField::whereIn('key', $keys)
            ->whereHas('section', fn ($query) => $query->where('key', 'sociaal'))
            ->get()->each(function (IntakeField $field) use ($rule) {
                $next = $rule($this->withoutFirstGuard($field->show_if));
                if ($next !== $field->show_if) {
                    $field->update(['show_if' => $next]);
                }
            });
    }

    /**
     * Removes the guard of the first version. It was merged in as a top-level `$not`, or, when the
     * rule had its own `$not`, written as `{"$all": [rule, guard]}`. Only those shapes are undone:
     * an administrator's own `$all` stays as it is.
     */
    private function withoutFirstGuard(?array $rule): ?array
    {
        $first = ['$not' => [self::FREQUENCY => 'Nooit']];
        if ($rule === null) {
            return null;
        }
        if (array_keys($rule) === ['$all'] && count($rule['$all']) === 2 && $rule['$all'][1] === $first) {
            return $rule['$all'][0];
        }
        if (($rule['$not'] ?? null) === $first['$not']) {
            unset($rule['$not']);
        }

        return $rule ?: null;
    }

    /** Always one `$all` with the guard as its last child, so the guard is recognised wherever it ends up. */
    private function withGuard(array $rule, array $guard): array
    {
        if ($this->contains($rule, $guard)) {
            return $rule;
        }

        return array_keys($rule) === ['$all'] ? ['$all' => [...$rule['$all'], $guard]] : ['$all' => [$rule, $guard]];
    }

    private function contains(mixed $rule, array $guard): bool
    {
        if ($rule === $guard) {
            return true;
        }
        foreach (is_array($rule) ? $rule : [] as $child) {
            if ($this->contains($child, $guard)) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // Retain the new answer option and conditions for saved responses.
    }
};
