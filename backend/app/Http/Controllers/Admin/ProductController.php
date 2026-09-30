<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('brand', 'ilike', "%{$q}%")->orWhere('barcode', 'ilike', "%{$q}%")))
            ->when($request->boolean('review'), fn ($query) => $query->where('needs_review', true))
            ->withCount('scans')
            ->with('ingredients')
            ->orderBy('brand')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $request->only('q', 'review'),
            'reviewCount' => Product::where('needs_review', true)->count(),
            'ingredientOptions' => Ingredient::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        DB::transaction(function () use ($data) {
            $product = Product::create(Arr::except($data, 'ingredients'));
            $this->syncIngredients($product, $data);
            AuditLogger::log('created', $product, after: $product->load('ingredients')->toArray());
        });

        return back()->with('success', 'Product added.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product->id);
        DB::transaction(function () use ($product, $data) {
            $before = $product->load('ingredients')->toArray();
            $product->update(Arr::except($data, 'ingredients'));
            $this->syncIngredients($product, $data);
            AuditLogger::log('updated', $product, $before, $product->load('ingredients')->toArray());
        });

        return back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        DB::transaction(function () use ($product) {
            AuditLogger::log('deleted', $product, before: $product->load('ingredients')->toArray());
            $product->delete();
        });

        return back()->with('success', 'Product removed.');
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'brand' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64', 'unique:products,barcode'.($id ? ",{$id}" : '')],
            'category' => ['nullable', 'string', 'max:255'],
            'needs_review' => ['boolean'],
            'ingredients' => ['sometimes', 'array'],
            'ingredients.*' => ['required', 'array:ingredient_id,order,amount'],
            'ingredients.*.ingredient_id' => ['required', 'uuid', 'distinct', 'exists:ingredients,id'],
            'ingredients.*.order' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'ingredients.*.amount' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function syncIngredients(Product $product, array $data): void
    {
        // Older callers that omit ingredients must not clear the composition.
        if (array_key_exists('ingredients', $data)) {
            $product->ingredients()->sync(collect($data['ingredients'])->mapWithKeys(fn (array $ingredient) => [
                $ingredient['ingredient_id'] => [
                    'order' => $ingredient['order'],
                    'amount' => $ingredient['amount'] ?? null,
                ],
            ])->all());
        }
    }
}
