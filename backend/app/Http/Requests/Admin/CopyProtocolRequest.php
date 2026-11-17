<?php

namespace App\Http\Requests\Admin;

use App\Models\Horse;
use App\Models\Protocol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Early, actionable checks for the copy dialog. ProtocolCopier repeats them under row locks. */
class CopyProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'horse_id' => ['required', 'uuid', 'exists:horses,id'],
            'destination' => ['required', 'in:new,draft'],
            'draft_id' => ['nullable', 'required_if:destination,draft', 'uuid'],
            'include_analysis' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Kies eerst een gebruiker.',
            'horse_id.required' => 'Kies een paard van deze gebruiker.',
            'draft_id.required_if' => 'Kies het concept dat je wilt invullen.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var Protocol $protocol */
            $protocol = $this->route('protocol');
            if (! $protocol->protocol_template_id) {
                $validator->errors()->add('protocol', 'Dit protocol heeft nog geen template en kan niet worden gekopieerd.');

                return;
            }
            $horse = Horse::query()->find($this->input('horse_id'));
            if (strtolower((string) $horse->owner_id) !== strtolower((string) $this->input('user_id'))) {
                $validator->errors()->add('horse_id', 'Dit paard hoort niet bij de gekozen gebruiker.');
            } elseif ($horse->status !== 'active') {
                $validator->errors()->add('horse_id', 'Een gearchiveerd paard kan geen protocol krijgen. Herstel het paard eerst.');
            } elseif ($horse->id === $protocol->horse_id) {
                $validator->errors()->add('horse_id', 'Kies een ander paard dan het paard van dit protocol.');
            }
        }];
    }
}
