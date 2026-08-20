<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LibraryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChunkedMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config()->set('media.chunk_size', 24);

        $this->admin = AdminUser::create([
            'name' => 'Content Editor',
            'email' => 'chunks@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_it_streams_resumable_chunks_into_a_media_asset(): void
    {
        $item = LibraryItem::create([
            'slug' => 'chunked-image-test',
            'format' => 'article',
            'title' => 'Chunked image test',
        ]);
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        $start = $this->withHeaders([
            'Upload-Length' => (string) strlen($contents),
            'X-Library-Item-Id' => $item->id,
        ])->post('/admin/library/media/chunks');
        $start->assertOk();
        $upload = $start->getContent();

        $offset = 0;
        foreach (str_split($contents, 24) as $chunk) {
            $response = $this->patchChunk($upload, $offset, strlen($contents), 'pixel.png', $chunk);
            $offset += strlen($chunk);

            $response
                ->assertNoContent()
                ->assertHeader('Upload-Offset', (string) $offset);

            if ($offset < strlen($contents)) {
                $this->call('HEAD', "/admin/library/media/chunks/{$upload}")
                    ->assertOk()
                    ->assertHeader('Upload-Offset', (string) $offset);
            }
        }

        $assetResponse = $this->getJson("/admin/library/media/chunks/{$upload}/asset")
            ->assertOk()
            ->assertJsonPath('asset.type', 'image')
            ->assertJsonPath('asset.original_name', 'pixel.png')
            ->assertJsonPath('asset.size_bytes', strlen($contents));

        Storage::disk('public')->assertExists($assetResponse->json('asset.path'));
        $this->assertDatabaseHas('media_assets', [
            'library_item_id' => $item->id,
            'uploaded_by' => $this->admin->id,
            'type' => 'image',
        ]);

        $this->get("/admin/library/{$item->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Library/Edit')
                ->has('item.media', 1)
                ->where('item.media.0.id', $assetResponse->json('asset.id'))
            );
    }

    public function test_an_offset_mismatch_returns_the_current_server_offset(): void
    {
        $upload = $this->startUpload(10);

        $this->patchChunk($upload, 3, 10, 'video.mp4', 'abcd')
            ->assertConflict()
            ->assertHeader('Upload-Offset', '0');
    }

    public function test_a_video_above_the_old_direct_upload_limit_can_start_chunking(): void
    {
        $length = (150 * 1024 * 1024) + 1;

        $upload = $this->startUpload($length);

        $this->call('HEAD', "/admin/library/media/chunks/{$upload}")
            ->assertOk()
            ->assertHeader('Upload-Offset', '0');
    }

    public function test_another_admin_cannot_resume_or_read_an_upload(): void
    {
        $upload = $this->startUpload(10);
        $other = AdminUser::create([
            'name' => 'Other Editor',
            'email' => 'other-chunks@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);

        $this->actingAs($other, 'admin')
            ->call('HEAD', "/admin/library/media/chunks/{$upload}")
            ->assertNotFound();
        $this->getJson("/admin/library/media/chunks/{$upload}/asset")->assertNotFound();
    }

    public function test_an_incomplete_upload_can_be_reverted(): void
    {
        $upload = $this->startUpload(10);
        $this->patchChunk($upload, 0, 10, 'video.mp4', 'abcd')->assertNoContent();

        $this->call(
            'DELETE',
            '/admin/library/media/chunks',
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: $upload,
        )->assertOk();

        Storage::disk('local')->assertMissing("media-chunks/{$upload}/metadata.json");
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_an_upload_larger_than_the_configured_video_limit_is_rejected_before_chunks_are_sent(): void
    {
        config()->set('media.limits.video', 100);
        config()->set('media.limits.audio', 30);
        config()->set('media.limits.image', 10);

        $this->withHeaders(['Upload-Length' => '101'])
            ->postJson('/admin/library/media/chunks')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_the_prune_command_removes_stale_incomplete_uploads(): void
    {
        $upload = $this->startUpload(10);
        $this->travel(25)->hours();

        $this->artisan('media:prune-chunks')
            ->expectsOutput('Deleted 1 stale media upload(s).')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing("media-chunks/{$upload}/metadata.json");
    }

    private function startUpload(int $length): string
    {
        return $this->withHeaders(['Upload-Length' => (string) $length])
            ->post('/admin/library/media/chunks')
            ->assertOk()
            ->getContent();
    }

    private function patchChunk(string $upload, int $offset, int $length, string $name, string $contents): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'PATCH',
            "/admin/library/media/chunks/{$upload}",
            server: [
                'CONTENT_TYPE' => 'application/offset+octet-stream',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_UPLOAD_LENGTH' => (string) $length,
                'HTTP_UPLOAD_NAME' => $name,
            ],
            content: $contents,
        );
    }
}
