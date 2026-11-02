<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class IntakeOptionEffects
{
    /** Effects are scoped to the exact answer text within one question. */
    public function validate(mixed $effects, mixed $options): void
    {
        if ($effects === null || $effects === []) {
            return;
        }

        if (is_array($effects) && is_array($options)) {
            foreach ($effects as $answer => $settings) {
                if (! in_array((string) $answer, $options, true) || ! is_array($settings)
                    || array_keys($settings) !== ['exclusive_selection'] || ! is_bool($settings['exclusive_selection'])) {
                    throw $this->invalid();
                }
            }

            return;
        }

        throw $this->invalid();
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'option_effects' => 'Koppel effecten aan bestaande antwoordopties, met exclusive_selection als true of false.',
        ]);
    }
}
