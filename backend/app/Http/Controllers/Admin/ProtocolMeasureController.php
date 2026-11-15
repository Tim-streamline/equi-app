<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\LibrarySyncLock;
use App\Support\ProtocolMeasures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The protocol measure library (Content → Protocolmaatregelen). Measures a protocol refers to can be
 * archived but not deleted or moved to another category: protocols keep referring to them by id.
 * Every mutation takes LibrarySyncLock, the lock protocol saves hold while they snapshot measures.
 */
class ProtocolMeasureController extends Controller
{
    private const IN_USE_DELETE = 'Deze maatregel wordt gebruikt in :count protocol(len) en kan niet worden verwijderd. Archiveer hem in plaats daarvan.';

    private const IN_USE_MOVE = 'Deze maatregel wordt gebruikt in :count protocol(len) en kan niet naar een andere categorie. Archiveer hem en maak een nieuwe maatregel aan in de andere categorie.';

    public function index(): Response
    {
        $measures = collect(ProtocolMeasures::keys())->flatMap(function (string $category) {
            $usage = ProtocolMeasures::usageCounts($category);

            return ProtocolMeasures::model($category)::query()->ordered()->get()
                ->map(fn (Model $measure): array => [
                    'id' => $measure->id,
                    'category' => $category,
                    'title' => $measure->title,
                    'description' => $measure->description,
                    'layout' => $measure->layout,
                    'icon_url' => $measure->icon_url,
                    'archived_at' => $measure->archived_at,
                    'sort_order' => $measure->sort_order,
                    'usage_count' => $usage[strtolower($measure->id)] ?? 0,
                ]);
        })->values();

        return Inertia::render('ProtocolMeasures/Index', [
            'measures' => $measures,
            'categories' => collect(ProtocolMeasures::CATEGORIES)->map(fn (array $config, string $key): array => [
                'key' => $key,
                'label' => $config['label'],
                'description' => $config['description'],
                'layouts' => ProtocolMeasures::layoutOptions($key),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateMeasure($request, (string) $request->input('category'));
        $icon = $this->storeIcon($request);

        $this->withUploadedIcon($icon, function () use ($data, $icon) {
            DB::transaction(function () use ($data, $icon) {
                LibrarySyncLock::acquire();
                $category = $data['category'];
                $measure = ProtocolMeasures::model($category)::query()->create([
                    ...collect($data)->only(['title', 'description', 'layout'])->all(),
                    'icon' => $icon,
                    'sort_order' => ProtocolMeasures::nextSortOrder($category),
                ]);
                AuditLogger::created($measure);
            });
        });

        return back()->with('success', 'Maatregel toegevoegd.');
    }

    public function update(Request $request, string $category, string $measure): RedirectResponse
    {
        ProtocolMeasures::config($category);
        if (! $request->filled('category')) {
            $request->merge(['category' => $category]);
        }
        $data = $this->validateMeasure($request, (string) $request->input('category'));
        $icon = $request->hasFile('icon') ? $this->storeIcon($request) : null;

        $this->withUploadedIcon($icon, function () use ($data, $category, $measure, $icon) {
            DB::transaction(function () use ($data, $category, $measure, $icon) {
                LibrarySyncLock::acquire();
                $model = ProtocolMeasures::model($category)::query()->lockForUpdate()->findOrFail($measure);
                $oldIcon = $model->icon;
                $newIcon = $icon ?? (($data['remove_icon'] ?? false) ? null : $oldIcon);
                $fields = ['title' => $data['title'], 'description' => $data['description'], 'layout' => $data['layout'], 'icon' => $newIcon];

                if ($data['category'] === $category) {
                    $before = $model->only(array_keys($fields));
                    $model->update($fields);
                    AuditLogger::updated($model, $before);
                } else {
                    $this->moveToCategory($model, $category, $data['category'], $fields);
                }

                if ($oldIcon && $oldIcon !== $newIcon) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($oldIcon));
                }
            });
        });

        return back()->with('success', $category === $data['category']
            ? 'Maatregel bijgewerkt.'
            : 'Maatregel bijgewerkt en verplaatst naar '.ProtocolMeasures::config($data['category'])['label'].'.');
    }

    public function move(Request $request, string $category, string $measure): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $modelClass = ProtocolMeasures::model($category);

        DB::transaction(function () use ($modelClass, $measure, $direction) {
            LibrarySyncLock::acquire();
            $rows = $modelClass::query()->whereNull('archived_at')->ordered()->lockForUpdate()->get(['id', 'title', 'sort_order']);
            $index = $rows->search(fn (Model $row): bool => strcasecmp($row->id, $measure) === 0);
            if ($index === false) {
                $modelClass::query()->findOrFail($measure);
                throw ValidationException::withMessages(['measure' => 'Alleen actieve maatregelen kunnen worden verplaatst.']);
            }

            $neighbour = $direction === 'up' ? $index - 1 : $index + 1;
            if (! $rows->has($neighbour)) {
                return;
            }

            $ids = $rows->pluck('id')->all();
            [$ids[$index], $ids[$neighbour]] = [$ids[$neighbour], $ids[$index]];
            $before = $rows->pluck('sort_order', 'id');
            foreach ($ids as $position => $id) {
                if ($before[$id] !== $position + 1) {
                    $modelClass::query()->whereKey($id)->update(['sort_order' => $position + 1]);
                }
            }

            $moved = $rows[$index];
            AuditLogger::log('reordered', $moved, ['sort_order' => $before[$moved->id]], ['sort_order' => $neighbour + 1]);
        });

        return back();
    }

    public function archive(string $category, string $measure): RedirectResponse
    {
        return $this->setArchived($category, $measure, true);
    }

    public function restore(string $category, string $measure): RedirectResponse
    {
        return $this->setArchived($category, $measure, false);
    }

    public function destroy(string $category, string $measure): RedirectResponse
    {
        try {
            DB::transaction(function () use ($category, $measure) {
                LibrarySyncLock::acquire();
                $model = ProtocolMeasures::model($category)::query()->lockForUpdate()->findOrFail($measure);
                $usage = ProtocolMeasures::usageCount($category, $model->id);
                if ($usage > 0) {
                    throw ValidationException::withMessages(['measure' => str_replace(':count', (string) $usage, self::IN_USE_DELETE)]);
                }

                $icon = $model->icon;
                AuditLogger::deleted($model);
                $model->delete();
                if ($icon) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($icon));
                }
            });
        } catch (QueryException $exception) {
            // The snapshot foreign keys restrict deletion; the usage check above should have caught it.
            if ($exception->getCode() !== '23503') {
                throw $exception;
            }

            throw ValidationException::withMessages(['measure' => str_replace(':count', '1 of meer', self::IN_USE_DELETE)]);
        }

        return back()->with('success', 'Maatregel verwijderd.');
    }

    /** Archived measures keep their row and icon: protocols that use them, or hold an override for them, still refer to them. */
    private function setArchived(string $category, string $measure, bool $archive): RedirectResponse
    {
        DB::transaction(function () use ($category, $measure, $archive) {
            LibrarySyncLock::acquire();
            $model = ProtocolMeasures::model($category)::query()->lockForUpdate()->findOrFail($measure);
            if (($model->archived_at !== null) === $archive) {
                return;
            }
            $before = $model->only('archived_at', 'sort_order');
            $model->forceFill($archive
                ? ['archived_at' => now()]
                // A restored measure returns at the end of its category.
                : ['archived_at' => null, 'sort_order' => ProtocolMeasures::nextSortOrder($category)])->save();
            AuditLogger::log($archive ? 'archived' : 'restored', $model, before: $before, after: $model->only('archived_at', 'sort_order'));
        });

        return back()->with('success', $archive
            ? 'Maatregel gearchiveerd. Protocollen die hem al gebruiken behouden hem.'
            : 'Maatregel hersteld.');
    }

    /**
     * Move an unused measure to another category's table, keeping its id, icon, archive state and creation date.
     *
     * @param  array{title: string, description: string, layout: string, icon: ?string}  $fields
     */
    private function moveToCategory(Model $source, string $from, string $to, array $fields): void
    {
        $usage = ProtocolMeasures::usageCount($from, $source->id);
        if ($usage > 0) {
            throw ValidationException::withMessages(['category' => str_replace(':count', (string) $usage, self::IN_USE_MOVE)]);
        }

        $targetClass = ProtocolMeasures::model($to);
        if ($targetClass::query()->whereKey($source->id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['category' => 'Deze maatregel kan niet worden verplaatst. Vernieuw de pagina en probeer opnieuw.']);
        }

        $before = ['category' => $from, 'type' => class_basename($source)] + $source->only(array_keys($fields));
        $target = new $targetClass;
        $target->forceFill([
            ...$fields,
            'id' => $source->id,
            'archived_at' => $source->archived_at,
            'created_at' => $source->created_at,
            'sort_order' => ProtocolMeasures::nextSortOrder($to),
        ])->save();
        $source->delete();

        AuditLogger::log('moved', $target, $before, ['category' => $to, 'type' => class_basename($target)] + $target->only(array_keys($fields)));
    }

    /** @return array{category: string, title: string, description: string, layout: string, remove_icon?: bool} */
    private function validateMeasure(Request $request, string $category): array
    {
        $layouts = isset(ProtocolMeasures::CATEGORIES[$category])
            ? array_column(ProtocolMeasures::layoutOptions($category), 'value')
            : [];

        return $request->validate([
            'category' => ['required', Rule::in(ProtocolMeasures::keys())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'layout' => ['required', Rule::in($layouts)],
            'icon' => ['nullable', 'file', 'max:2048', 'extensions:jpg,jpeg,png,gif,webp,svg', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/svg+xml'],
            'remove_icon' => ['sometimes', 'boolean'],
        ], [
            'layout.in' => 'Kies een layout die bij deze categorie past.',
        ]);
    }

    private function storeIcon(Request $request): ?string
    {
        $icon = $request->file('icon');
        if (! $icon) {
            return null;
        }

        return $icon->storeAs('protocol-advice-icons', Str::uuid().'.'.strtolower($icon->getClientOriginalExtension()), 'public');
    }

    /** Remove a freshly uploaded icon when the save that should reference it fails. */
    private function withUploadedIcon(?string $icon, callable $save): void
    {
        try {
            $save();
        } catch (Throwable $exception) {
            if ($icon) {
                Storage::disk('public')->delete($icon);
            }
            throw $exception;
        }
    }
}
