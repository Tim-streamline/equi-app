<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryFilterGroup;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LibraryFilterGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $group = LibraryFilterGroup::create($this->data($request));
        AuditLogger::created($group);

        return back()->with('success', 'Filtergroep toegevoegd.');
    }

    public function update(Request $request, LibraryFilterGroup $libraryFilterGroup): RedirectResponse
    {
        $data = $this->data($request, $libraryFilterGroup);
        $before = $libraryFilterGroup->only(array_keys($data));
        $libraryFilterGroup->update($data);
        AuditLogger::updated($libraryFilterGroup, $before);

        return back()->with('success', 'Filtergroep bijgewerkt.');
    }

    private function data(Request $request, ?LibraryFilterGroup $group = null): array
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'order' => ['required', 'integer', 'min:0'], 'active' => ['required', 'boolean']]);
        $data['label'] = trim($data['label']);
        $key = Str::slug($data['label']);
        if ($key === '' || in_array($key, ['persoonlijk', 'soort'], true)) {
            throw ValidationException::withMessages(['label' => 'Kies een eigen groepsnaam. Persoonlijk en Soort zijn systeemgroepen.']);
        }
        if (LibraryFilterGroup::when($group, fn ($q) => $q->whereKeyNot($group->id))->get()->contains(fn ($row) => Str::slug($row->label) === $key)) {
            throw ValidationException::withMessages(['label' => 'Deze filtergroep bestaat al.']);
        }
        if (! $group) {
            if (LibraryFilterGroup::where('slug', $key)->exists()) {
                throw ValidationException::withMessages(['label' => 'Deze groepsnaam is al eerder gebruikt. Kies een andere naam.']);
            }
            $data['slug'] = $key;
        }

        return $data;
    }
}
