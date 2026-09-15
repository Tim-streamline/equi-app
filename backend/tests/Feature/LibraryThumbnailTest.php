<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LibraryItem;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LibraryThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.public.url' => 'http://192.168.1.209:81/storage']);
        Storage::fake('public', ['url' => 'http://192.168.1.209:81/storage']);
        Storage::fake('local');
        $this->actingAs(AdminUser::create([
            'name' => 'Editor', 'email' => 'thumbnail@example.test',
            'password' => 'password', 'role' => 'content_editor', 'active' => true,
        ]), 'admin');
    }

    private function video(string $firstColor = 'red'): UploadedFile
    {
        $path = Storage::disk('local')->path('source.mp4');
        // Red first second, blue afterwards: a thumbnail must never pick blue.
        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "color={$firstColor}:s=160x90:d=1:r=10",
            '-f', 'lavfi', '-i', 'color=blue:s=160x90:d=1:r=10', '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path]))->mustRun();

        return new UploadedFile($path, 'lesson.mp4', 'video/mp4', null, true);
    }

    public function test_video_upload_generates_first_second_thumbnail_and_new_item_keeps_it(): void
    {
        $asset = $this->postJson('/admin/library/media', ['file' => $this->video()])->assertOk()->json('asset');
        $this->assertNotEmpty($asset['thumbnail_url'] ?? null);
        $image = imagecreatefromjpeg(Storage::disk('public')->path($asset['thumbnail_path']));
        $this->assertSame(640, imagesx($image));
        $this->assertSame(480, imagesy($image));
        $color = imagecolorsforindex($image, imagecolorat($image, 320, 240));
        $this->assertGreaterThan(200, $color['red']);
        $this->assertLessThan(30, $color['blue']);
        imagedestroy($image);

        $this->post('/admin/library', [
            'title' => 'New video', 'format' => 'video', 'thumbnail_mode' => 'auto',
            'body' => '<video src="'.$asset['url'].'"></video>', 'media_ids' => [$asset['id']],
        ])->assertRedirect(route('admin.library.index'));
        $item = LibraryItem::where('title', 'New video')->firstOrFail();
        $this->assertSame($asset['thumbnail_url'], $item->hero_image_url);
        $this->assertSame($item->id, MediaAsset::findOrFail($asset['id'])->library_item_id);

        $this->put('/admin/library/'.$item->id, ['title' => 'Updated video', 'format' => 'video',
            'body' => $item->body, 'thumbnail_mode' => 'auto', 'hero_image_url' => '',
        ])->assertRedirect();
        $this->assertSame($asset['thumbnail_url'], $item->fresh()->hero_image_url);
    }

    public function test_chunked_video_updates_existing_item_and_does_not_overwrite_manual_thumbnail(): void
    {
        $item = LibraryItem::create(['title' => 'Video', 'slug' => 'video', 'format' => 'video']);
        $contents = $this->video()->get();
        config(['media.chunk_size' => strlen($contents)]);
        $id = $this->withHeaders(['Upload-Length' => strlen($contents), 'X-Library-Item-Id' => $item->id])
            ->post('/admin/library/media/chunks')->assertOk()->getContent();
        $this->call('PATCH', '/admin/library/media/chunks/'.$id, [], [], [], [
            'CONTENT_TYPE' => 'application/offset+octet-stream', 'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_UPLOAD_LENGTH' => strlen($contents), 'HTTP_UPLOAD_NAME' => 'video.mp4',
        ], $contents)->assertNoContent();
        $asset = $this->getJson('/admin/library/media/chunks/'.$id.'/asset')->assertOk()->json('asset');
        $this->assertNotEmpty($asset['thumbnail_url'] ?? null);
        $this->assertSame($asset['thumbnail_url'], $item->fresh()->hero_image_url);

        $item->update(['thumbnail_mode' => 'manual', 'hero_image_url' => 'https://example.test/custom.jpg']);
        $this->postJson('/admin/library/media', ['file' => $this->video(), 'library_item_id' => $item->id])->assertOk();
        $this->assertSame('https://example.test/custom.jpg', $item->fresh()->hero_image_url);
    }

    public function test_manual_thumbnail_can_be_uploaded_replaced_removed_and_reuploaded_for_audio(): void
    {
        $upload = fn () => $this->postJson('/admin/library/media', ['file' => UploadedFile::fake()->image('cover.jpg')])->assertOk()->json('asset');
        $first = $upload();
        $this->post('/admin/library', ['title' => 'Podcast', 'format' => 'audio',
            'thumbnail_mode' => 'manual', 'hero_image_url' => $first['url'], 'media_ids' => [$first['id']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $item = LibraryItem::where('title', 'Podcast')->firstOrFail();
        $this->assertSame($first['url'], $item->hero_image_url);
        $second = $upload();
        foreach ([['manual', $second['url']], ['none', null], ['manual', $first['url']]] as [$mode, $url]) {
            $this->put('/admin/library/'.$item->id, ['title' => 'Podcast', 'format' => 'audio',
                'thumbnail_mode' => $mode, 'hero_image_url' => $url,
            ])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($url, $item->fresh()->hero_image_url);
        }
    }

    public function test_failed_generation_keeps_upload_and_uses_empty_thumbnail_for_fallback(): void
    {
        config(['media.ffmpeg_binary' => '/missing/ffmpeg']);
        $asset = $this->postJson('/admin/library/media', ['file' => $this->video()])->assertOk()->json('asset');
        $this->assertNull($asset['thumbnail_url'] ?? null);
        Storage::disk('public')->assertExists($asset['path']);
    }

    public function test_thumbnail_endpoint_rejects_video_and_failed_save_does_not_claim_media(): void
    {
        $this->postJson('/admin/library/media', ['file' => $this->video(), 'purpose' => 'thumbnail'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $asset = $this->postJson('/admin/library/media', ['file' => UploadedFile::fake()->image('cover.jpg')])
            ->assertOk()->json('asset');
        $this->post('/admin/library', ['title' => '', 'format' => 'article', 'media_ids' => [$asset['id']]])
            ->assertSessionHasErrors('title');
        $this->assertNull(MediaAsset::findOrFail($asset['id'])->library_item_id);
        $this->assertDatabaseCount('library_items', 0);
    }

    public function test_new_item_cannot_claim_another_editors_unassigned_upload(): void
    {
        $asset = $this->postJson('/admin/library/media', ['file' => UploadedFile::fake()->image('cover.jpg')])
            ->assertOk()->json('asset');
        $this->actingAs(AdminUser::create(['name' => 'Other', 'email' => 'other@example.test',
            'password' => 'password', 'role' => 'content_editor', 'active' => true]), 'admin');
        $this->post('/admin/library', ['title' => 'Other', 'format' => 'article', 'media_ids' => [$asset['id']]])
            ->assertSessionHasErrors('media_ids.0');
        $this->assertNull(MediaAsset::findOrFail($asset['id'])->library_item_id);
    }

    public function test_editor_keeps_distinct_video_posters_after_save_reorder_and_reopen_without_changing_manual_cover(): void
    {
        $assets = [];
        foreach (['red', 'green', 'yellow'] as $color) {
            $assets[] = $this->postJson('/admin/library/media', ['file' => $this->video($color)])
                ->assertOk()->json('asset');
        }
        $cover = 'https://example.test/manual-cover.jpg';
        $body = collect($assets)->map(fn ($asset) => '<video src="'.$asset['url'].'"></video>')->implode("\n");
        $this->post('/admin/library', [
            'title' => 'Three lessons', 'format' => 'video', 'thumbnail_mode' => 'manual',
            'hero_image_url' => $cover, 'body' => $body, 'media_ids' => array_column($assets, 'id'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $item = LibraryItem::where('title', 'Three lessons')->firstOrFail();
        $posters = array_column($assets, 'thumbnail_url', 'url');
        $this->assertCount(3, array_unique($posters));

        // An older embedded upload has no item relationship or generated poster.
        $legacy = MediaAsset::findOrFail($assets[1]['id']);
        Storage::disk('public')->delete($legacy->thumbnail_path);
        $legacy->update(['library_item_id' => null, 'thumbnail_path' => null, 'thumbnail_url' => null]);

        $response = $this->get('/admin/library/'.$item->id.'/edit')->assertOk();
        $legacy->refresh();
        $this->assertNotNull($legacy->thumbnail_url);
        $posters[$legacy->url] = $legacy->thumbnail_url;
        $response->assertInertia(fn (Assert $page) => $page->component('Library/Edit')
            ->where('videoPosters', fn ($value) => collect($value)->all() == $posters)
            ->where('item.hero_image_url', $cover));

        $pixels = [];
        foreach ($assets as $asset) {
            $asset = MediaAsset::findOrFail($asset['id']);
            $image = imagecreatefromjpeg(Storage::disk('public')->path($asset->thumbnail_path));
            $pixel = imagecolorsforindex($image, imagecolorat($image, 320, 240));
            $this->assertLessThan(30, $pixel['blue'], 'Posters must come from the first second, before the blue frame.');
            $pixels[] = $pixel;
            imagedestroy($image);
        }
        $this->assertCount(3, array_unique(array_map('json_encode', $pixels)));

        $this->put('/admin/library/'.$item->id, [
            'title' => $item->title, 'format' => 'video', 'thumbnail_mode' => 'manual',
            'hero_image_url' => $cover, 'body' => implode("\n", array_reverse(explode("\n", $body))),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/admin/library/'.$item->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('videoPosters', fn ($value) => collect($value)->all() == $posters)
            ->where('item.hero_image_url', $cover));
        $this->assertSame($cover, $item->fresh()->hero_image_url);
    }
}
