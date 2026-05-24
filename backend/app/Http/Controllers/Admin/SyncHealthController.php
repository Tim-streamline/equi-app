<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * PowerSync health snapshot. Reads the sync-rules file to surface which
 * tables are synced and flags the sensitive server-owned tables the spec
 * says should be removed from the global stream.
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
                'service_url' => config('services.powersync.url', env('POWERSYNC_URL')),
            ],
            'db' => [
                'connection' => config('database.default'),
                'tables_total' => count(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")),
            ],
        ]);
    }

    private function extractTables(string $raw): array
    {
        preg_match_all('/FROM\s+([a-z_]+)/i', $raw, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
