<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->orderBy('brand')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $request->only('q', 'review'),
            'reviewCount' => Product::where('needs_review', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = Product::create($this->validateData($request));
        AuditLogger::created($product);

        return back()->with('success', 'Product added.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product->id);
        $before = $product->only(array_keys($data));
        $product->update($data);
        AuditLogger::updated($product, $before);

        return back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        AuditLogger::deleted($product);
        $product->delete();

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
        ]);
    }
}
