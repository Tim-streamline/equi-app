<?php

namespace App\Support;

use App\Models\FeedKnowledgeCard;
use App\Models\FeedRuleVersion;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ScanResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FeedAdminReadModel
{
    public static function products(string $status = ''): Builder
    {
        $query = Product::query();
        if ($status === 'review') {
            $query->where('catalog_status', '!=', 'archived')
                ->where(fn ($q) => $q->where('needs_review', true)->orWhere('catalog_status', 'legacy'));
            self::latestStatus($query, 'needs_info', false);
        } elseif (in_array($status, ['approved', 'needs_info'])) {
            $query->where('catalog_status', '!=', 'archived');
            self::latestStatus($query, $status);
        }

        return $query;
    }

    private static function latestStatus(Builder $query, string $status, bool $exists = true): void
    {
        $callback = fn ($q) => $q->selectRaw('1')->from('product_versions as pv')->whereColumn('pv.product_id', 'products.id')
            ->whereRaw('pv.number = (SELECT MAX(v.number) FROM product_versions v WHERE v.product_id = products.id)')->where('pv.status', $status);
        $exists ? $query->whereExists($callback) : $query->whereNotExists($callback);
    }

    public static function dashboard(): array
    {
        $month = now('Europe/Amsterdam')->startOfMonth();
        $review = self::products('review')->count();
        $missing = self::products('needs_info')->count();
        $ingredients = Ingredient::whereNotIn('catalog_status', ['archived', 'removed'])
            ->where(fn ($q) => $q->where('needs_review', true)->orWhereIn('catalog_status', ['legacy', 'draft']))->count();
        $draftCards = FeedKnowledgeCard::where('status', 'draft')->count();
        $flagged = ScanResult::where('flagged', true)->count();
        $tasks = collect([
            ['count' => $review, 'title' => 'Voerproducten beoordelen', 'url' => '/admin/feed-scanner/products?status=review'],
            ['count' => $missing, 'title' => 'Ontbrekende productinformatie aanvullen', 'url' => '/admin/feed-scanner/products?status=needs_info'],
            ['count' => $ingredients, 'title' => 'Ingrediënten controleren', 'url' => '/admin/feed-scanner/knowledge?tab=ingredients'],
            ['count' => $draftCards, 'title' => 'Conceptkenniskaarten afronden', 'url' => '/admin/feed-scanner/knowledge?tab=nutrition&status=draft'],
            ['count' => $flagged, 'title' => 'Gemarkeerde scans bekijken', 'url' => '/admin/scans?flagged=1'],
        ])->filter(fn ($task) => $task['count'] > 0)->values();

        return ['statistics' => ['review' => $review, 'products' => Product::count(),
            'published_cards' => Ingredient::where('catalog_status', 'published')->count() + FeedKnowledgeCard::where('status', 'published')->count(),
            'month_scans' => ScanResult::where('scanned_at', '>=', $month->copy()->utc())->where('scanned_at', '<', $month->copy()->addMonth()->utc())->count()],
            'monthLabel' => $month->locale('nl')->translatedFormat('F Y'), 'tasks' => $tasks,
            'pendingProducts' => self::products('review')->oldest('created_at')->limit(5)->get()->map(function ($product) {
                $version = FeedCatalog::latestProduct($product);

                return ['id' => $product->id, 'brand' => $version?->data['brand'] ?? $product->brand,
                    'name' => $version?->data['name'] ?? $product->name, 'status' => $version?->status ?? 'legacy'];
            })];
    }

    public static function profiles(): array
    {
        $profiles = DB::table('feed_assessment_profiles')->orderBy('code')->get()->map(function ($profile) {
            $profile->versions = FeedRuleVersion::where('profile_id', $profile->id)->orderByDesc('number')->get();

            return $profile;
        });
        $conflicts = [];
        foreach ($profiles as $profile) {
            foreach ($profile->versions as $version) {
                $state = $profile->active_rule_version_id === $version->id ? 'Actief' : ($version->status === 'draft' ? 'Concept' : 'Historisch');
                foreach ($version->data['sugar_starch_caps'] ?? [] as $tier) {
                    if (isset($tier['max_score']) && (float) $tier['max_score'] === 0.0) {
                        $conflicts[] = ['profile' => $profile->name, 'version' => $version->number, 'state' => $state,
                            'rule' => ($tier['inclusive'] ? 'Vanaf ' : 'Boven ').$tier['threshold'].'% suiker + zetmeel: maximaal 0/10'];
                    }
                }
                foreach (['organic_selenium_cap' => 'Organisch selenium', 'unknown_added_selenium_cap' => 'Toegevoegd selenium met onbekende vorm'] as $key => $label) {
                    if (isset($version->data[$key]) && (float) $version->data[$key] === 0.0) {
                        $conflicts[] = ['profile' => $profile->name, 'version' => $version->number, 'state' => $state, 'rule' => $label.': maximaal 0/10'];
                    }
                }
            }
        }

        return ['profiles' => $profiles, 'scoreConflicts' => $conflicts];
    }
}
