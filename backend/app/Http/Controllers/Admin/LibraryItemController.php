<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryCategory;
use App\Models\LibraryItem;
use App\Models\MediaAsset;
use App\Models\Therapist;
use App\Support\AuditLogger;
use App\Support\LibraryThumbnail;
use App\Support\LibraryVideoDuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'hero_image_url' => $i->hero_image_url,
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
            'item' => $library?->load('categories:id', 'media'),
            'videoDurationMinutes' => $library ? LibraryVideoDuration::minutes($library->duration_label, $library->duration_sec) : null,
            'automaticThumbnailUrl' => $library ? app(LibraryThumbnail::class)->sourceAsset($library)?->thumbnail_url : null,
            'categories' => LibraryCategory::orderBy('order')->get(['id', 'label']),
            'therapists' => Therapist::availableFor($library?->author_therapist_id)->orderBy('name')->get(['id', 'name', 'title', 'archived_at'])
                ->map(fn (Therapist $therapist) => [
                    'id' => $therapist->id,
                    'archived_at' => $therapist->archived_at,
                    // The original Shelley profile uses only her first name.
                    'name' => $therapist->name === 'Shelley' && $therapist->title === 'De Paardentherapeut'
                        ? 'Shelley Meeuwsen' : $therapist->name,
                ]),
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
        DB::transaction(function () use ($data, $request) {
            $item = LibraryItem::create($data);
            $this->syncRelations($item, $request);
            app(LibraryThumbnail::class)->resolve($item);
            AuditLogger::created($item);
        });

        return redirect()->route('admin.library.index')->with('success', 'Library item created.');
    }

    public function update(Request $request, LibraryItem $library): RedirectResponse
    {
        $data = $this->validateData($request, $library->id);
        $before = $library->only(array_keys($data));
        DB::transaction(function () use ($library, $data, $request, $before) {
            $library->update($data);
            $this->syncRelations($library, $request);
            app(LibraryThumbnail::class)->resolve($library);
            AuditLogger::updated($library, $before);
        });

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
        MediaAsset::whereIn('id', $request->input('media_ids', []))
            ->whereNull('library_item_id')->where('uploaded_by', $request->user('admin')->id)
            ->update(['library_item_id' => $item->id]);
        if ($request->has('category_ids')) {
            $item->categories()->sync($request->input('category_ids', []));
        }
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:library_items,slug'.($id ? ",{$id}" : '')],
            'format' => ['required', 'in:article,video,audio,podcast,course,program'],
            'description' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'hero_image_url' => ['nullable', 'url', 'max:255'],
            'thumbnail_mode' => ['sometimes', 'in:auto,manual,none'],
            'media_ids' => ['sometimes', 'array'],
            'media_ids.*' => ['uuid', Rule::exists('media_assets', 'id')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('library_item_id', $id ?? '00000000-0000-0000-0000-000000000000')
                    ->orWhere(fn ($q) => $q->whereNull('library_item_id')->where('uploaded_by', $request->user('admin')->id))))],
            'duration_label' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => [Rule::excludeIf($request->input('format') !== 'video'), 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.LibraryVideoDuration::MAX_MINUTES],
            'author_therapist_id' => ['nullable', Therapist::assignmentRule($id ? LibraryItem::find($id)?->author_therapist_id : null)],
            'published_at' => ['nullable', 'date'],
            'credit_cost' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_plus' => ['boolean'],
            'is_featured' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ], [
            'author_therapist_id.exists' => 'Deze author is gearchiveerd of niet beschikbaar. Kies een actieve author.',
            'duration_minutes.numeric' => 'Vul een geldig positief getal in voor de videoduur.',
            'duration_minutes.gt' => 'De videoduur moet groter zijn dan 0.',
            'duration_minutes.decimal' => 'Gebruik maximaal twee decimalen voor de videoduur.',
            'duration_minutes.max' => 'De opgegeven videoduur is te groot.',
        ]);
        if ($validated['format'] === 'video') {
            if (array_key_exists('duration_minutes', $validated)) {
                $minutes = $validated['duration_minutes'];
                $validated['duration_label'] = $minutes === null ? null : LibraryVideoDuration::label((float) $minutes);
                $validated['duration_sec'] = $minutes === null ? null : (int) round($minutes * 60);
            } elseif (array_key_exists('duration_label', $validated)) {
                // Older clients may still send the duration as a label.
                $minutes = LibraryVideoDuration::minutes($validated['duration_label']);
                if ($validated['duration_label'] !== null && $minutes === null) {
                    throw ValidationException::withMessages(['duration_label' => 'Vul een geldige positieve duur in minuten in.']);
                }
                $validated['duration_label'] = $minutes === null ? null : LibraryVideoDuration::label($minutes);
                $validated['duration_sec'] = $minutes === null ? null : (int) round($minutes * 60);
            }
        }
        unset($validated['duration_minutes'], $validated['media_ids']);
        // Preserve clients that still send the legacy image URL field.
        if (! isset($validated['thumbnail_mode']) && ! empty($validated['hero_image_url'])) {
            $validated['thumbnail_mode'] = 'manual';
        }

        return $validated;
    }
}
