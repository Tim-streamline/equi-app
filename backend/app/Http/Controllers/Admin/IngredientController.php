<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Support\AuditLogger;
use App\Support\FeedCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IngredientController extends Controller
{
    public function index(Request $request): Response
    {
        $ingredients = Ingredient::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where('name', 'ilike', "%{$q}%"))
            ->when($request->string('tag')->toString(), fn ($query, $tag) => $query->where('default_tag', $tag))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Ingredients/Index', [
            'ingredients' => $ingredients,
            'filters' => $request->only('q', 'tag'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        DB::transaction(function () use ($data) {
            $ingredient = Ingredient::create($data);
            FeedCatalog::snapshotLegacyIngredient($ingredient);
            AuditLogger::created($ingredient);
        });

        return back()->with('success', 'Ingredient added.');
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $data = $this->validateData($request, $ingredient->id);
        DB::transaction(function () use ($ingredient, $data) {
            $ingredient = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            abort_unless($ingredient->catalog_status === 'legacy' && in_array(FeedCatalog::latestIngredient($ingredient)?->status, [null, 'legacy'], true), 409, 'Bewerk deze kenniskaart via Voerscanner → Ingrediënten.');
            $before = $ingredient->only(array_keys($data));
            $ingredient->update($data);
            FeedCatalog::snapshotLegacyIngredient($ingredient);
            AuditLogger::updated($ingredient, $before);
        });

        return back()->with('success', 'Ingredient updated.');
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        if (IngredientRevision::where('ingredient_id', $ingredient->id)->exists()) {
            $ingredient->forceFill(['catalog_status' => 'archived'])->save();
            AuditLogger::log('archived', $ingredient);

            return back()->with('success', 'Ingredient archived; history retained.');
        }
        AuditLogger::deleted($ingredient);
        $ingredient->delete();

        return back()->with('success', 'Ingredient removed.');
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:ingredients,name'.($id ? ",{$id}" : '')],
            'description' => ['nullable', 'string'],
            'default_tag' => ['nullable', 'in:good,warn,danger'],
            'needs_review' => ['boolean'],
        ]);
    }
}
