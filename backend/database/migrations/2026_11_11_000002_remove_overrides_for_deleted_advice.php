<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Library advice is archived from now on, never deleted. This clears the protocol overrides left
 * behind by earlier deletions, which would otherwise fail validation on every save of their protocol,
 * and lowercases the remaining ids: overrides are matched to their advice by exact string.
 */
return new class extends Migration
{
    private const GROUPS = [
        'nutrition_advice' => 'voeding_adviezen',
        'management' => 'management_adviezen',
        'movement' => 'beweging_adviezen',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // Match LibrarySyncLock and the protocol/library triggers before locking protocol rows.
            DB::select('SELECT pg_advisory_xact_lock(724119301)');
            $existing = array_map(fn (string $table): array => DB::table($table)->pluck('id', 'id')->all(), self::GROUPS);

            foreach (DB::table('protocols')->whereNotNull('customer_settings')->orderBy('id')->lockForUpdate()->get(['id', 'customer_settings']) as $protocol) {
                $settings = json_decode($protocol->customer_settings, true);
                if (! is_array($settings)) {
                    continue;
                }
                $cleaned = $settings;
                foreach ($existing as $group => $ids) {
                    if (is_array($settings[$group] ?? null)) {
                        $rows = array_filter($settings[$group], fn ($row) => isset($ids[strtolower($row['id'] ?? '')]));
                        $cleaned[$group] = array_values(array_map(fn ($row) => array_replace($row, ['id' => strtolower($row['id'])]), $rows));
                    }
                }
                if ($cleaned !== $settings) {
                    DB::table('protocols')->where('id', $protocol->id)->update(['customer_settings' => json_encode($cleaned)]);
                }
            }
        });
    }

    public function down(): void
    {
        // The removed overrides pointed at deleted advice and cannot be restored.
    }
};
