<?php

namespace App\Support;

use App\Enums\ProtocolAdviceLayout;
use App\Models\BewegingAdvies;
use App\Models\ManagementAdvies;
use App\Models\VoedingAdvies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The protocol measure library: one table per category (Voeding, Management, Beweging).
 * Protocols refer to a measure through a snapshot row and, optionally, a customer_settings override.
 */
class ProtocolMeasures
{
    /**
     * @var array<string, array{label: string, description: string, model: class-string<Model>, table: string, snapshot_table: string, foreign_key: string, settings_group: string}>
     */
    public const CATEGORIES = [
        'voeding' => [
            'label' => 'Voeding',
            'description' => 'Maatregelen over rantsoen, ruwvoer en supplementen.',
            'model' => VoedingAdvies::class,
            'table' => 'voeding_adviezen',
            'snapshot_table' => 'protocol_voeding_adviezen',
            'foreign_key' => 'voeding_advies_id',
            'settings_group' => 'nutrition_advice',
        ],
        'management' => [
            'label' => 'Management',
            'description' => 'Maatregelen over huisvesting, routine en herstel.',
            'model' => ManagementAdvies::class,
            'table' => 'management_adviezen',
            'snapshot_table' => 'protocol_management_adviezen',
            'foreign_key' => 'management_advies_id',
            'settings_group' => 'management',
        ],
        'beweging' => [
            'label' => 'Beweging',
            'description' => 'Maatregelen over training, opbouw en bewegingsvrijheid.',
            'model' => BewegingAdvies::class,
            'table' => 'beweging_adviezen',
            'snapshot_table' => 'protocol_beweging_adviezen',
            'foreign_key' => 'beweging_advies_id',
            'settings_group' => 'movement',
        ],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** @return array{label: string, description: string, model: class-string<Model>, table: string, snapshot_table: string, foreign_key: string, settings_group: string} */
    public static function config(string $category): array
    {
        abort_unless(isset(self::CATEGORIES[$category]), 404);

        return self::CATEGORIES[$category];
    }

    /** @return class-string<Model> */
    public static function model(string $category): string
    {
        return self::config($category)['model'];
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function layoutOptions(string $category): array
    {
        return ProtocolAdviceLayout::options($category === 'voeding');
    }

    /**
     * Number of distinct protocols (any status) that refer to each measure of a category, through a
     * snapshot row or a customer_settings override. Keys are lowercase measure ids.
     *
     * @return array<string, int>
     */
    public static function usageCounts(string $category, ?string $id = null): array
    {
        $config = self::config($category);
        $group = $config['settings_group'];

        $rows = DB::select(<<<SQL
            SELECT refs.source_id, COUNT(DISTINCT refs.protocol_id) AS usage
            FROM (
                SELECT LOWER({$config['foreign_key']}::text) AS source_id, protocol_id
                FROM {$config['snapshot_table']}
                UNION ALL
                SELECT LOWER(element ->> 'id') AS source_id, protocols.id AS protocol_id
                FROM protocols
                CROSS JOIN LATERAL json_array_elements(
                    CASE WHEN json_typeof(protocols.customer_settings -> '{$group}') = 'array'
                        THEN protocols.customer_settings -> '{$group}' ELSE '[]'::json END
                ) AS element
                WHERE json_typeof(element) = 'object'
            ) AS refs
            WHERE refs.source_id IS NOT NULL AND (CAST(? AS text) IS NULL OR refs.source_id = LOWER(CAST(? AS text)))
            GROUP BY refs.source_id
        SQL, [$id, $id]);

        return collect($rows)->mapWithKeys(fn (object $row): array => [$row->source_id => (int) $row->usage])->all();
    }

    public static function usageCount(string $category, string $id): int
    {
        return self::usageCounts($category, $id)[strtolower($id)] ?? 0;
    }

    /** Next free position at the end of a category. */
    public static function nextSortOrder(string $category): int
    {
        return ((int) self::model($category)::query()->max('sort_order')) + 1;
    }

    /**
     * Install default measures that are not in the library yet. Admins manage the library, so an existing
     * measure, matched by title in any category, is never overwritten, and a renamed one is not reinstalled.
     *
     * @param  array<int, array{title: string, description: string}>  $items
     */
    public static function seedDefaults(string $category, array $items): void
    {
        DB::transaction(function () use ($category, $items) {
            LibrarySyncLock::acquire();
            $existing = collect(self::CATEGORIES)
                ->flatMap(fn (array $config) => DB::table($config['table'])->pluck('title'))
                ->mapWithKeys(fn (string $title) => [mb_strtolower(trim($title)) => true]);

            foreach ($items as $item) {
                $key = mb_strtolower(trim($item['title']));
                if (isset($existing[$key])) {
                    continue;
                }
                self::model($category)::query()->create([
                    ...$item,
                    'layout' => 'normal',
                    'icon' => null,
                    'sort_order' => self::nextSortOrder($category),
                ]);
                $existing[$key] = true;
            }
        });
    }

    /**
     * Every override in a protocol's customer_settings must point at an existing measure. Validation runs
     * before the save transaction, so a measure deleted or moved in between is caught here, under the lock.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<int, string> Error keys of overrides whose measure no longer exists.
     */
    public static function missingOverrideKeys(?array $settings): array
    {
        $missing = [];
        foreach (self::CATEGORIES as $config) {
            $rows = $settings[$config['settings_group']] ?? null;
            if (! is_array($rows) || $rows === []) {
                continue;
            }
            $ids = collect($rows)->map(fn ($row) => is_array($row) ? strtolower((string) ($row['id'] ?? '')) : '')->all();
            $existing = DB::table($config['table'])->whereIn('id', array_filter($ids))->pluck('id')
                ->mapWithKeys(fn ($id) => [strtolower($id) => true]);
            foreach ($ids as $index => $id) {
                if (! isset($existing[$id])) {
                    $missing[] = "customer_settings.{$config['settings_group']}.{$index}.id";
                }
            }
        }

        return $missing;
    }
}
