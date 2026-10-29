<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedAnalysisPrompt;
use App\Models\FeedKnowledgeCard;
use App\Models\FeedLabelAttachment;
use App\Models\FeedRuleVersion;
use App\Models\FeedScanSubmission;
use App\Models\Ingredient;
use App\Models\IngredientRevision;
use App\Models\LibraryItem;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\AuditLogger;
use App\Support\FeedAdminReadModel;
use App\Support\FeedCatalog;
use App\Support\FeedLabelReader;
use App\Support\FeedPhotoAnalysis;
use App\Support\FeedPolicy;
use App\Support\FeedPrefillMerger;
use App\Support\FeedProductPrefill;
use App\Support\FeedScanProcessor;
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
        }))->when($request->filled('status'), fn ($q) => $q->where('catalog_status', $request->string('status')), fn ($q) => $q->where('catalog_status', '!=', 'removed'))
            ->orderBy('name')->paginate(25)->withQueryString();
        $rows->through(function ($ingredient) {
            $ingredient->setAttribute('versions', IngredientRevision::where('ingredient_id', $ingredient->id)->orderByDesc('number')->get());

            return $ingredient;
        });

        return Inertia::render('FeedScanner/Ingredients', array_merge($this->options($request), ['ingredients' => $rows, 'filters' => $request->only('q', 'status')]));
    }

    public function saveIngredient(Request $request, ?Ingredient $ingredient = null)
    {
        abort_if($ingredient?->catalog_status === 'removed', 409, 'Herstel dit ingrediënt voordat je het bewerkt.');
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
            abort_if($record->catalog_status === 'removed', 409, 'Herstel dit ingrediënt voordat je het bewerkt.');
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
            abort_if(in_array($record->catalog_status, ['archived', 'removed']), 409, 'Herstel de kaart en sla een nieuw concept op om opnieuw te publiceren.');
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
        DB::transaction(function () use ($ingredient) {
            $record = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            abort_if($record->catalog_status === 'removed', 409);
            $record->forceFill(['catalog_status' => 'archived'])->save();
            AuditLogger::log('archived', $record);
        });

        return back()->with('success', 'Ingrediënt gearchiveerd. De historie blijft bewaard.');
    }

    public function removeIngredient(Request $request, Ingredient $ingredient)
    {
        abort_unless($this->canPublish($request), 403);
        DB::transaction(function () use ($ingredient) {
            $record = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            $before = $record->only('catalog_status');
            $record->forceFill(['catalog_status' => 'removed'])->save();
            AuditLogger::log('ingredient_removed', $record, before: $before, after: ['catalog_status' => 'removed']);
        });

        return back()->with('success', 'Ingrediënt verwijderd. Eerdere koppelingen en historie blijven bewaard. Herstellen kan via de filter Verwijderd.');
    }

    public function restoreIngredient(Request $request, Ingredient $ingredient)
    {
        abort_unless($this->canPublish($request), 403);
        DB::transaction(function () use ($ingredient) {
            $record = Ingredient::lockForUpdate()->findOrFail($ingredient->id);
            abort_unless($record->catalog_status === 'removed', 409);
            $record->forceFill(['catalog_status' => 'archived'])->save();
            AuditLogger::log('ingredient_restored', $record);
        });

        return back()->with('success', 'Ingrediënt hersteld als gearchiveerd. Je kunt het weer bewerken; het is niet automatisch gepubliceerd.');
    }

    public function overview(Request $request)
    {
        return Inertia::render('FeedScanner/Overview', FeedAdminReadModel::dashboard());
    }

    public function knowledge(Request $request)
    {
        return $request->string('tab')->toString() === 'nutrition' ? $this->knowledgeCards($request) : $this->ingredients($request);
    }

    private function knowledgeCards(Request $request)
    {
        return Inertia::render('FeedScanner/Rules', array_merge($this->options($request), [
            'mode' => 'knowledge', 'profiles' => [], 'ingredientOptions' => [], 'filters' => $request->only('q', 'status'),
            'cards' => FeedKnowledgeCard::when($request->filled('q'), fn ($q) => $q->where('title', 'ilike', '%'.$request->string('q').'%'))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderBy('title')->get(),
        ]));
    }

    public function products(Request $request)
    {
        $request->validate(['open' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['review', 'approved', 'needs_info'])]]);
        $status = $request->string('status')->toString() ?: ($request->boolean('review') ? 'review' : '');
        $rows = FeedAdminReadModel::products($status)->withCount('scans')->when($request->filled('open'), fn ($q) => $q->where('id', $request->string('open')))->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request) {
            $term = '%'.$request->string('q').'%';
            $query->where('name', 'ilike', $term)->orWhere('brand', 'ilike', $term)->orWhere('barcode', 'ilike', $term)
                ->orWhereIn('id', ProductVersion::select('product_id')->whereRaw('data::text ILIKE ?', [$term]));
        }))
            ->when($request->filled('category'), fn ($q) => $q->whereIn('id', ProductVersion::select('product_id')->whereRaw('number = (SELECT MAX(v.number) FROM product_versions v WHERE v.product_id = product_versions.product_id AND v.superseded_by_product_id IS NULL)')->whereRaw("data->>'category' = ?", [$request->string('category')->toString()])))
            ->when($request->string('sort')->toString() === 'scans', fn ($q) => $q->orderByDesc('scans_count'))
            ->when($request->string('sort')->toString() === 'newest', fn ($q) => $q->orderByDesc('created_at'))
            ->orderBy('brand')->orderBy('name')->paginate(20)->withQueryString();
        $rows->through(function ($product) {
            $currentVersionIds = FeedCatalog::currentProductVersions($product)->pluck('id');
            $submissions = FeedScanSubmission::where(fn ($q) => $q->where('product_id', $product->id)
                ->orWhere(fn ($q) => $q->whereNull('product_id')->where('reference_product_id', $product->id)))
                ->orderByDesc('created_at')->get()->map(fn ($s) => array_replace($s->toArray(), ['photos' => $s->adminPhotos()]));
            $product->setAttribute('submissions', $submissions);
            $product->setAttribute('versions', ProductVersion::where('product_id', $product->id)->with(['attachments', 'assessments.overrides', 'assessments.ruleVersion:id,number,profile_id,status'])->orderByDesc('number')->get()
                ->each(fn ($version) => $version->setAttribute('is_current', $currentVersionIds->contains($version->id))));

            $product->load('relatedProduct:id,brand,name');
            $product->setAttribute('delete_blockers', $this->deleteBlockers($product));

            return $product;
        });

        return Inertia::render('FeedScanner/Products', array_merge($this->options($request), [
            'products' => $rows, 'filters' => array_merge($request->only('q', 'sort', 'category', 'open'), ['status' => $status]),
            'reviewCount' => FeedAdminReadModel::products('review')->count(),
            'ingredientOptions' => Ingredient::whereNotIn('catalog_status', ['archived', 'removed'])->orderBy('name')->get(['id', 'name', 'published_version_id']),
            'assessmentProfiles' => DB::table('feed_assessment_profiles')->get(['id', 'active_rule_version_id']),
        ]));
    }

    public function retrySubmission(Request $request, FeedScanSubmission $submission)
    {
        app(FeedScanProcessor::class)->analyse($submission);

        return back()->with('success', 'Inzending verwerkt. Bekijk de actuele status bij het product.');
    }

    public function saveProduct(Request $request, ?Product $product = null)
    {
        $data = $request->validate([
            'source_version_id' => ['nullable', 'uuid', Rule::prohibitedIf(! $product)],
            'related_product_id' => ['nullable', 'uuid', 'exists:products,id', Rule::prohibitedIf((bool) $product)],
            'approve' => ['sometimes', 'boolean'],
            'analysis_attachment_ids' => ['sometimes', 'array', 'max:20'],
            'analysis_attachment_ids.*' => ['required', 'uuid', 'distinct'],
            'brand' => ['required', 'string', 'max:255'], 'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category' => ['required', Rule::in(array_keys(FeedCatalog::CATEGORIES))],
            'composition_text' => ['nullable', 'string', 'max:30000'], 'usage' => ['nullable', 'string', 'max:10000'],
            'source' => ['nullable', 'string', 'max:2000'], 'internal_notes' => ['nullable', 'string', 'max:20000'],
            'recognition_enabled' => ['sometimes', 'boolean'],
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
                $record->forceFill(['related_product_id' => $data['related_product_id'] ?? null])->save();
            }
            $source = null;
            if ($product) {
                $source = ! empty($data['source_version_id'])
                    ? ProductVersion::where('product_id', $record->id)->findOrFail($data['source_version_id'])
                    : FeedCatalog::latestProduct($record);
                if ($source) {
                    abort_unless(FeedCatalog::currentProductVersions($record)->whereKey($source->id)->exists(), 409, 'Open de nieuwste correctie van dit product.');
                }
                $this->checkVersion($data, $source?->number);
            }
            $record->forceFill(['internal_notes' => $data['internal_notes'] ?? null, 'catalog_status' => in_array($record->catalog_status, ['published', 'legacy']) ? $record->catalog_status : 'draft', 'needs_review' => true])->save();
            $version = FeedCatalog::productDraft($record, collect($data)->except(['internal_notes', 'expected_number', 'source_version_id', 'related_product_id', 'approve', 'analysis_attachment_ids'])->all(), $request->user('admin')->id, sourceVersion: $source);
            foreach ($data['analysis_attachment_ids'] ?? [] as $id) {
                $photo = FeedLabelAttachment::where('uploaded_by', $request->user('admin')->id)
                    ->whereNull('product_version_id')->whereNull('submission_id')->lockForUpdate()->findOrFail($id);
                $photo->update(['product_version_id' => $version->id]);
            }
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
            $version = FeedCatalog::currentProductVersions($record)->whereKey($data['version_id'])->first();
            abort_unless($version && in_array($version->status, ['draft', 'needs_info']), 409, 'Open het nieuwste concept van dit product.');
            if ($data['decision'] === 'approve') {
                FeedCatalog::approveProduct($record, $version, $request->user('admin')->id, $data['notes'] ?? null);
            } else {
                $version->update(['status' => $data['decision'] === 'needs_info' ? 'needs_info' : 'rejected', 'review_notes' => $data['notes'] ?? null, 'reviewed_by' => $request->user('admin')->id, 'reviewed_at' => now()]);
                $record->forceFill(['needs_review' => FeedCatalog::hasPendingProductVersions($record)])->save();
            }
            AuditLogger::log('product_reviewed', $record, after: ['version_id' => $version->id, 'decision' => $data['decision']], reason: $data['notes'] ?? null);
        });

        return back()->with('success', 'Controle opgeslagen.');
    }

    /** Save comparison evidence privately; product fields are saved only when the form is submitted. */
    public function prefillProduct(Request $request, FeedLabelReader $reader, FeedProductPrefill $prefill)
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:30000', 'required_without:photos'],
            'photos' => ['nullable', 'array', 'max:4', 'required_without:text'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
        ], ['text.required_without' => 'Voeg een foto toe of plak de tekst van het etiket.', 'photos.required_without' => 'Voeg een foto toe of plak de tekst van het etiket.']);
        if (! $reader->configured() && ! $request->hasFile('photos')) {
            return response()->json(['message' => 'Uitlezen met AI is niet ingesteld op deze server.'], 503);
        }
        $comparisons = [];
        $ids = [];
        $readings = [];
        try {
            foreach ($request->file('photos', []) as $file) {
                $path = $file->store('feed-labels', 'local');
                $photo = FeedLabelAttachment::create(['purpose' => 'label', 'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $request->user('admin')->id]);
                $analysis = app(FeedPhotoAnalysis::class);
                $photo->update(['ocr' => $analysis->ocr($photo)]);
                $photo->update(['luna' => $analysis->luna($photo)]);
                $comparisons[] = ['id' => $photo->id, 'name' => $photo->original_name, 'ocr' => $photo->ocr, 'luna' => $photo->luna];
                $ids[] = $photo->id;
                if ($photo->luna['status'] === 'completed') {
                    $readings[] = $photo->luna['data'];
                }
            }
            if (filled($data['text'] ?? null)) {
                $prompt = FeedAnalysisPrompt::findOrFail('label');
                $readings[] = $reader->read([], $data['text'], ['type' => 'label', 'prompt' => $prompt->prompt]);
            }
            if (! $readings) {
                throw new \RuntimeException('reader_unavailable');
            }
        } catch (\Throwable) {
            return response()->json(['message' => 'Het etiket kon niet worden uitgelezen. Probeer een scherpere foto of plak de tekst.', 'analyses' => $comparisons, 'analysis_attachment_ids' => $ids], 422);
        }
        $merged = FeedPrefillMerger::merge($readings);
        $result = $prefill->fromExtraction($merged['extraction']);
        $result['notes'] = array_values(array_unique([...$result['notes'], ...$merged['notes']]));
        if (collect($comparisons)->contains(fn ($row) => $row['luna']['status'] !== 'completed')) {
            $result['notes'][] = 'Niet alle foto’s konden met Luna worden gelezen. Controleer de vergelijking en vul ontbrekende gegevens aan.';
        }

        return response()->json($result + ['analyses' => $comparisons, 'analysis_attachment_ids' => $ids, 'nutrient_conflicts' => $merged['nutrient_conflicts']]);
    }

    public function archiveProduct(Request $request, Product $product)
    {
        abort_unless($this->canPublish($request), 403);
        $product->forceFill(['catalog_status' => 'archived', 'needs_review' => false])->save();
        AuditLogger::log('archived', $product);

        return back()->with('success', 'Product gearchiveerd. De historie blijft bewaard.');
    }

    /**
     * Permanently delete a product that nobody has used yet, such as a placeholder.
     * Anything with scans, submissions, assessments, label photos or later recipes is archived instead.
     */
    public function deleteProduct(Request $request, Product $product)
    {
        abort_unless($this->canPublish($request), 403);
        $label = trim($product->brand.' · '.$product->name, ' ·');
        $blocked = DB::transaction(function () use ($product) {
            $record = Product::lockForUpdate()->findOrFail($product->id);
            if ($blockers = $this->deleteBlockers($record)) {
                return $blockers;
            }
            $before = $record->only('brand', 'name', 'barcode', 'category', 'catalog_status');
            $record->forceFill(['published_version_id' => null])->save();
            $record->ingredients()->detach();
            ProductVersion::where('product_id', $record->id)->delete();
            $record->delete();
            AuditLogger::log('deleted', $record, before: $before);

            return [];
        });
        if ($blocked) {
            if (in_array('een vervolgrecept', $blocked, true)) {
                return back()->withErrors(['product' => 'Dit product hoort bij de receptgeschiedenis van een ander product. Je kunt het archiveren.']);
            }

            return back()->withErrors(['product' => 'Dit product kan niet worden verwijderd, want het heeft '.implode(', ', $blocked).'. Archiveer het in plaats daarvan.']);
        }

        return back()->with('success', "Product {$label} verwijderd.");
    }

    /** @return list<string> Reasons, in Dutch, why deleting would lose history. */
    private function deleteBlockers(Product $product): array
    {
        $versions = ProductVersion::where('product_id', $product->id)->pluck('id');
        $checks = [
            'scans' => DB::table('scan_results')->where('product_id', $product->id)->exists(),
            'inzendingen uit de app' => DB::table('feed_scan_submissions')->where('product_id', $product->id)
                ->orWhere('reference_product_id', $product->id)->orWhereIn('reference_version_id', $versions)->orWhereIn('product_version_id', $versions)->exists(),
            'beoordelingen' => DB::table('feed_assessments')->whereIn('product_version_id', $versions)->exists(),
            'etiketfoto\'s' => DB::table('feed_label_attachments')->whereIn('product_version_id', $versions)->exists(),
            'een vervolgrecept' => ProductVersion::where('superseded_by_product_id', $product->id)->exists()
                || Product::where('related_product_id', $product->id)->exists(),
        ];

        return array_keys(array_filter($checks));
    }

    public function upload(Request $request, ProductVersion $version)
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'], 'purpose' => ['required', Rule::in(['packaging', 'composition', 'analysis'])]]);
        // Keep attachments on a version immutable after approval, including concurrent reviews.
        $path = null;
        $attachment = null;
        try {
            DB::transaction(function () use ($request, $version, $data, &$path, &$attachment) {
                $product = Product::lockForUpdate()->findOrFail($version->product_id);
                abort_if($product->catalog_status === 'archived', 409, 'Dit product is gearchiveerd.');
                $version->refresh();
                abort_unless(in_array($version->status, ['draft', 'needs_info']) && FeedCatalog::currentProductVersions($product)->whereKey($version->id)->exists(), 409, 'Voeg etiketten toe aan het nieuwste concept van dit product.');
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

        app(FeedPhotoAnalysis::class)->attachment($attachment);

        return back()->with('success', 'Etiket toegevoegd. Bekijk de OCR- en Luna-resultaten bij het product.');
    }

    public function submissionPhoto(FeedScanSubmission $submission, string $photo)
    {
        $file = $submission->currentPhotos()->firstWhere('id', $photo);
        abort_unless($file, 404);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function attachment(FeedLabelAttachment $attachment)
    {
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function rules(Request $request)
    {
        return Inertia::render('FeedScanner/Rules', array_merge($this->options($request), FeedAdminReadModel::profiles(), ['cards' => [], 'analysisPrompts' => FeedAnalysisPrompt::orderBy('type')->get(),
            'ingredientOptions' => Ingredient::whereNotIn('catalog_status', ['archived', 'removed'])->orderBy('name')->get(['id', 'name'])]));
    }

    public function saveAnalysisPrompt(Request $request, string $type)
    {
        abort_unless($this->canPublish($request), 403);
        $data = $request->validate(['prompt' => ['required', 'string', 'max:20000'], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($type, $data) {
            $prompt = FeedAnalysisPrompt::lockForUpdate()->findOrFail($type);
            abort_unless($prompt->version === $data['version'], 409, 'Deze prompt is ondertussen gewijzigd. Vernieuw de pagina.');
            $before = $prompt->toArray();
            $prompt->update(['prompt' => trim($data['prompt']), 'version' => $prompt->version + 1]);
            AuditLogger::log('feed_analysis_prompt_updated', before: $before, after: $prompt->toArray(), label: 'Analyseprompt '.$prompt->type);
        });

        return back()->with('success', 'Analyseprompt opgeslagen. Nieuwe analyses gebruiken deze versie.');
    }

    public function retryPhotoAnalysis(FeedScanSubmission $submission, string $photo)
    {
        $slot = array_search($photo, $submission->photo_ids, true);
        abort_if($slot === false, 404);
        app(FeedPhotoAnalysis::class)->analyse($submission, $slot);

        return back()->with('success', 'Fotoanalyse bijgewerkt. Bekijk beide resultaten bij de foto.');
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
