<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FocusTopicsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_focus_topic_storage_routes_and_sync_streams_are_removed(): void
    {
        $this->assertFalse(Schema::hasTable('focus_topics'));
        $this->assertFalse(Schema::hasTable('horse_focus'));
        $this->assertFalse(Schema::hasTable('library_item_focus'));
        $this->assertFalse(Route::has('admin.focus-topics.index'));

        $rules = file_get_contents(base_path('powersync/sync_rules.yaml'));
        $benchmarkPayload = file_get_contents(base_path('database/fixtures/sync-benchmark-payload.json'));

        $this->assertStringNotContainsString('focus_topics', $rules);
        $this->assertStringNotContainsString('horse_focus', $rules);
        $this->assertStringNotContainsString('library_item_focus', $rules);
        $this->assertStringNotContainsString('focus_topics', $benchmarkPayload);
    }
}
