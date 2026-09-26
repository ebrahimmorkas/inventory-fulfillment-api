<?php

namespace Tests\Feature\Inventory;

use App\Models\Order;
use App\Models\StockLevel;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * These tests need data that is visible to other database connections, so they
 * commit real rows instead of using RefreshDatabase's wrapping transaction and
 * clean up after themselves.
 */
class ConcurrentReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
    }

    protected function tearDown(): void
    {
        foreach (['stock_movements', 'stock_levels', 'order_items', 'orders', 'customers', 'products', 'warehouses'] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_reservation_blocks_while_another_transaction_holds_the_stock_row(): void
    {
        $level = StockLevel::factory()->quantity(10)->create();
        $order = Order::factory()->create(['warehouse_id' => $level->warehouse_id]);

        config(['database.connections.contender' => config('database.connections.mysql')]);
        $contender = DB::connection('contender');
        $contender->beginTransaction();
        $contender->table('stock_levels')->where('id', $level->id)->lockForUpdate()->first();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(InventoryService::class)->reserve($level->warehouse_id, [$level->product_id => 5], $order, null);
            $this->fail('The reservation should have waited for the row lock.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1], 'Expected MySQL lock wait timeout (1205).');
        } finally {
            $contender->rollBack();
            DB::purge('contender');
        }

        // Once the other transaction finishes, the same reservation succeeds.
        app(InventoryService::class)->reserve($level->warehouse_id, [$level->product_id => 5], $order, null);
        $this->assertSame(5, $level->fresh()->reserved);
    }

    public function test_parallel_processes_cannot_oversell_the_last_units(): void
    {
        $level = StockLevel::factory()->quantity(10)->create();
        $orders = Order::factory()->count(6)->create(['warehouse_id' => $level->warehouse_id]);

        // Six separate PHP processes each try to reserve 3 of the 10 units.
        $results = Process::pool(function ($pool) use ($level, $orders) {
            foreach ($orders as $order) {
                $pool->path(base_path())->command([
                    PHP_BINARY, 'tests/Support/reserve-stock.php',
                    $level->warehouse_id, $level->product_id, 3, $order->id,
                ]);
            }
        })->start()->wait();

        $outcomes = collect($results)->map(fn ($result) => trim($result->output()))->countBy();

        // Key order depends on which process finishes first, so compare counts individually.
        $this->assertSame(3, $outcomes->get('reserved', 0));
        $this->assertSame(3, $outcomes->get('insufficient', 0));
        $this->assertSame(9, $level->fresh()->reserved);
        $this->assertSame(3, DB::table('stock_movements')->where('type', 'reservation')->count());
    }
}
