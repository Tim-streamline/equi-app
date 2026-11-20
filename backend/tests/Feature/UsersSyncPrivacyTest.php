<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * User rows hold credentials and admin-only columns. Sync rules must project them
 * explicitly: the own account as `users`, share counterparts as `shared_users`.
 * Delivery itself is covered by tests/powersync/users-privacy.mjs.
 */
class UsersSyncPrivacyTest extends TestCase
{
    private const SENSITIVE = ['password', 'remember_token', 'admin_note', 'disabled_at', 'email_verified_at', 'is_test_user'];

    public function test_user_rows_sync_only_through_explicit_allowlists(): void
    {
        $rules = Yaml::parseFile(base_path('powersync/sync_rules.yaml'));
        $queries = collect($rules['streams'])
            ->flatMap(fn (array $stream) => $stream['queries'] ?? [$stream['query']])
            ->map(fn (string $sql) => preg_replace('/\s+/', ' ', trim($sql)))
            ->filter(fn (string $sql) => preg_match('/\bFROM\s+users\b/i', $sql))
            ->values();

        $this->assertCount(2, $queries, 'Expected one self projection and one share-counterpart projection.');

        foreach ($queries as $sql) {
            preg_match('/^SELECT (.+?) FROM users\b/i', $sql, $match);
            $this->assertNotEmpty($match, $sql);
            $columns = array_map('trim', explode(',', $match[1]));
            $this->assertNotContains('*', $columns, $sql);
            $this->assertSame([], array_values(array_intersect($columns, self::SENSITIVE)), $sql);

            if (preg_match('/\bFROM users AS shared_users\b/i', $sql)) {
                $this->assertNotContains('email', $columns, 'Counterparts must not receive email addresses.');
                $this->assertStringContainsString("auth.parameter('subscribed') = 1", $sql);
            } else {
                $this->assertMatchesRegularExpression('/WHERE id = auth\.user_id\(\)$/i', $sql, 'Only the own row may carry account fields.');
            }
        }

        $this->assertSame(1, $queries->filter(fn ($sql) => str_contains($sql, 'AS shared_users'))->count());
    }
}
