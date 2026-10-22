<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One persistent horse-level workflow, configured by the questionnaire. */
class IntakeSafetySettings
{
    public function validate(?array $config): void
    {
        if ($config === null) {
            return;
        }
        Validator::make($config, [
            'version' => ['required', 'integer', 'in:1'],
            'section' => ['required', 'string', 'regex:/^[a-z0-9_-]+$/'],
            'marker_field' => ['required', 'string', 'regex:/^_[a-z0-9_-]+$/'],
            'state_field' => ['required', 'string', 'regex:/^_[a-z0-9_-]+$/', 'different:marker_field'],
            'state_value' => ['required', 'string'], 'clear_value' => ['required', 'string', 'different:state_value'],
            'actions' => ['required', 'array', 'min:1'], 'actions.*' => ['required', 'in:submit,preview,protocol', 'distinct'],
            'triggers' => ['required', 'array', 'list', 'min:1'],
            'triggers.*' => ['required', 'array:id,when,renew,block_while_matching,notice,popup,critical_fields'],
            'triggers.*.id' => ['required', 'string', 'regex:/^[a-z0-9_-]+$/', 'distinct'],
            'triggers.*.when' => ['required', 'array', 'min:1'],
            'triggers.*.renew' => ['required', 'boolean'], 'triggers.*.block_while_matching' => ['required', 'boolean'], 'triggers.*.popup' => ['required', 'boolean'],
            'triggers.*.notice' => ['required', 'string'],
            'triggers.*.critical_fields' => ['present', 'array', 'list'], 'triggers.*.critical_fields.*' => ['string'],
            'recovery' => ['required', 'array:require,conditional,intro,after'],
            'recovery.require' => ['required', 'array', 'min:1'],
            'recovery.conditional' => ['present', 'array', 'list'],
            'recovery.conditional.*' => ['array:when,require'],
            'recovery.conditional.*.when' => ['required', 'array', 'min:1'],
            'recovery.conditional.*.require' => ['required', 'array', 'min:1'],
            'recovery.intro' => ['required', 'string'], 'recovery.after' => ['required', 'string'],
            'messages' => ['required', 'array:blocked,confirm,update,acknowledge,pending'],
            'messages.blocked' => ['required', 'string', 'max:5000'],
            'messages.confirm' => ['required', 'string', 'max:255'],
            'messages.update' => ['required', 'string', 'max:255'],
            'messages.acknowledge' => ['required', 'string', 'max:255'],
            'messages.pending' => ['required', 'string', 'max:5000'],
            'release_source' => ['required', 'string', 'max:100'],
        ])->validate();
        foreach ([...array_column($config['triggers'], 'when'), $config['recovery']['require'],
            ...array_column($config['recovery']['conditional'], 'when'), ...array_column($config['recovery']['conditional'], 'require')] as $conditions) {
            if (array_is_list($conditions)) {
                throw ValidationException::withMessages(['safety_config' => 'Conditions must map field keys to accepted values.']);
            }
            foreach ($conditions as $key => $values) {
                if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) || ! is_array($values) || ! array_is_list($values) || ! $values
                    || array_filter($values, fn ($v) => ! is_string($v) || $v === '')) {
                    throw ValidationException::withMessages(['safety_config' => 'Each condition requires a field key and a non-empty list of answer values.']);
                }
            }
        }
    }

    public function validateAliases(mixed $aliases): void
    {
        if ($aliases === null || $aliases === []) {
            return;
        }
        if (! is_array($aliases) || array_is_list($aliases)
            || array_filter($aliases, fn ($v, $k) => ! is_string($v) || $v === '' || isset($aliases[$v]), ARRAY_FILTER_USE_BOTH)) {
            throw ValidationException::withMessages(['answer_aliases' => 'Use a map of old values to current values without chains or cycles.']);
        }
    }

    public function matches(array $conditions, array $answers): bool
    {
        foreach ($conditions as $key => $values) {
            $answer = $answers[$key] ?? null;
            if (! in_array($answer, $values, true)) {
                return false;
            }
        }

        return count($conditions) > 0;
    }

    public function requirements(array $config, array $answers): array
    {
        $required = $config['recovery']['require'];
        foreach ($config['recovery']['conditional'] as $rule) {
            if ($this->matches($rule['when'], $answers)) {
                $required = array_replace($required, $rule['require']);
            }
        }

        return $required;
    }
}
