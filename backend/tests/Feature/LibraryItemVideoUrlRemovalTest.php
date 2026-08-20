<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LibraryItemVideoUrlRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_items_no_longer_have_or_require_a_video_url(): void
    {
        $admin = AdminUser::create([
            'name' => 'Content Editor',
            'email' => 'video-library-editor@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);

        $this->assertFalse(Schema::hasColumn('library_items', 'video_url'));

        $this->actingAs($admin, 'admin')
            ->post('/admin/library', [
                'title' => 'Video stored in body media',
                'format' => 'video',
                'body' => '<video src="/storage/library/video/example.mp4" controls></video>',
            ])
            ->assertRedirect(route('admin.library.index'));

        $this->assertDatabaseHas('library_items', [
            'title' => 'Video stored in body media',
            'format' => 'video',
        ]);
    }
}
