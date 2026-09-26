<?php

namespace Tests\Feature\Catalog;

use App\Enums\Role;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_product_and_sku_is_normalised(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/products', [
            'sku' => ' bolt-m8-40 ',
            'name' => 'Hex bolt M8 x 40',
            'unit_price_cents' => 45,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'BOLT-M8-40')
            ->assertJsonPath('data.unit_price_cents', 45)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_sku_must_be_unique_regardless_of_case(): void
    {
        $this->actingAsRole(Role::Admin);
        Product::factory()->create(['sku' => 'BOLT-M8-40']);

        $this->postJson('/api/v1/products', [
            'sku' => 'bolt-m8-40',
            'name' => 'Duplicate',
            'unit_price_cents' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_product_validation(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/products', [
            'sku' => 'has spaces',
            'unit_price_cents' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku', 'name', 'unit_price_cents']);
    }

    public function test_partial_update_only_changes_given_fields(): void
    {
        $this->actingAsRole(Role::Admin);
        $product = Product::factory()->create(['name' => 'Old name', 'unit_price_cents' => 100]);

        $this->patchJson("/api/v1/products/{$product->id}", ['unit_price_cents' => 150])
            ->assertOk()
            ->assertJsonPath('data.name', 'Old name')
            ->assertJsonPath('data.unit_price_cents', 150);
    }

    public function test_products_can_be_searched_filtered_sorted_and_paginated(): void
    {
        $this->actingAsRole(Role::Sales);
        Product::factory()->create(['sku' => 'CABLE-001', 'name' => 'Ethernet cable 2m', 'unit_price_cents' => 500]);
        Product::factory()->create(['sku' => 'CABLE-002', 'name' => 'Ethernet cable 5m', 'unit_price_cents' => 900]);
        Product::factory()->inactive()->create(['sku' => 'CABLE-003', 'name' => 'Ethernet cable 10m', 'unit_price_cents' => 1500]);
        Product::factory()->create(['sku' => 'MOUSE-001', 'name' => 'Wireless mouse']);

        $this->getJson('/api/v1/products?search=cable&is_active=1&sort=-unit_price_cents&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'CABLE-002')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/v1/products?search=cable-0')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/products?max_price_cents=600&search=cable')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'CABLE-001');
    }

    public function test_unknown_sort_columns_fall_back_to_default(): void
    {
        $this->actingAsRole(Role::Sales);
        Product::factory()->create(['name' => 'Beta']);
        Product::factory()->create(['name' => 'Alpha']);

        $this->getJson('/api/v1/products?sort=password')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');
    }

    public function test_per_page_is_capped(): void
    {
        $this->actingAsRole(Role::Sales);

        $this->getJson('/api/v1/products?per_page=5000')->assertJsonPath('meta.per_page', 100);
    }

    public function test_non_admins_cannot_modify_products(): void
    {
        $product = Product::factory()->create();

        foreach ([Role::Sales, Role::WarehouseManager] as $role) {
            $this->actingAsRole($role);

            $this->postJson('/api/v1/products', ['sku' => 'X', 'name' => 'X', 'unit_price_cents' => 1])->assertForbidden();
            $this->patchJson("/api/v1/products/{$product->id}", ['unit_price_cents' => 1])->assertForbidden();
        }

        $this->assertNotSame(1, $product->fresh()->unit_price_cents);
    }

    public function test_guests_cannot_browse_the_catalogue(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }
}
