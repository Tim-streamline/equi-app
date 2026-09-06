<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LibraryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminLibraryCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_editor_can_open_category_management_and_create_a_category(): void
    {
        $admin = AdminUser::create([
            'name' => 'Library editor',
            'email' => 'library-editor@example.test',
            'password' => 'password',
            'role' => 'content_editor',
            'active' => true,
        ]);
        LibraryCategory::create(['label' => 'Bestaand', 'slug' => 'bestaand', 'order' => 2]);

        $this->actingAs($admin, 'admin')->get('/admin/library-categories')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LibraryCategories/Index')
                ->has('categories', 1));

        $this->actingAs($admin, 'admin')->post('/admin/library-categories', [
            'label' => 'Nieuwe categorie',
            'slug' => '',
            'order' => 3,
            'is_default' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('library_categories', [
            'label' => 'Nieuwe categorie',
            'slug' => 'nieuwe-categorie',
            'order' => 3,
            'is_default' => false,
        ]);
    }
}
