<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DoseBuildupType;
use App\Http\Controllers\Controller;
use App\Models\DoseBuildup;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoseBuildupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $buildup = DoseBuildup::create($this->validated($request));
        AuditLogger::created($buildup);

        return back()->with('success', 'Dosisopbouw aangemaakt.');
    }

    public function update(Request $request, DoseBuildup $doseBuildup): RedirectResponse
    {
        $rules = ['name' => ['required', 'string', 'max:255']];
        $messages = [];
        foreach (DoseBuildup::DOSING_FIELDS as $field) {
            $rules[$field] = ['missing'];
            $messages[$field.'.missing'] = 'De opbouwinstellingen staan vast. Dupliceer de dosisopbouw om een ander schema te maken.';
        }
        $data = $request->validate($rules, $messages);
        $before = $doseBuildup->only(array_keys($data));
        $doseBuildup->update($data);
        AuditLogger::updated($doseBuildup, $before);

        return back()->with('success', 'Naam van de dosisopbouw gewijzigd.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(DoseBuildupType::class)],
            'start_dose_ratio' => ['exclude_unless:type,linear', 'required_if:type,linear', 'nullable', 'numeric', 'gt:0', 'lte:1'],
            'days' => ['exclude_unless:type,linear', 'required_if:type,linear', 'nullable', 'integer', 'between:1,730'],
            'schedule' => ['exclude_unless:type,custom', 'required_if:type,custom', 'nullable', 'array', 'min:1', 'max:730'],
            'schedule.*' => ['array:day,dose_ratio'],
            'schedule.*.day' => ['required', 'integer', 'min:1'],
            'schedule.*.dose_ratio' => ['required', 'numeric', 'gt:0', 'lte:1'],
        ]);
        if ($data['type'] === 'custom') {
            $schedule = array_values($data['schedule']);
            foreach ($schedule as $index => &$step) {
                if ((int) $step['day'] !== $index + 1) {
                    throw ValidationException::withMessages(['schedule' => 'Gebruik opeenvolgende dagen vanaf dag 1.']);
                }
                $step = ['day' => $index + 1, 'dose_ratio' => (float) $step['dose_ratio']];
            }
            unset($step);
            if (end($schedule)['dose_ratio'] !== 1.0) {
                throw ValidationException::withMessages(['schedule' => 'De laatste opbouwdag moet 100% zijn.']);
            }
            $data = array_replace($data, ['schedule' => $schedule, 'days' => count($schedule), 'start_dose_ratio' => $schedule[0]['dose_ratio']]);
        } else {
            $data['schedule'] = null;
            if ((int) $data['days'] === 1) {
                $data['start_dose_ratio'] = 1;
            }
        }

        return $data;
    }
}
