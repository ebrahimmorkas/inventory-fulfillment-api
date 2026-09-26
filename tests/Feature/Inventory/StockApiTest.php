<?php

namespace Tests\Feature\Inventory;

use App\Enums\Role;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_receive_stock_into_an_assigned_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $manager = $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $this->postJson("/api/v1/warehouses/{$warehouse->id}/stock-receipts", [
            'product_id' => $product->id,
            'quantity' => 25,
            'reason' => 'PO-4471',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'receipt')
            ->assertJsonPath('data.on_hand_after', 25);

        $this->assertDatabaseHas('stock_movements', ['user_id' => $manager->id, 'reason' => 'PO-4471']);
    }

    public function test_manager_cannot_touch_stock_in_a_warehouse_they_are_not_assigned_to(): void
    {
        [$assigned, $other] = Warehouse::factory()->count(2)->create();
        $level = StockLevel::factory()->for($other)->quantity(10)->create();
        $this->actingAsRole(Role::WarehouseManager, [$assigned]);

        $this->postJson("/api/v1/warehouses/{$other->id}/stock-receipts", ['product_id' => $level->product_id, 'quantity' => 5])->assertForbidden();
        $this->postJson("/api/v1/warehouses/{$other->id}/stock-adjustments", ['product_id' => $level->product_id, 'quantity_delta' => -5, 'reason' => 'x'])->assertForbidden();
        $this->getJson("/api/v1/warehouses/{$other->id}/stock")->assertForbidden();
        $this->getJson("/api/v1/warehouses/{$other->id}/stock-movements")->assertForbidden();

        $this->assertSame(10, $level->fresh()->on_hand);
    }

    public function test_sales_can_view_stock_everywhere_but_not_change_it(): void
    {
        $level = StockLevel::factory()->quantity(10)->create();
        $this->actingAsRole(Role::Sales);

        $this->getJson("/api/v1/warehouses/{$level->warehouse_id}/stock")->assertOk();
        $this->postJson("/api/v1/warehouses/{$level->warehouse_id}/stock-receipts", ['product_id' => $level->product_id, 'quantity' => 5])->assertForbidden();
    }

    public function test_adjustments_require_a_reason_and_a_non_zero_delta(): void
    {
        $warehouse = Warehouse::factory()->create();
        $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $this->postJson("/api/v1/warehouses/{$warehouse->id}/stock-adjustments", ['product_id' => 999, 'quantity_delta' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id', 'quantity_delta', 'reason']);
    }

    public function test_adjustment_below_reserved_stock_returns_409_with_details(): void
    {
        $level = StockLevel::factory()->quantity(10, 9)->create();
        $this->actingAsRole(Role::WarehouseManager, [$level->warehouse]);

        $this->postJson("/api/v1/warehouses/{$level->warehouse_id}/stock-adjustments", [
            'product_id' => $level->product_id,
            'quantity_delta' => -4,
            'reason' => 'Shrinkage',
        ])->assertConflict()
            ->assertJsonPath('shortages.0.available', 1);
    }

    public function test_stock_listing_can_filter_items_below_reorder_point(): void
    {
        $warehouse = Warehouse::factory()->create();
        StockLevel::factory()->for($warehouse)->quantity(5)->create(['reorder_point' => 10]);
        StockLevel::factory()->for($warehouse)->quantity(50)->create(['reorder_point' => 10]);
        StockLevel::factory()->for($warehouse)->quantity(0)->create(['reorder_point' => 0]);
        $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $this->getJson("/api/v1/warehouses/{$warehouse->id}/stock?below_reorder_point=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.available', 5)
            ->assertJsonPath('data.0.below_reorder_point', true);

        $this->getJson("/api/v1/warehouses/{$warehouse->id}/stock?in_stock=0")->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/warehouses/{$warehouse->id}/stock?sort=-available")->assertJsonPath('data.0.available', 50);
    }

    public function test_product_stock_only_includes_accessible_warehouses(): void
    {
        [$assigned, $other] = Warehouse::factory()->count(2)->create();
        $product = Product::factory()->create();
        StockLevel::factory()->for($assigned)->for($product)->create();
        StockLevel::factory()->for($other)->for($product)->create();
        $this->actingAsRole(Role::WarehouseManager, [$assigned]);

        $this->getJson("/api/v1/products/{$product->id}/stock")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.warehouse.id', $assigned->id);
    }

    public function test_manager_can_set_a_reorder_point(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}/stock/{$product->id}", ['reorder_point' => 15])
            ->assertOk()
            ->assertJsonPath('data.reorder_point', 15);
    }

    public function test_ledger_is_cursor_paginated_newest_first_and_filterable(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $inventory = app(InventoryService::class);
        $inventory->receive($warehouse->id, $product->id, 10, null);
        $inventory->receive($warehouse->id, $product->id, 20, null);
        $inventory->adjust($warehouse->id, $product->id, -1, null, 'Count');
        $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $page = $this->getJson("/api/v1/warehouses/{$warehouse->id}/stock-movements?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'adjustment')
            ->assertJsonPath('data.0.product.sku', $product->sku);

        $this->getJson($page->json('links.next'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.on_hand_after', 10);

        $this->getJson("/api/v1/warehouses/{$warehouse->id}/stock-movements?type=receipt")
            ->assertJsonCount(2, 'data');
    }
}
