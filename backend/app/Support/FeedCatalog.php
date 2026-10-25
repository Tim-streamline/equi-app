<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeedCatalog
{
    public const CATEGORIES = ['complete' => 'Compleet paardenvoer', 'complementary' => 'Aanvullend voer', 'balancer' => 'Balancer', 'mash' => 'Slobber'];

    public const NUTRIENTS = ['sugar' => 'Suiker', 'starch' => 'Zetmeel', 'moisture' => 'Vocht', 'dry_matter' => 'Droge stof', 'protein' => 'Ruw eiwit', 'fibre' => 'Ruwe celstof', 'fat' => 'Ruw vet', 'ash' => 'Ruwe as', 'calcium' => 'Calcium', 'phosphorus' => 'Fosfor', 'selenium_total' => 'Selenium totaal'];

    public static function normalize(string $term): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($term)));
    }

    public static function latestIngredient(Ingredient $ingredient): ?IngredientRevision
    {
        return IngredientRevision::where('ingredient_id', $ingredient->id)->orderByDesc('number')->first();
    }

    public static function latestProduct(Product $product): ?ProductVersion
    {
        return ProductVersion::where('product_id', $product->id)->orderByDesc('number')->first();
    }

    public static function snapshotLegacyIngredient(Ingredient $ingredient): void
    {
        IngredientRevision::create(['ingredient_id' => $ingredient->id, 'number' => (int) IngredientRevision::where('ingredient_id', $ingredient->id)->max('number') + 1,
            'status' => 'legacy', 'data' => ['name' => $ingredient->name, 'explanation' => $ingredient->description, 'default_tag' => $ingredient->default_tag, 'terms' => []]]);
    }

    public static function snapshotLegacyProduct(Product $product): void
    {
        $data = ['brand' => $product->brand, 'name' => $product->name, 'barcode' => $product->barcode, 'category' => $product->category,
            'ingredients' => $product->ingredients()->get()->map(fn ($i) => ['ingredient_id' => $i->id, 'label' => $i->name, 'order' => $i->pivot->order, 'amount' => $i->pivot->amount])->all(), 'nutrients' => []];
        ProductVersion::create(['product_id' => $product->id, 'number' => (int) ProductVersion::where('product_id', $product->id)->max('number') + 1,
            'status' => 'legacy', 'data' => $data, 'content_hash' => hash('sha256', json_encode($data))]);
    }

    public static function ingredientDraft(Ingredient $ingredient, array $data, ?string $actor): IngredientRevision
    {
        $revision = IngredientRevision::create(['ingredient_id' => $ingredient->id,
            'number' => (int) IngredientRevision::where('ingredient_id', $ingredient->id)->max('number') + 1,
            'status' => 'draft', 'data' => $data, 'created_by' => $actor]);
        foreach ($data['terms'] ?? [] as $term) {
            DB::table('ingredient_terms')->insert(['id' => (string) Str::uuid(), 'ingredient_revision_id' => $revision->id,
                'term' => trim($term['term']), 'normalized_term' => self::normalize($term['term']), 'language' => $term['language'], 'kind' => $term['kind']]);
        }

        return $revision;
    }

    public static function productDraft(Product $product, array $data, ?string $actor): ProductVersion
    {
        $previous = self::latestProduct($product);
        $profile = DB::table('feed_assessment_profiles')->where('code', $data['category'] === 'balancer' ? 'balancer' : 'standard')->value('id');
        $version = ProductVersion::create(['product_id' => $product->id,
            'number' => (int) ProductVersion::where('product_id', $product->id)->max('number') + 1,
            'status' => 'draft', 'profile_id' => $profile, 'data' => $data, 'created_by' => $actor,
            'content_hash' => hash('sha256', json_encode($data))]);
        foreach ($previous?->attachments ?? [] as $attachment) {
            // Files are immutable; a new version can retain the same original evidence.
            $copy = $attachment->replicate();
            $copy->product_version_id = $version->id;
            $copy->save();
        }

        return $version;
    }

    public static function publishIngredient(Ingredient $ingredient, IngredientRevision $revision, string $actor): void
    {
        // Serialize publication so concurrent exact synonym claims cannot both pass.
        DB::table('feed_assessment_profiles')->orderBy('id')->lockForUpdate()->get();
        $ownTerms = array_merge([['term' => $revision->data['name'], 'language' => 'nl', 'kind' => 'exact']], $revision->data['terms'] ?? []);
        foreach ($ownTerms as $term) {
            if ($term['kind'] !== 'exact') {
                continue;
            }
            $normalized = self::normalize($term['term']);
            $collision = DB::table('ingredient_terms as t')->join('ingredients as i', 'i.published_version_id', '=', 't.ingredient_revision_id')
                ->where('i.id', '!=', $ingredient->id)->whereNotIn('i.catalog_status', ['archived', 'removed'])
                ->where('t.kind', 'exact')->where('t.language', $term['language'])->where('t.normalized_term', $normalized)->exists();
            if ($term['language'] === 'nl') {
                $collision = $collision || Ingredient::where('id', '!=', $ingredient->id)->whereNotIn('catalog_status', ['archived', 'removed'])
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists();
            }
            if ($collision) {
                throw ValidationException::withMessages(['terms' => 'Deze exacte benaming hoort al bij een ander ingrediënt: '.$term['term']]);
            }
        }
        $revision->update(['status' => 'published', 'published_by' => $actor, 'published_at' => now()]);
        $ingredient->forceFill(['name' => $revision->data['name'], 'description' => $revision->data['explanation'] ?? null,
            'default_tag' => $revision->data['default_tag'] ?? null, 'catalog_status' => 'published', 'needs_review' => false,
            'published_version_id' => $revision->id])->save();
    }

    public static function approveProduct(Product $product, ProductVersion $version, string $actor, ?string $notes): void
    {
        // Serialize catalog publication so barcode validation remains valid until commit.
        DB::table('feed_assessment_profiles')->orderBy('id')->lockForUpdate()->get();
        validator($version->data, ['barcode' => ['nullable', Rule::unique('products')->ignore($product->id)]])->validate();
        if (! isset(self::CATEGORIES[$version->data['category'] ?? ''])) {
            throw ValidationException::withMessages(['category' => 'Kies eerst een productcategorie.']);
        }
        foreach ($version->data['ingredients'] ?? [] as $row) {
            if (! empty($row['ingredient_id'])) {
                $ingredient = Ingredient::findOrFail($row['ingredient_id']);
                if (in_array($ingredient->catalog_status, ['archived', 'removed'])) {
                    throw ValidationException::withMessages(['ingredients' => 'Een gekoppeld ingrediënt is gearchiveerd of verwijderd. Controleer de samenstelling.']);
                }
            }
        }
        $version->update(['status' => 'approved', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'review_notes' => $notes]);
        $product->forceFill(['brand' => $version->data['brand'], 'name' => $version->data['name'], 'barcode' => $version->data['barcode'] ?: null,
            'category' => $version->data['category'], 'catalog_status' => 'published', 'published_version_id' => $version->id, 'needs_review' => false])->save();
        $links = [];
        foreach ($version->data['ingredients'] ?? [] as $index => $row) {
            if (! empty($row['ingredient_id']) && ($row['match_status'] ?? 'confirmed') === 'confirmed' && ! isset($links[$row['ingredient_id']])) {
                $links[$row['ingredient_id']] = ['order' => $index + 1, 'amount' => $row['amount'] ?? null];
            }
        }
        $product->ingredients()->sync($links);
    }
}
