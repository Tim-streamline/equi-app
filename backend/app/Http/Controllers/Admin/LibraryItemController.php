<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FocusTopic;
use App\Models\LibraryCategory;
use App\Models\LibraryItem;
use App\Models\Therapist;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LibraryItemController extends Controller
{
    public function index(Request $request): Response
    {
        $items = LibraryItem::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where('title', 'ilike', "%{$q}%"))
            ->when($request->string('format')->toString(), fn ($query, $f) => $query->where('format', $f))
            ->when($request->string('gate')->toString(), function ($query, $g) {
                match ($g) {
                    'plus' => $query->where('is_plus', true),
                    'featured' => $query->where('is_featured', true),
                    'draft' => $query->whereNull('published_at'),
                    default => null,
                };
            })
            ->with('author:id,name')
            ->orderByDesc('is_featured')->orderBy('order')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LibraryItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'kind' => $i->kind,
                'format' => $i->format,
                'author' => $i->author?->name,
                'is_plus' => $i->is_plus,
                'is_featured' => $i->is_featured,
                'published' => (bool) $i->published_at,
                'published_at' => $i->published_at,
            ]);

        return Inertia::render('Library/Index', [
            'items' => $items,
            'filters' => $request->only('q', 'format', 'gate'),
            'counts' => [
                'total' => LibraryItem::count(),
                'drafts' => LibraryItem::whereNull('published_at')->count(),
                'plus' => LibraryItem::where('is_plus', true)->count(),
            ],
        ]);
    }

    public function edit(?LibraryItem $library = null): Response
    {
        return Inertia::render('Library/Edit', [
            'item' => $library?->load('categories:id', 'focusTopics:id', 'media'),
            'categories' => LibraryCategory::orderBy('order')->get(['id', 'label']),
            'focusTopics' => FocusTopic::orderBy('order')->get(['id', 'title']),
            'therapists' => Therapist::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // Inertia uses store for new items; we route create through edit with no model.
    public function create(): Response
    {
        return $this->edit(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['slug'] ??= Str::slug($data['title']).'-'.Str::lower(Str::random(4));
        $item = LibraryItem::create($data);
        $this->syncRelations($item, $request);
        AuditLogger::created($item);

        return redirect()->route('admin.library.index')->with('success', 'Library item created.');
    }

    public function update(Request $request, LibraryItem $library): RedirectResponse
    {
        $data = $this->validateData($request, $library->id);
        $before = $library->only(array_keys($data));
        $library->update($data);
        $this->syncRelations($library, $request);
        AuditLogger::updated($library, $before);

        return redirect()->route('admin.library.index')->with('success', 'Library item updated.');
    }

    public function destroy(LibraryItem $library): RedirectResponse
    {
        AuditLogger::deleted($library);
        $library->delete();

        return back()->with('success', 'Library item deleted.');
    }

    private function syncRelations(LibraryItem $item, Request $request): void
    {
        if ($request->has('category_ids')) {
            $item->categories()->sync($request->input('category_ids', []));
        }
        if ($request->has('focus_ids')) {
            $item->focusTopics()->sync($request->input('focus_ids', []));
        }
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:library_items,slug'.($id ? ",{$id}" : '')],
            'kind' => ['required', 'string', 'max:32'],
            'format' => ['required', 'in:article,video,course,program'],
            'description' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'video_url' => ['nullable', 'url'],
            'hero_image_url' => ['nullable', 'url'],
            'duration_label' => ['nullable', 'string', 'max:255'],
            'author_therapist_id' => ['nullable', 'exists:therapists,id'],
            'published_at' => ['nullable', 'date'],
            'is_plus' => ['boolean'],
            'is_featured' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
