<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedKnowledgeCard;
use App\Models\FeedLabelAttachment;
use App\Models\FeedRuleVersion;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\LibraryItem;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\AuditLogger;
use App\Support\FeedCatalog;
use App\Support\FeedPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FeedCatalogController extends Controller
{
    private function canPublish(Request $request): bool
    {
        return $request->user('admin')->hasRole('admin');
    }

    private function options(Request $request): array
    {
        return ['canPublish' => $this->canPublish($request), 'categories' => FeedCatalog::CATEGORIES,
            'nutrientOptions' => FeedCatalog::NUTRIENTS,
            'libraryOptions' => LibraryItem::orderBy('title')->get(['id', 'title'])];
    }

    public function ingredients(Request $request)
    {
        $rows = Ingredient::query()->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request) {
            $query->where('name', 'ilike', '%'.$request->string('q').'%')->orWhereIn('id', IngredientRevision::select('ingredient_id')->whereRaw('data::text ILIKE ?', ['%'.$request->string('q').'%']));
        }))->when($request->filled('status'), fn ($q) => $q->where('catalog_status', $request->string('status')))
            ->orderBy('name')->paginate(25)->withQueryString();
        $rows->through(function ($ingredient) {
            $ingredient->setAttribute('versions', IngredientRevision::where('ingredient_id', $ingredient->id)->orderByDesc('number')->get());

            return $ingredient;
        });

        return Inertia::render('FeedScanner/Ingredients', array_merge($this->options($request), ['ingredients' => $rows, 'filters' => $request->only('q', 'status')]));
    }

    public function saveIngredient(Request $request, ?Ingredient $ingredient = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ingredients')->ignore($ingredient?->id)],
            'category' => ['nullable', 'string', 'max:255'], 'explanation' => ['nullable', 'string', 'max:20000'],
            'assessment' => ['nullable', Rule::in(['preferred', 'conditional', 'critical', 'unrated'])],
            'exceptions' => ['nullable', 'string', 'max:10000'], 'default_tag' => ['nullable', Rule::in(['good', 'warn', 'danger'])],
            'library_item_id' => ['nullable', 'uuid', 'exists:library_items,id'], 'internal_notes' => ['nullable', 'string', 'max:20000'],
            'terms' => ['present', 'array', 'max:100'], 'terms.*.term' => ['required', 'string', 'max:255'],
            'terms.*.language' => ['required', 'string', 'max:12'], 'terms.*.kind' => ['required', Rule::in(['exact', 'related'])],
            'expected_number' => ['nullable', 'integer', 'min:1'],
        ]);
        $keys = [];
        foreach ($data['terms'] as $term) {
            $key = FeedCatalog::normalize($term['term']).'|'.$term['language'].'|'.$term['kind'];
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['terms' => 'Deze benaming staat dubbel in de lijst.']);
            }
            $keys[$key] = true;
        }
        DB::transaction(function () use ($data, $ingredient, $request) {
            $record = $ingredient ? Ingredient::lockForUpdate()->findOrFail($ingredient->id) : (new Ingredient)->forceFill(['name' => $data['name'], 'catalog_status' => 'draft']);
            if (! $record->exists) {
                $record->save();
            }
            if ($ingredient) {
                $this->checkVersion($data, FeedCatalog::latestIngredient($record)?->number);
            }
            $record->forceFill(['internal_notes' => $data['internal_notes'] ?? null, 'catalog_status' => in_array($record->catalog_status, ['published', 'legacy']) ? $record->catalog_status : 'draft', 'needs_review' => true])->save();
            $payload = collect($data)->except(['internal_notes', 'expected_number'])->all();
            $revision = FeedCatalog::ingredientDraft($record, $payload, $request->user('admin')->id);
            AuditLogger::log('ingredient_draft_saved', $record, after: ['version_id' => $revision->id, 'number' => $revision->number]);
        });

        return back()->with('success', 'Kenniskaart opgeslagen als concept.');
    }

    private function checkVersion(array $data, ?int $number): void
    {
        if (($data['expected_number'] ?? null) != $number) {
            throw ValidationException::withMessages(['expected_number' => 'Dit item is ondertussen gewijzigd. Sluit het formulier en open het opnieuw.']);
        }
    }

    public function publishIngredient(Request $request, Ingredient $ingredient)
    {
        abort_unless($this->canPublish($request), 403);
        $data = $request->validate(['version_id' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $ingredient, $data) {
            // Same lock order for all ingredient publishers, including different ingredients.
            DB::table('feed_assessment_profiles')->orderBy('id')->lockForUpdate()->get();
            $record = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            abort_if($record->catalog_status === 'archived', 409, 'Sla eerst een nieuw concept op om deze kaart opnieuw te publiceren.');
            $revision = FeedCatalog::latestIngredient($record);
            abort_unless($revision && $revision->id === $data['version_id'] && $revision->status === 'draft', 409, 'Open het nieuwste concept.');
            FeedCatalog::publishIngredient($record, $revision, $request->user('admin')->id);
            AuditLogger::log('ingredient_published', $record, after: ['version_id' => $revision->id]);
        });

        return back()->with('success', 'Kenniskaart gepubliceerd.');
    }

    public function archiveIngredient(Request $request, Ingredient $ingredient)
    {
        abort_unless($this->canPublish($request), 403);
        $ingredient->forceFill(['catalog_status' => 'archived'])->save();
        AuditLogger::log('archived', $ingredient);

        return back()->with('success', 'Ingrediënt gearchiveerd. De historie blijft bewaard.');
    }

    public function products(Request $request)
    {
        $rows = Product::query()->withCount('scans')->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request) {
            $term = '%'.$request->string('q').'%';
            $query->where('name', 'ilike', $term)->orWhere('brand', 'ilike', $term)->orWhere('barcode', 'ilike', $term)
                ->orWhereIn('id', ProductVersion::select('product_id')->whereRaw('data::text ILIKE ?', [$term]));
        }))->when($request->boolean('review'), fn ($q) => $q->where(fn ($q) => $q->where('needs_review', true)->orWhere('catalog_status', 'legacy'))->where('catalog_status', '!=', 'archived'))
            ->when($request->filled('category'), fn ($q) => $q->whereIn('id', ProductVersion::select('product_id')->whereRaw('number = (SELECT MAX(v.number) FROM product_versions v WHERE v.product_id = product_versions.product_id)')->whereRaw("data->>'category' = ?", [$request->string('category')->toString()])))
            ->when($request->string('sort')->toString() === 'scans', fn ($q) => $q->orderByDesc('scans_count'))
            ->orderBy('brand')->orderBy('name')->paginate(20)->withQueryString();
        $rows->through(function ($product) {
            $product->setAttribute('versions', ProductVersion::where('product_id', $product->id)->with(['attachments', 'assessments.overrides', 'assessments.ruleVersion:id,number,profile_id,status'])->orderByDesc('number')->get());

            return $product;
        });

        return Inertia::render('FeedScanner/Products', array_merge($this->options($request), [
            'products' => $rows, 'filters' => $request->only('q', 'review', 'sort', 'category'),
            'reviewCount' => Product::where(fn ($q) => $q->where('needs_review', true)->orWhere('catalog_status', 'legacy'))->where('catalog_status', '!=', 'archived')->count(),
            'ingredientOptions' => Ingredient::where('catalog_status', '!=', 'archived')->orderBy('name')->get(['id', 'name', 'published_version_id']),
            'assessmentProfiles' => DB::table('feed_assessment_profiles')->get(['id', 'active_rule_version_id']),
        ]));
    }

    public function saveProduct(Request $request, ?Product $product = null)
    {
        $data = $request->validate([
            'approve' => ['sometimes', 'boolean'],
            'brand' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products')->ignore($product?->id)],
            'category' => ['required', Rule::in(array_keys(FeedCatalog::CATEGORIES))],
            'composition_text' => ['nullable', 'string', 'max:30000'], 'usage' => ['nullable', 'string', 'max:10000'],
            'source' => ['nullable', 'string', 'max:2000'], 'internal_notes' => ['nullable', 'string', 'max:20000'],
            'ingredients' => ['present', 'array', 'max:150'],
            'ingredients.*.ingredient_id' => ['nullable', 'uuid', 'exists:ingredients,id'],
            'ingredients.*.label' => ['required', 'string', 'max:255'], 'ingredients.*.amount' => ['nullable', 'string', 'max:255'],
            'ingredients.*.match_status' => ['required', Rule::in(['unknown', 'suggested', 'confirmed'])],
            'ingredients.*.selenium_form' => ['nullable', Rule::in(['organic', 'inorganic', 'unknown'])],
            'ingredients.*.added_selenium' => ['required', Rule::in(['unknown', 'confirmed', 'not_added'])],
            'ingredients.*.evidence' => ['nullable', 'string', 'max:2000'],
            'nutrients' => ['present', 'array', 'max:40'],
            'nutrients.*.code' => ['required', Rule::in(array_keys(FeedCatalog::NUTRIENTS))],
            'nutrients.*.value' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'nutrients.*.qualifier' => ['sometimes', Rule::in(['exact', 'less_than', 'greater_than', 'minimum', 'maximum'])],
            'nutrients.*.unit' => ['required', Rule::in(['%', 'g/kg', 'mg/kg'])],
            'nutrients.*.basis' => ['required', Rule::in(['product', 'dry_matter', 'unknown'])],
            'nutrients.*.source' => ['nullable', 'string', 'max:2000'],
            'expected_number' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($data['approve'] ?? false) {
            abort_unless($this->canPublish($request), 403);
        }
        $seen = [];
        foreach ($data['nutrients'] as $index => $nutrient) {
            $data['nutrients'][$index]['qualifier'] = $nutrient['qualifier'] ?? 'exact';
            $key = $nutrient['code'].'|'.$nutrient['basis'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['nutrients' => 'Een voedingswaarde staat dubbel op dezelfde analysebasis.']);
            }
            if ($nutrient['unit'] === '%' && (float) $nutrient['value'] > 100) {
                throw ValidationException::withMessages(["nutrients.$index.value" => 'Een percentage mag niet hoger zijn dan 100.']);
            }
            $seen[$key] = true;
        }
        foreach ($data['ingredients'] as $index => &$row) {
            if ($row['match_status'] !== 'unknown' && empty($row['ingredient_id'])) {
                throw ValidationException::withMessages(["ingredients.$index.ingredient_id" => 'Kies een ingrediënt voor deze koppeling.']);
            }
            if ($row['added_selenium'] === 'confirmed' && empty(trim($row['evidence'] ?? ''))) {
                throw ValidationException::withMessages(["ingredients.$index.evidence" => 'Leg vast waar de bevestiging van toegevoegd selenium op is gebaseerd.']);
            }
            if (in_array($row['selenium_form'] ?? null, ['organic', 'inorganic']) && empty(trim($row['evidence'] ?? ''))) {
                throw ValidationException::withMessages(["ingredients.$index.evidence" => 'Vermeld de bron voor deze seleniumvorm.']);
            }
            $row['ingredient_revision_id'] = empty($row['ingredient_id']) ? null : Ingredient::findOrFail($row['ingredient_id'])->published_version_id;
        }
        unset($row);
        DB::transaction(function () use ($data, $product, $request) {
            $record = $product ? Product::lockForUpdate()->findOrFail($product->id) : (new Product)->forceFill(['brand' => $data['brand'], 'name' => $data['name'], 'barcode' => null, 'catalog_status' => 'draft']);
            if (! $record->exists) {
                $record->save();
            }
            if ($product) {
                $this->checkVersion($data, FeedCatalog::latestProduct($record)?->number);
            }
            $record->forceFill(['internal_notes' => $data['internal_notes'] ?? null, 'catalog_status' => in_array($record->catalog_status, ['published', 'legacy']) ? $record->catalog_status : 'draft', 'needs_review' => true])->save();
            $version = FeedCatalog::productDraft($record, collect($data)->except(['internal_notes', 'expected_number', 'approve'])->all(), $request->user('admin')->id);
            if ($data['approve'] ?? false) {
                FeedCatalog::approveProduct($record, $version, $request->user('admin')->id, null);
            }
            AuditLogger::log('product_draft_saved', $record, after: ['version_id' => $version->id, 'number' => $version->number]);
        });

        return back()->with('success', ($data['approve'] ?? false) ? 'Product opgeslagen en goedgekeurd.' : 'Product opgeslagen als concept.');
    }

    public function reviewProduct(Request $request, Product $product)
    {
        abort_unless($this->canPublish($request), 403);
        $data = $request->validate(['version_id' => ['required', 'uuid'], 'decision' => ['required', Rule::in(['approve', 'needs_info', 'reject'])], 'notes' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($data, $product, $request) {
            $record = Product::lockForUpdate()->findOrFail($product->id);
            abort_if($record->catalog_status === 'archived', 409, 'Sla eerst een nieuw concept op om dit product opnieuw te beoordelen.');
            $version = FeedCatalog::latestProduct($record);
            abort_unless($version && $version->id === $data['version_id'] && in_array($version->status, ['draft', 'needs_info']), 409, 'Open het nieuwste concept.');
            if ($data['decision'] === 'approve') {
                validator($version->data, ['barcode' => ['nullable', Rule::unique('products')->ignore($product->id)]])->validate();
                FeedCatalog::approveProduct($record, $version, $request->user('admin')->id, $data['notes'] ?? null);
            } else {
                $version->update(['status' => $data['decision'] === 'needs_info' ? 'needs_info' : 'rejected', 'review_notes' => $data['notes'] ?? null, 'reviewed_by' => $request->user('admin')->id, 'reviewed_at' => now()]);
                $record->forceFill(['needs_review' => $data['decision'] === 'needs_info'])->save();
            }
            AuditLogger::log('product_reviewed', $record, after: ['version_id' => $version->id, 'decision' => $data['decision']], reason: $data['notes'] ?? null);
        });

        return back()->with('success', 'Controle opgeslagen.');
    }

    public function archiveProduct(Request $request, Product $product)
    {
        abort_unless($this->canPublish($request), 403);
        $product->forceFill(['catalog_status' => 'archived', 'needs_review' => false])->save();
        AuditLogger::log('archived', $product);

        return back()->with('success', 'Product gearchiveerd. De historie blijft bewaard.');
    }

    public function upload(Request $request, ProductVersion $version)
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'], 'purpose' => ['required', Rule::in(['packaging', 'composition', 'analysis'])]]);
        // Keep attachments on a version immutable after approval, including concurrent reviews.
        $path = null;
        try {
            DB::transaction(function () use ($request, $version, $data, &$path) {
                $product = Product::lockForUpdate()->findOrFail($version->product_id);
                abort_if($product->catalog_status === 'archived', 409, 'Dit product is gearchiveerd.');
                $version->refresh();
                abort_unless(in_array($version->status, ['draft', 'needs_info']) && FeedCatalog::latestProduct(Product::findOrFail($version->product_id))->id === $version->id, 409, 'Voeg etiketten toe aan het nieuwste concept.');
                $file = $request->file('file');
                $path = $file->store('feed-labels', 'local');
                $attachment = FeedLabelAttachment::create(['product_version_id' => $version->id, 'purpose' => $data['purpose'], 'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $request->user('admin')->id]);
                AuditLogger::log('feed_label_uploaded', $attachment);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Etiket toegevoegd.');
    }

    public function attachment(FeedLabelAttachment $attachment)
    {
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function rules(Request $request)
    {
        return Inertia::render('FeedScanner/Rules', array_merge($this->options($request), ['cards' => FeedKnowledgeCard::orderBy('title')->get(),
            'ingredientOptions' => Ingredient::where('catalog_status', '!=', 'archived')->orderBy('name')->get(['id', 'name']),
            'profiles' => DB::table('feed_assessment_profiles')->orderBy('code')->get()->map(function ($profile) {
                $profile->versions = FeedRuleVersion::where('profile_id', $profile->id)->orderByDesc('number')->get();

                return $profile;
            })]));
    }

    public function saveCard(Request $request, ?FeedKnowledgeCard $card = null)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'explanation' => ['nullable', 'string', 'max:30000'], 'internal_notes' => ['nullable', 'string', 'max:20000'],
            'library_item_id' => ['nullable', 'uuid', 'exists:library_items,id'], 'status' => ['required', Rule::in(['draft', 'published', 'archived'])], 'expected_updated_at' => ['nullable', 'string']]);
        DB::transaction(function () use ($request, $data, $card) {
            $record = $card ? FeedKnowledgeCard::lockForUpdate()->findOrFail($card->id) : null;
            if ($record && ($data['expected_updated_at'] ?? null) !== $record->updated_at->toISOString()) {
                throw ValidationException::withMessages(['title' => 'Deze kaart is ondertussen gewijzigd. Open de kaart opnieuw.']);
            }
            if ($data['status'] !== 'draft' || $record?->status === 'published') {
                abort_unless($this->canPublish($request), 403);
            }
            unset($data['expected_updated_at']);
            $before = $record?->toArray();
            if ($record) {
                $record->update($data);
            } else {
                $record = FeedKnowledgeCard::create($data);
            }
            AuditLogger::log('feed_knowledge_saved', $record, $before, $record->toArray());
        });

        return back()->with('success', 'Kenniskaart opgeslagen.');
    }

    public function saveRules(Request $request, string $profile)
    {
        $data = $request->validate(array_merge([
            'expected_number' => ['nullable', 'integer', 'min:1'],
            'principles' => ['nullable', 'string', 'max:20000'], 'explanation' => ['nullable', 'string', 'max:20000'],
            'sugar_starch_challenged' => ['required', 'numeric', 'min:0', 'max:100'], 'sugar_starch_healthy' => ['required', 'numeric', 'min:0', 'max:100', 'gte:sugar_starch_challenged'],
            'organic_selenium_cap' => ['required', 'numeric', 'min:0', 'max:10'], 'unknown_added_selenium_cap' => ['required', 'numeric', 'min:0', 'max:10', 'gte:organic_selenium_cap'],
            'exceptions' => ['present', 'array', 'max:100'], 'exceptions.*.ingredient_id' => ['required', 'uuid', 'distinct', 'exists:ingredients,id'],
            'exceptions.*.condition' => ['required', 'string', 'max:2000'],
            'exceptions.*.max_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], FeedPolicy::capRules(false)));
        DB::transaction(function () use ($request, $profile, $data) {
            $record = DB::table('feed_assessment_profiles')->where('id', $profile)->lockForUpdate()->first();
            abort_unless($record, 404);
            $latest = FeedRuleVersion::where('profile_id', $profile)->orderByDesc('number')->first();
            $this->checkVersion($data, $latest?->number);
            unset($data['expected_number']);
            $data['sugar_starch_caps'] ??= $latest?->data['sugar_starch_caps'] ?? [];
            FeedPolicy::validate($data);
            $data['knowledge_snapshot'] = FeedKnowledgeCard::where('status', 'published')->get(['id', 'title', 'explanation', 'library_item_id'])->toArray();
            $data['ingredient_revision_ids'] = Ingredient::where('catalog_status', 'published')->pluck('published_version_id')->all();
            $version = FeedRuleVersion::create(['profile_id' => $profile, 'number' => ($latest?->number ?? 0) + 1, 'status' => 'draft', 'calibration_status' => 'uncalibrated', 'schema_version' => FeedPolicy::SCHEMA_VERSION,
                'data' => $data, 'content_hash' => hash('sha256', json_encode($data)), 'created_by' => $request->user('admin')->id]);
            AuditLogger::log('feed_rules_draft_saved', $version);
        });

        return back()->with('success', 'Voedingsbeleid opgeslagen als concept. De puntenweging volgt na kalibratie.');
    }

    public function publishRules(Request $request, string $profile)
    {
        abort_unless($this->canPublish($request), 403);
        $data = $request->validate(['version_id' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $profile, $data) {
            $record = DB::table('feed_assessment_profiles')->where('id', $profile)->lockForUpdate()->first();
            abort_unless($record, 404);
            $version = FeedRuleVersion::where('profile_id', $profile)->orderByDesc('number')->first();
            if (! $version || $version->id !== $data['version_id'] || $version->status !== 'draft' || $version->schema_version !== FeedPolicy::SCHEMA_VERSION) {
                throw ValidationException::withMessages(['policy' => 'Open het nieuwste concept met scoreplafonds.']);
            }
            FeedPolicy::validate($version->data);
            $version->update(['status' => 'published', 'published_at' => now(), 'published_by' => $request->user('admin')->id]);
            DB::table('feed_assessment_profiles')->where('id', $profile)->update(['active_rule_version_id' => $version->id, 'updated_at' => now()]);
            AuditLogger::log('feed_rules_activated', $version, before: ['active_rule_version_id' => $record->active_rule_version_id], after: ['active_rule_version_id' => $version->id]);
        });

        return back()->with('success', 'Scoreplafonds geactiveerd. De volledige puntenweging blijft nog ongekalibreerd.');
    }
}
