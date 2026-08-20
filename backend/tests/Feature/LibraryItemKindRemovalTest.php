<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LibraryItemKindRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_items_no_longer_have_or_require_a_kind(): void
    {
        $admin = AdminUser::create([
            'name' => 'Content Editor',
            'email' => 'library-editor@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);

        $this->assertFalse(Schema::hasColumn('library_items', 'kind'));

        $this->actingAs($admin, 'admin')
            ->post('/admin/library', [
                'title' => 'Library item without kind',
                'format' => 'article',
            ])
            ->assertRedirect(route('admin.library.index'));

        $this->assertDatabaseHas('library_items', [
            'title' => 'Library item without kind',
            'format' => 'article',
        ]);
    }
}
