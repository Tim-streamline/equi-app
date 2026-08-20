<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'horse_id' => ['required', 'uuid', 'exists:horses,id'],
            'protocol_type_id' => ['required', 'uuid', 'exists:protocol_types,id'],
            'therapist_id' => ['nullable', 'uuid', 'exists:therapists,id'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle_analyse' => ['nullable', 'string', 'max:255'],
            'subtitle_protocol' => ['nullable', 'string', 'max:255'],
            'subtitle_calendar' => ['nullable', 'string', 'max:255'],
            'total_weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'current_week' => ['nullable', 'integer', 'min:1', 'max:104'],
            'started_at' => ['nullable', 'date'],
            'status' => ['required', 'in:active,paused,completed'],

            'analysis' => ['nullable', 'array'],
            'analysis.cause' => ['nullable', 'string', 'max:10000'],
            'advice' => ['present', 'array', 'max:20'],
            'advice.*.id' => ['nullable', 'uuid'],
            'advice.*.icon_key' => ['required', 'in:leaf,run,horse'],
            'advice.*.title' => ['required', 'string', 'max:255'],
            'advice.*.body' => ['required', 'string', 'max:10000'],

            'phases' => ['required', 'array', 'size:3'],
            'phases.*.id' => ['nullable', 'uuid'],
            'phases.*.client_key' => ['required', 'string', 'max:100', 'distinct'],
            'phases.*.title' => ['required', 'string', 'max:255'],
            'phases.*.state' => ['required', 'in:done,active,upcoming'],
            'phases.*.week_start' => ['nullable', 'integer', 'min:0', 'max:104'],
            'phases.*.week_end' => ['nullable', 'integer', 'min:0', 'max:104'],
            'phases.*.chip_label' => ['nullable', 'string', 'max:255'],
            'phases.*.items' => ['present', 'array', 'max:100'],
            'phases.*.items.*.id' => ['nullable', 'uuid'],
            'phases.*.items.*.label' => ['required', 'string', 'max:255'],

            'tasks' => ['present', 'array', 'max:200'],
            'tasks.*.id' => ['nullable', 'uuid'],
            'tasks.*.phase_key' => ['nullable', 'string', 'max:100'],
            'tasks.*.label' => ['required', 'string', 'max:255'],
            'tasks.*.meta' => ['nullable', 'string', 'max:255'],
            'tasks.*.kind' => ['required', 'in:feeding,observation,care,other'],
            'tasks.*.active_from' => ['nullable', 'date'],
            'tasks.*.active_until' => ['nullable', 'date'],
            'tasks.*.reference_item_id' => ['nullable', 'uuid', 'exists:library_items,id'],
        ];
    }
}
