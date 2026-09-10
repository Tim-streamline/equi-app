<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LibraryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryVideoDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create(['name' => 'Editor', 'email' => 'duration@example.test',
            'password' => 'password', 'role' => 'content_editor', 'active' => true]), 'admin');
    }

    public function test_video_minutes_are_saved_as_a_label_and_seconds_and_can_be_edited(): void
    {
        $this->post('/admin/library', ['title' => 'Duration', 'format' => 'video', 'duration_minutes' => 12])
            ->assertSessionHasNoErrors()->assertRedirect();
        $item = LibraryItem::where('title', 'Duration')->firstOrFail();
        $this->assertSame('12 min', $item->duration_label);
        $this->assertSame(720, $item->duration_sec);
        $this->put('/admin/library/'.$item->id, ['title' => 'Duration', 'format' => 'video', 'duration_minutes' => 2.5])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2.5 min', $item->fresh()->duration_label);
        $this->assertSame(150, $item->fresh()->duration_sec);
        $this->put('/admin/library/'.$item->id, ['title' => 'Duration', 'format' => 'video', 'duration_minutes' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->duration_label);
        $this->assertNull($item->fresh()->duration_sec);
    }

    public function test_invalid_minutes_are_rejected_without_changing_the_video(): void
    {
        $item = LibraryItem::create(['title' => 'Duration', 'slug' => 'duration', 'format' => 'video',
            'duration_label' => '12 min', 'duration_sec' => 720]);
        foreach ([0, -2, 'abc', '12 min', 'NaN', 999999999, 0.001] as $invalid) {
            $this->put('/admin/library/'.$item->id, ['title' => 'Duration', 'format' => 'video', 'duration_minutes' => $invalid])
                ->assertSessionHasErrors('duration_minutes');
            $this->assertSame('12 min', $item->fresh()->duration_label);
            $this->assertSame(720, $item->fresh()->duration_sec);
        }
    }

    public function test_conversion_preserves_unrecognised_labels_and_other_formats(): void
    {
        foreach (['12' => '12 min', '7 min' => '7 min', '5 min · Video' => '5 min', '2,5 minuten' => '2.5 min', 'onbekend' => 'onbekend'] as $before => $after) {
            $item = LibraryItem::create(['title' => (string) $before, 'slug' => uniqid(), 'format' => 'video', 'duration_label' => (string) $before]);
            $items[] = [$item, $after];
        }
        $article = LibraryItem::create(['title' => 'Article', 'slug' => 'article', 'format' => 'article', 'duration_label' => '5 min · lezen']);
        $secondsOnly = LibraryItem::create(['title' => 'Seconds', 'slug' => 'seconds', 'format' => 'video', 'duration_sec' => 90]);
        $migration = require database_path('migrations/2026_09_10_190000_normalize_library_video_durations.php');
        $migration->up();
        foreach ($items as [$item, $after]) {
            $this->assertSame($after, $item->fresh()->duration_label);
        }
        $this->assertSame('5 min · lezen', $article->fresh()->duration_label);
        $this->assertSame('1.5 min', $secondsOnly->fresh()->duration_label);
    }
}
