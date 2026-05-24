<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * PowerSync health snapshot. Reads the sync-rules file to surface which
 * tables are synced and flags the sensitive server-owned tables the spec
 * says should be removed from the global stream. The `live` prop scrapes the
 * PowerSync Prometheus endpoint for runtime stats (connected clients, etc.)
 * and is lazy so the page can poll it via a partial reload.
 */
class SyncHealthController extends Controller
{
    private const SENSITIVE = ['payments', 'data_exports', 'notification_preferences', 'audit_logs', 'admin_users', 'moderation_reports'];

    public function index(): Response
    {
        $rulesPath = base_path('powersync/sync_rules.yaml');
        $syncedTables = [];
        $globalStream = false;

        if (File::exists($rulesPath)) {
            try {
                $rules = Yaml::parseFile($rulesPath);
                $raw = File::get($rulesPath);
                $globalStream = str_contains($raw, 'SELECT * FROM') || str_contains($raw, "data: ['*']");
                $syncedTables = $this->extractTables($raw);
            } catch (\Throwable) {
                // best-effort parse
            }
        }

        $exposed = array_values(array_intersect(self::SENSITIVE, $syncedTables));

        return Inertia::render('SyncHealth/Index', [
            'config' => [
                'rules_present' => File::exists($rulesPath),
                'global_stream' => $globalStream,
                'synced_tables' => $syncedTables,
                'exposed_sensitive' => $exposed,
                'service_url' => config('services.powersync.url'),
            ],
            'db' => [
                'connection' => config('database.default'),
                'tables_total' => count(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")),
            ],
            // Lazy: only evaluated on first load and on `router.reload({ only: ['live'] })`.
            'live' => fn () => $this->liveMetrics(),
        ]);
    }

    /**
     * Scrape the PowerSync Prometheus endpoint. Returns reachable=false rather
     * than throwing so the page degrades gracefully when the service is down.
     */
    private function liveMetrics(): array
    {
        try {
            $res = Http::timeout(3)->get(config('services.powersync.metrics_url'));

            if (! $res->ok()) {
                return ['reachable' => false];
            }

            $text = $res->body();

            return [
                'reachable' => true,
                'connected_clients' => (int) ($this->metric($text, 'powersync_concurrent_connections') ?? 0),
                'replication_lag_seconds' => $this->metric($text, 'powersync_replication_lag_seconds'),
                'rows_replicated' => $this->metric($text, 'powersync_rows_replicated_total'),
                'data_replicated_bytes' => $this->metric($text, 'powersync_data_replicated_bytes_total'),
                'transactions_replicated' => $this->metric($text, 'powersync_transactions_replicated_total'),
                'scraped_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable) {
            return ['reachable' => false];
        }
    }

    /** Pull a single (optionally labelled) Prometheus sample value by name. */
    private function metric(string $text, string $name): ?float
    {
        if (preg_match('/^'.preg_quote($name, '/').'(?:\{[^}]*\})?\s+([0-9eE.+-]+)$/m', $text, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function extractTables(string $raw): array
    {
        preg_match_all('/FROM\s+([a-z_]+)/i', $raw, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
