<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::create([
            'name' => 'Catalog Admin', 'email' => 'catalog@example.com',
            'password' => 'password', 'role' => 'admin', 'active' => true,
        ]), 'admin');
    }

    public function test_admin_can_create_and_reopen_ordered_ingredients_with_product_specific_amounts(): void
    {
        $first = Ingredient::create(['name' => 'Linseed']);
        $second = Ingredient::create(['name' => 'Vitamin C']);
        $this->post('/admin/products', $this->payload([
            ['ingredient_id' => $second->id, 'order' => 2, 'amount' => '250 mg'],
            ['ingredient_id' => $first->id, 'order' => 1, 'amount' => '12%'],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->assertSame([$first->id, $second->id], $product->ingredients->modelKeys());
        $this->assertSame('12%', $product->ingredients[0]->pivot->amount);
        $this->assertSame($product->id, $first->products->sole()->id);
        $this->get('/admin/products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Index')
            ->has('ingredientOptions', 2)
            ->where('products.data.0.ingredients.0.id', $first->id)
            ->where('products.data.0.ingredients.0.pivot.order', 1)
            ->where('products.data.0.ingredients.1.pivot.amount', '250 mg'));
        $this->assertCount(2, AuditLog::where('target_id', $product->id)->sole()->after['ingredients']);
    }

    public function test_edit_can_add_remove_reorder_and_clear_without_affecting_other_products(): void
    {
        $first = Ingredient::create(['name' => 'Linseed']);
        $second = Ingredient::create(['name' => 'Vitamin C']);
        $third = Ingredient::create(['name' => 'Magnesium']);
        $product = Product::create(['brand' => 'Brand', 'name' => 'Original']);
        $other = Product::create(['brand' => 'Brand', 'name' => 'Other']);
        $product->ingredients()->attach([$first->id => ['order' => 1, 'amount' => '10%'], $second->id => ['order' => 2, 'amount' => '20 mg']]);
        $other->ingredients()->attach($first, ['order' => 4, 'amount' => '80%']);
        $this->put('/admin/products/'.$product->id, $this->payload([
            ['ingredient_id' => $second->id, 'order' => 1, 'amount' => '30 mg'],
            ['ingredient_id' => $third->id, 'order' => 2, 'amount' => null],
        ]))->assertSessionHasNoErrors();
        $this->assertSame([$second->id, $third->id], $product->fresh()->ingredients->modelKeys());
        $this->assertSame('30 mg', $product->fresh()->ingredients[0]->pivot->amount);
        $this->assertNull($product->fresh()->ingredients[1]->pivot->amount);
        $log = AuditLog::where('target_id', $product->id)->sole();
        $this->assertSame($first->id, $log->before['ingredients'][0]['id']);
        $this->assertSame($second->id, $log->after['ingredients'][0]['id']);
        $this->put('/admin/products/'.$product->id, $this->payload([]))->assertSessionHasNoErrors();
        $this->assertCount(0, $product->fresh()->ingredients);
        $this->assertSame('80%', $other->fresh()->ingredients->sole()->pivot->amount);
        $this->assertDatabaseCount('ingredients', 3);
    }

    public function test_omitted_ingredients_preserves_existing_composition(): void
    {
        $ingredient = Ingredient::create(['name' => 'Linseed']);
        $product = Product::create(['brand' => 'Brand', 'name' => 'Original']);
        $product->ingredients()->attach($ingredient, ['order' => 1, 'amount' => '10%']);
        $this->put('/admin/products/'.$product->id, ['brand' => 'Brand', 'name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('10%', $product->fresh()->ingredients->sole()->pivot->amount);
    }

    public function test_invalid_composition_is_rejected_without_writing_product_or_links(): void
    {
        $ingredient = Ingredient::create(['name' => 'Linseed']);
        $cases = [
            [[['ingredient_id' => Str::uuid()->toString(), 'order' => 1]], 'ingredients.0.ingredient_id'],
            [[['ingredient_id' => 'invalid', 'order' => 1]], 'ingredients.0.ingredient_id'],
            [[['ingredient_id' => $ingredient->id, 'order' => 1], ['ingredient_id' => $ingredient->id, 'order' => 2]], 'ingredients.0.ingredient_id'],
            [[['ingredient_id' => $ingredient->id, 'order' => 0]], 'ingredients.0.order'],
            [[['ingredient_id' => $ingredient->id, 'order' => 1.5]], 'ingredients.0.order'],
            [[['ingredient_id' => $ingredient->id, 'order' => 1, 'amount' => str_repeat('x', 256)]], 'ingredients.0.amount'],
        ];
        foreach ($cases as [$rows, $error]) {
            $this->post('/admin/products', $this->payload($rows))->assertSessionHasErrors($error);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('ingredient_product', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_orders_reject_update_and_preserve_existing_data(): void
    {
        $first = Ingredient::create(['name' => 'Linseed']);
        $second = Ingredient::create(['name' => 'Vitamin C']);
        $product = Product::create(['brand' => 'Brand', 'name' => 'Original']);
        $product->ingredients()->attach($first, ['order' => 1, 'amount' => '10%']);
        $this->put('/admin/products/'.$product->id, $this->payload([
            ['ingredient_id' => $first->id, 'order' => 1],
            ['ingredient_id' => $second->id, 'order' => 1],
        ]))->assertSessionHasErrors('ingredients.0.order');
        $this->assertSame('Original', $product->fresh()->name);
        $this->assertSame('10%', $product->fresh()->ingredients->sole()->pivot->amount);
    }

    public function test_product_deletion_cleans_links_but_preserves_shared_ingredients(): void
    {
        $ingredient = Ingredient::create(['name' => 'Linseed']);
        $product = Product::create(['brand' => 'Brand', 'name' => 'Original']);
        $product->ingredients()->attach($ingredient, ['order' => 1]);
        $this->delete('/admin/products/'.$product->id)->assertRedirect();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('ingredient_product', 0);
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id]);
    }

    public function test_save_rolls_back_product_and_ingredients_if_audit_fails(): void
    {
        $ingredient = Ingredient::create(['name' => 'Linseed']);
        AuditLog::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            $this->post('/admin/products', $this->payload([
                ['ingredient_id' => $ingredient->id, 'order' => 1],
            ]))->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('ingredient_product', 0);
    }

    private function payload(array $ingredients): array
    {
        return ['brand' => 'Brand', 'name' => 'Feed', 'ingredients' => $ingredients];
    }
}
