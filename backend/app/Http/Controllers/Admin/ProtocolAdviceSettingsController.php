<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProtocolAdviceLayout;
use App\Http\Controllers\Controller;
use App\Models\BewegingAdvies;
use App\Models\ManagementAdvies;
use App\Models\VoedingAdvies;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProtocolAdviceSettingsController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const ADVICE_MODELS = [
        'voeding' => VoedingAdvies::class,
        'management' => ManagementAdvies::class,
        'beweging' => BewegingAdvies::class,
    ];

    public function index(): Response
    {
        return Inertia::render('ProtocolAdviceSettings/Index', [
            'voedingAdviezen' => VoedingAdvies::query()->orderBy('title')->get(),
            'managementAdviezen' => ManagementAdvies::query()->orderBy('title')->get(),
            'bewegingAdviezen' => BewegingAdvies::query()->orderBy('title')->get(),
            'layoutOptions' => [
                'voeding' => ProtocolAdviceLayout::options(true),
                'management' => ProtocolAdviceLayout::options(false),
                'beweging' => ProtocolAdviceLayout::options(false),
            ],
        ]);
    }

    public function store(Request $request, string $category): RedirectResponse
    {
        $modelClass = $this->modelClass($category);
        $data = $this->validateAdvice($request, $category);
        $data['icon'] = $this->storeIcon($request);
        unset($data['remove_icon']);
        $advice = $modelClass::query()->create($data);
        AuditLogger::created($advice);

        return back()->with('success', 'Advies toegevoegd.');
    }

    public function update(Request $request, string $category, string $advice): RedirectResponse
    {
        $model = $this->findAdvice($category, $advice);
        $data = $this->validateAdvice($request, $category);
        $oldIcon = $model->icon;

        if ($request->hasFile('icon')) {
            $data['icon'] = $this->storeIcon($request);
        } elseif ($data['remove_icon'] ?? false) {
            $data['icon'] = null;
        }

        unset($data['remove_icon']);
        $before = $model->only(array_keys($data));
        $model->update($data);

        if ($oldIcon && $oldIcon !== $model->icon) {
            Storage::disk('public')->delete($oldIcon);
        }
        AuditLogger::updated($model, $before);

        return back()->with('success', 'Advies bijgewerkt.');
    }

    public function archive(string $category, string $advice): RedirectResponse
    {
        return $this->setArchived($category, $advice, true);
    }

    public function restore(string $category, string $advice): RedirectResponse
    {
        return $this->setArchived($category, $advice, false);
    }

    /** Archived advice keeps its row and icon: protocols that use it, or hold an override for it, still refer to it. */
    private function setArchived(string $category, string $advice, bool $archive): RedirectResponse
    {
        DB::transaction(function () use ($category, $advice, $archive) {
            $model = $this->modelClass($category)::query()->lockForUpdate()->findOrFail($advice);
            if (($model->archived_at !== null) === $archive) {
                return;
            }
            $before = $model->only('archived_at');
            $model->forceFill(['archived_at' => $archive ? now() : null])->save();
            AuditLogger::log($archive ? 'archived' : 'restored', $model, before: $before, after: $model->only('archived_at'));
        });

        return back()->with('success', $archive
            ? 'Advies gearchiveerd. Protocollen die het al gebruiken behouden het.'
            : 'Advies hersteld.');
    }

    /** @return array{title: string, description: string, layout: string, icon?: mixed, remove_icon: bool} */
    private function validateAdvice(Request $request, string $category): array
    {
        $layouts = array_column(ProtocolAdviceLayout::options($category === 'voeding'), 'value');

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'layout' => ['required', Rule::in($layouts)],
            'icon' => ['nullable', 'file', 'max:2048', 'extensions:jpg,jpeg,png,gif,webp,svg', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/svg+xml'],
            'remove_icon' => ['sometimes', 'boolean'],
        ]);
    }

    private function storeIcon(Request $request): ?string
    {
        $icon = $request->file('icon');
        if (! $icon) {
            return null;
        }

        $extension = strtolower($icon->getClientOriginalExtension());

        return $icon->storeAs('protocol-advice-icons', Str::uuid().'.'.$extension, 'public');
    }

    /** @return class-string<Model> */
    private function modelClass(string $category): string
    {
        abort_unless(isset(self::ADVICE_MODELS[$category]), 404);

        return self::ADVICE_MODELS[$category];
    }

    private function findAdvice(string $category, string $id): Model
    {
        return $this->modelClass($category)::query()->findOrFail($id);
    }
}
