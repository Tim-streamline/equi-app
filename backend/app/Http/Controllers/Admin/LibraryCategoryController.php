<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryCategory;
use App\Models\LibraryFilterGroup;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LibraryCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('LibraryCategories/Index', [
            'groups' => LibraryFilterGroup::orderBy('order')->orderBy('label')->get(),
            'categories' => LibraryCategory::withCount('items')->orderBy('order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['slug'] ??= Str::slug($data['label']);
        $cat = LibraryCategory::create($data);
        AuditLogger::created($cat);

        return back()->with('success', 'Categorie toegevoegd.');
    }

    public function update(Request $request, LibraryCategory $libraryCategory): RedirectResponse
    {
        $data = $this->validateData($request, $libraryCategory->id);
        $before = $libraryCategory->only(array_keys($data));
        $libraryCategory->update($data);
        AuditLogger::updated($libraryCategory, $before);

        return back()->with('success', 'Categorie bijgewerkt.');
    }

    public function destroy(LibraryCategory $libraryCategory): RedirectResponse
    {
        $before = $libraryCategory->only('active');
        $libraryCategory->update(['active' => false]);
        AuditLogger::updated($libraryCategory, $before);

        return back()->with('success', 'Categorie gearchiveerd.');
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        $existing = $id ? LibraryCategory::findOrFail($id) : null;
        $request->merge(['label' => trim((string) $request->input('label')), 'slug' => $request->input('slug') ?: ($existing?->slug ?? Str::slug((string) $request->input('label'))), 'group_id' => $request->input('group_id') ?: ($existing?->group_id ?? LibraryFilterGroup::where('slug', 'onderwerp')->value('id'))]);
        $data = $request->validate([
            'group_id' => ['required', 'uuid', Rule::exists('library_filter_groups', 'id')],
            'active' => ['sometimes', 'boolean'],
            'label' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:library_categories,slug'.($id ? ",{$id}" : '')],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['boolean'],
            'is_quick_filter' => ['boolean'],
        ]);
        $canonical = fn ($label) => match (Str::slug($label)) {
            'darm', 'darmen', 'darmgezondheid', 'voor-darmen' => 'darmen',
            'voor-jeuk' => 'jeuk',
            default => Str::slug($label),
        };
        if (LibraryCategory::where('group_id', $data['group_id'])->where('active', true)->when($id, fn ($q) => $q->whereKeyNot($id))->get()
            ->contains(fn ($row) => $canonical($row->label) === $canonical($data['label']))) {
            throw ValidationException::withMessages(['label' => 'Deze subcategorie bestaat al in deze groep.']);
        }

        return $data;
    }
}
