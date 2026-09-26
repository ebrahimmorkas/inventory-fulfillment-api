<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Reports\InventoryValuationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valuation_aggregates_quantity_and_value_per_warehouse(): void
    {
        [$london, $leeds] = [
            Warehouse::factory()->create(['code' => 'A-LON']),
            Warehouse::factory()->create(['code' => 'B-LDS']),
        ];
        $widget = Product::factory()->create(['unit_price_cents' => 250]);
        $gadget = Product::factory()->create(['unit_price_cents' => 1000]);
        StockLevel::factory()->for($london)->for($widget)->quantity(10, 4)->create(['reorder_point' => 8]);
        StockLevel::factory()->for($london)->for($gadget)->quantity(3)->create(['reorder_point' => 0]);
        StockLevel::factory()->for($leeds)->for($widget)->quantity(100)->create();
        $this->actingAsRole(Role::Admin);

        $this->getJson('/api/v1/reports/inventory-valuation')
            ->assertOk()
            ->assertJsonPath('data.warehouses.0.code', 'A-LON')
            ->assertJsonPath('data.warehouses.0.sku_count', 2)
            ->assertJsonPath('data.warehouses.0.units_on_hand', 13)
            ->assertJsonPath('data.warehouses.0.units_reserved', 4)
            ->assertJsonPath('data.warehouses.0.value_cents', 10 * 250 + 3 * 1000)
            ->assertJsonPath('data.warehouses.0.items_below_reorder_point', 1)
            ->assertJsonPath('data.totals.units_on_hand', 113)
            ->assertJsonPath('data.totals.value_cents', 5500 + 25000);
    }

    public function test_managers_only_see_their_warehouses_from_the_shared_cache_entry(): void
    {
        [$assigned, $other] = Warehouse::factory()->count(2)->create();
        StockLevel::factory()->for($assigned)->create();
        StockLevel::factory()->for($other)->create();

        $this->actingAsRole(Role::Admin);
        $this->getJson('/api/v1/reports/inventory-valuation')->assertJsonCount(2, 'data.warehouses');

        $this->actingAsRole(Role::WarehouseManager, [$assigned]);
        $this->getJson('/api/v1/reports/inventory-valuation')
            ->assertJsonCount(1, 'data.warehouses')
            ->assertJsonPath('data.warehouses.0.warehouse_id', $assigned->id);
    }

    public function test_result_is_cached_between_requests(): void
    {
        StockLevel::factory()->quantity(10)->create();
        $this->actingAsRole(Role::Admin);

        $this->getJson('/api/v1/reports/inventory-valuation');
        $this->assertTrue(Cache::has(InventoryValuationReport::CACHE_KEY));

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/inventory-valuation')->assertOk();
        $aggregateQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'SUM('));

        $this->assertCount(0, $aggregateQueries, 'The second request should be served from cache.');
    }

    public function test_sales_cannot_view_reports(): void
    {
        $this->actingAsRole(Role::Sales);

        $this->getJson('/api/v1/reports/inventory-valuation')->assertForbidden();
    }
}
