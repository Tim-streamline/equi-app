<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LibraryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_editor_can_upload_a_video_at_the_documented_size_limit(): void
    {
        Storage::fake('public');

        $admin = AdminUser::create([
            'name' => 'Content Editor',
            'email' => 'editor@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);
        $item = LibraryItem::create([
            'slug' => 'video-upload-test',
            'format' => 'video',
            'title' => 'Video upload test',
        ]);
        $video = UploadedFile::fake()->create('lesson.mp4', 150 * 1024, 'video/mp4');

        $response = $this->actingAs($admin, 'admin')->postJson('/admin/library/media', [
            'file' => $video,
            'library_item_id' => $item->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('asset.type', 'video')
            ->assertJsonPath('asset.original_name', 'lesson.mp4')
            ->assertJsonPath('asset.size_bytes', 150 * 1024 * 1024);

        Storage::disk('public')->assertExists($response->json('asset.path'));
        $this->assertDatabaseHas('media_assets', [
            'library_item_id' => $item->id,
            'uploaded_by' => $admin->id,
            'type' => 'video',
        ]);
    }

    public function test_video_larger_than_the_documented_limit_is_rejected(): void
    {
        Storage::fake('public');

        $admin = AdminUser::create([
            'name' => 'Content Editor',
            'email' => 'editor@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);
        $video = UploadedFile::fake()->create('too-large.mp4', (150 * 1024) + 1, 'video/mp4');

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/library/media', ['file' => $video])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('media_assets', 0);
    }
}
