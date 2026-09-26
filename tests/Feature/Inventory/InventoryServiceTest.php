<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(InventoryService::class);
    }

    public function test_receiving_creates_the_stock_level_and_a_ledger_entry(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $movement = $this->inventory->receive($warehouse->id, $product->id, 40, null, 'PO-1001');

        $this->assertSame(StockMovementType::Receipt, $movement->type);
        $this->assertSame(40, $movement->on_hand_after);
        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'on_hand' => 40,
            'reserved' => 0,
        ]);
    }

    public function test_negative_adjustment_cannot_eat_into_reserved_stock(): void
    {
        $level = StockLevel::factory()->quantity(10, 8)->create();

        try {
            $this->inventory->adjust($level->warehouse_id, $level->product_id, -3, null, 'Damaged');
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame([['product_id' => $level->product_id, 'requested' => 3, 'available' => 2]], $e->shortages);
        }

        $this->assertSame(10, $level->fresh()->on_hand);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_reservation_is_all_or_nothing_and_reports_every_shortage(): void
    {
        $warehouse = Warehouse::factory()->create();
        $plenty = StockLevel::factory()->for($warehouse)->quantity(100)->create();
        $scarce = StockLevel::factory()->for($warehouse)->quantity(2)->create();
        $missing = Product::factory()->create();
        $order = Order::factory()->for($warehouse)->create();

        try {
            $this->inventory->reserve($warehouse->id, [
                $plenty->product_id => 5,
                $scarce->product_id => 3,
                $missing->id => 1,
            ], $order, null);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertEqualsCanonicalizing([
                ['product_id' => $scarce->product_id, 'requested' => 3, 'available' => 2],
                ['product_id' => $missing->id, 'requested' => 1, 'available' => 0],
            ], $e->shortages);
        }

        $this->assertSame(0, $plenty->fresh()->reserved, 'No line may be reserved when another line fails.');
    }

    public function test_reserve_release_and_ship_keep_quantities_consistent(): void
    {
        $level = StockLevel::factory()->quantity(20)->create();
        $order = Order::factory()->create(['warehouse_id' => $level->warehouse_id]);
        $lines = [$level->product_id => 6];

        $this->inventory->reserve($level->warehouse_id, $lines, $order, null);
        $this->assertSame([20, 6], [$level->fresh()->on_hand, $level->fresh()->reserved]);

        $this->inventory->release($level->warehouse_id, [$level->product_id => 2], $order, null);
        $this->assertSame([20, 4], [$level->fresh()->on_hand, $level->fresh()->reserved]);

        $this->inventory->ship($level->warehouse_id, [$level->product_id => 4], $order, null);
        $this->assertSame([16, 0], [$level->fresh()->on_hand, $level->fresh()->reserved]);

        $movements = StockMovement::where('reference_type', 'order')->where('reference_id', $order->id)->get();
        $this->assertSame(['reservation', 'release', 'shipment'], $movements->pluck('type')->map->value->all());
    }

    public function test_ledger_deltas_always_sum_to_the_current_level(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($warehouse)->create();

        $this->inventory->receive($warehouse->id, $product->id, 50, null);
        $this->inventory->adjust($warehouse->id, $product->id, -5, null, 'Cycle count');
        $this->inventory->reserve($warehouse->id, [$product->id => 12], $order, null);
        $this->inventory->ship($warehouse->id, [$product->id => 12], $order, null);
        $this->inventory->receive($warehouse->id, $product->id, 7, null);

        $level = StockLevel::where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->sole();
        $totals = StockMovement::where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])
            ->selectRaw('SUM(on_hand_delta) as on_hand, SUM(reserved_delta) as reserved')
            ->first();

        $this->assertSame(40, $level->on_hand);
        $this->assertSame($level->on_hand, (int) $totals->on_hand);
        $this->assertSame($level->reserved, (int) $totals->reserved);
    }

    public function test_non_positive_quantities_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inventory->receive(1, 1, 0, null);
    }
}
