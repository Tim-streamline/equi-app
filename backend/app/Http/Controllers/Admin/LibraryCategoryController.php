<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryCategory;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LibraryCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('LibraryCategories/Index', [
            'categories' => LibraryCategory::withCount('items')->orderBy('order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['slug'] ??= Str::slug($data['label']);
        $cat = LibraryCategory::create($data);
        AuditLogger::created($cat);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, LibraryCategory $libraryCategory): RedirectResponse
    {
        $data = $this->validateData($request, $libraryCategory->id);
        $before = $libraryCategory->only(array_keys($data));
        $libraryCategory->update($data);
        AuditLogger::updated($libraryCategory, $before);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(LibraryCategory $libraryCategory): RedirectResponse
    {
        AuditLogger::deleted($libraryCategory);
        $libraryCategory->delete();

        return back()->with('success', 'Category removed.');
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:library_categories,slug'.($id ? ",{$id}" : '')],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['boolean'],
        ]);
    }
}
