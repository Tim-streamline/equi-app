<?php

namespace Tests\Feature;

use App\Models\LibraryArticleSection;
use App\Models\LibraryCategory;
use App\Models\LibraryChapter;
use App\Models\LibraryItem;
use App\Models\LibraryItemCategory;
use App\Models\MediaAsset;
use App\Models\Therapist;
use App\Models\User;
use App\Support\LibraryDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DeployLibraryItemsTest extends TestCase
{
    use RefreshDatabase;

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = storage_path('framework/testing/library-bundle-'.Str::uuid());
        Storage::fake('public', ['url' => 'https://source.example.test/storage']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->bundle);
        parent::tearDown();
    }

    public function test_export_and_deployment_preserve_all_content_and_rebase_embedded_media(): void
    {
        $source = $this->sourceCatalog();
        $this->artisan('deploy-library-items', ['--export' => true, '--path' => $this->bundle])->assertSuccessful();
        $manifest = $this->manifest();
        $this->assertCount(4, $manifest['files']);
        $this->assertCount(2, $manifest['records']['media_assets']);
        $this->assertArrayNotHasKey('uploaded_by', $manifest['records']['media_assets'][0]);
        $this->assertCount(7, $manifest['records']);
        $this->assertSame('video-bytes', file_get_contents($this->blobFor('library/video/lesson.mp4')));

        $this->emptyCatalog();
        Storage::fake('public', ['url' => 'https://destination.example.test/storage']);
        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertSuccessful();

        $item = LibraryItem::where('slug', 'complete-lesson')->sole();
        $this->assertSame($source->id, $item->id);
        $this->assertSame('Library author', $item->author->name);
        $this->assertSame('video', $item->format);
        $this->assertSame('https://destination.example.test/storage/library/thumbnails/lesson.jpg', $item->hero_image_url);
        $this->assertStringContainsString('src="https://destination.example.test/storage/library/video/lesson.mp4"', $item->body);
        $this->assertStringContainsString('![Inline](https://destination.example.test/storage/legacy/inline.jpg)', $item->body);
        $this->assertStringContainsString('https://external.example.test/page', $item->body);
        $this->assertSame('https://destination.example.test/storage/library/image/cover.jpg', $item->sections->sole()->body);
        $this->assertSame('Introduction', $item->chapters->sole()->title);
        $this->assertSame('nutrition', $item->categories->sole()->slug);
        $this->assertTrue($item->is_plus);
        $this->assertSame(3, $item->credit_cost);
        $this->assertNull($item->published_at);
        $this->assertSame('auto', $item->thumbnail_mode);
        $this->assertNull(MediaAsset::where('type', 'video')->sole()->library_item_id);
        $this->assertSame($item->id, MediaAsset::where('type', 'image')->sole()->library_item_id);
        foreach (['library/video/lesson.mp4' => 'video-bytes', 'library/thumbnails/lesson.jpg' => 'thumbnail-bytes', 'library/image/cover.jpg' => 'image-bytes', 'legacy/inline.jpg' => 'legacy-bytes'] as $path => $content) {
            $this->assertSame($content, Storage::disk('public')->get($path));
        }

        $before = $this->rows();
        $this->travel(1)->day();
        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertSuccessful();
        $this->assertSame($before, $this->rows());
    }

    public function test_existing_slugs_keep_target_ids_and_customer_bookmarks(): void
    {
        $source = $this->sourceCatalog();
        app(LibraryDeployment::class)->export($this->bundle);
        $this->emptyCatalog();
        $author = Therapist::create(['name' => 'Library author', 'title' => 'Therapist', 'bio' => 'Destination biography']);
        $item = LibraryItem::create(['slug' => $source->slug, 'title' => 'Old title', 'format' => 'article']);
        $category = LibraryCategory::create(['slug' => 'nutrition', 'label' => 'Old category']);
        $oldSection = $item->sections()->create(['order' => 5, 'body' => 'Obsolete content']);
        $unrelated = LibraryItem::create(['slug' => 'destination-only', 'title' => 'Keep me', 'format' => 'article']);
        $bookmark = $item->bookmarks()->create(['user_id' => User::factory()->create()->id]);

        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertSuccessful();

        $this->assertSame($item->id, LibraryItem::where('slug', $source->slug)->sole()->id);
        $this->assertSame($item->id, $bookmark->fresh()->item_id);
        $this->assertSame($category->id, $item->fresh()->categories->sole()->id);
        $this->assertSame($author->id, $item->fresh()->author_therapist_id);
        $this->assertSame('Destination biography', $author->fresh()->bio);
        $this->assertNull($oldSection->fresh());
        $this->assertSame('Keep me', $unrelated->fresh()->title);
        $this->assertDatabaseCount('library_items', 2);
    }

    public function test_distinct_content_blocks_with_the_same_order_are_preserved(): void
    {
        $item = $this->sourceCatalog();
        $item->chapters()->create(['order' => 0, 'title' => 'Another chapter', 'start_sec' => 30]);
        $item->sections()->create(['order' => 0, 'body' => 'Another section']);
        app(LibraryDeployment::class)->export($this->bundle);
        $this->emptyCatalog();

        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertSuccessful();
        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertSuccessful();

        $this->assertDatabaseCount('library_chapters', 2);
        $this->assertDatabaseCount('library_article_sections', 2);
        $this->assertDatabaseHas('library_chapters', ['title' => 'Another chapter']);
        $this->assertDatabaseHas('library_article_sections', ['body' => 'Another section']);
    }

    public function test_corrupt_or_missing_files_are_rejected_before_any_import(): void
    {
        $this->sourceCatalog();
        app(LibraryDeployment::class)->export($this->bundle);
        $this->emptyCatalog();
        Storage::fake('public');
        file_put_contents($this->blobFor('library/video/lesson.mp4'), 'corrupted');

        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertFailed();
        $this->assertDatabaseCount('library_items', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());

        unlink($this->blobFor('library/video/lesson.mp4'));
        $this->artisan('deploy-library-items', ['--verify' => true, '--path' => $this->bundle])->assertFailed();
    }

    public function test_destination_file_conflicts_do_not_overwrite_files_or_records(): void
    {
        $this->sourceCatalog();
        app(LibraryDeployment::class)->export($this->bundle);
        $this->emptyCatalog();
        Storage::fake('public');
        Storage::disk('public')->put('library/video/lesson.mp4', 'Destination upload');

        $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertFailed();

        $this->assertDatabaseCount('library_items', 0);
        $this->assertSame('Destination upload', Storage::disk('public')->get('library/video/lesson.mp4'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_record_failure_rolls_back_rows_and_removes_newly_installed_files(): void
    {
        $this->sourceCatalog();
        app(LibraryDeployment::class)->export($this->bundle);
        $this->emptyCatalog();
        Storage::fake('public');
        Storage::disk('public')->put('unrelated.txt', 'Keep me');
        MediaAsset::creating(fn () => throw new RuntimeException('Simulated failure'));
        try {
            $this->artisan('deploy-library-items', ['--path' => $this->bundle])->assertFailed();
        } finally {
            MediaAsset::flushEventListeners();
        }

        $this->assertDatabaseCount('library_items', 0);
        $this->assertDatabaseCount('therapists', 0);
        $this->assertSame(['unrelated.txt'], Storage::disk('public')->allFiles());
    }

    public function test_unsafe_media_paths_and_missing_manifest_references_are_rejected(): void
    {
        $this->sourceCatalog();
        app(LibraryDeployment::class)->export($this->bundle);
        $original = $this->manifest();
        $manifest = $original;
        $manifest['files'][0]['path'] = '../outside.mp4';
        file_put_contents($this->bundle.'/manifest.json', json_encode($manifest));
        $this->artisan('deploy-library-items', ['--verify' => true, '--path' => $this->bundle])->assertFailed();

        $manifest = $original;
        $manifest['records']['library_items'][0]['author_therapist_id'] = (string) Str::uuid();
        file_put_contents($this->bundle.'/manifest.json', json_encode($manifest));
        $this->artisan('deploy-library-items', ['--verify' => true, '--path' => $this->bundle])->assertFailed();
    }

    public function test_failed_export_preserves_the_previous_complete_bundle(): void
    {
        $this->sourceCatalog();
        $this->artisan('deploy-library-items', ['--export' => true, '--path' => $this->bundle])->assertSuccessful();
        $before = file_get_contents($this->bundle.'/manifest.json');
        Storage::disk('public')->delete('library/video/lesson.mp4');

        $this->artisan('deploy-library-items', ['--export' => true, '--path' => $this->bundle])->assertFailed();
        $this->assertSame($before, file_get_contents($this->bundle.'/manifest.json'));
        $this->artisan('deploy-library-items', ['--verify' => true, '--path' => $this->bundle])->assertSuccessful();
    }

    private function sourceCatalog(): LibraryItem
    {
        $author = Therapist::create(['name' => 'Library author', 'title' => 'Therapist', 'bio' => 'Source biography']);
        $category = LibraryCategory::create(['slug' => 'nutrition', 'label' => 'Nutrition', 'is_default' => true]);
        $item = LibraryItem::create([
            'slug' => 'complete-lesson', 'title' => 'Complete lesson', 'format' => 'video', 'author_therapist_id' => $author->id,
            'hero_image_url' => 'https://source.example.test/storage/library/thumbnails/lesson.jpg',
            'body' => '<video src="https://source.example.test/storage/library/video/lesson.mp4"></video> ![Inline](/storage/legacy/inline.jpg) https://external.example.test/page',
            'is_plus' => true, 'credit_cost' => 3, 'published_at' => null,
        ]);
        LibraryItemCategory::create(['item_id' => $item->id, 'category_id' => $category->id]);
        LibraryChapter::create(['item_id' => $item->id, 'order' => 0, 'title' => 'Introduction', 'start_sec' => 12, 'start_label' => '0:12']);
        LibraryArticleSection::create(['item_id' => $item->id, 'order' => 0, 'heading' => 'Details', 'body' => 'https://source.example.test/storage/library/image/cover.jpg']);
        foreach (['library/video/lesson.mp4' => 'video-bytes', 'library/thumbnails/lesson.jpg' => 'thumbnail-bytes', 'library/image/cover.jpg' => 'image-bytes', 'legacy/inline.jpg' => 'legacy-bytes'] as $path => $content) {
            Storage::disk('public')->put($path, $content);
        }
        MediaAsset::create([
            'type' => 'video', 'disk' => 'public', 'path' => 'library/video/lesson.mp4', 'url' => 'https://source.example.test/storage/library/video/lesson.mp4',
            'thumbnail_path' => 'library/thumbnails/lesson.jpg', 'thumbnail_url' => 'https://source.example.test/storage/library/thumbnails/lesson.jpg',
            'original_name' => 'lesson.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 11,
        ]);
        MediaAsset::create([
            'library_item_id' => $item->id, 'type' => 'image', 'disk' => 'public', 'path' => 'library/image/cover.jpg',
            'url' => 'https://source.example.test/storage/library/image/cover.jpg', 'original_name' => 'cover.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 11,
        ]);

        return $item;
    }

    private function emptyCatalog(): void
    {
        MediaAsset::query()->delete();
        LibraryItem::query()->delete();
        LibraryCategory::query()->delete();
        Therapist::query()->delete();
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents($this->bundle.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function blobFor(string $path): string
    {
        $file = collect($this->manifest()['files'])->firstWhere('path', $path);

        return $this->bundle.'/files/'.$file['sha256'];
    }

    private function rows(): array
    {
        return collect(['library_items', 'library_categories', 'library_chapters', 'library_article_sections', 'library_item_categories', 'media_assets', 'therapists'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }
}
