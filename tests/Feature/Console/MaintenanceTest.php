<?php

namespace Tests\Feature\Console;

use App\Enums\ReportExportStatus;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function stockThroughTheLedger(): array
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        app(InventoryService::class)->receive($warehouse->id, $product->id, 20, null);

        app(OrderService::class)->place([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'ship_to' => ['name' => 'A', 'line1' => 'B', 'city' => 'C', 'postal_code' => 'D', 'country' => 'GB'],
        ], $this->userWithRole(Role::Sales));

        return [$warehouse, $product];
    }

    public function test_reconcile_passes_when_all_changes_went_through_the_ledger(): void
    {
        $this->stockThroughTheLedger();

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('consistent')
            ->assertSuccessful();
    }

    public function test_reconcile_detects_manual_changes_to_stock_levels(): void
    {
        [$warehouse, $product] = $this->stockThroughTheLedger();

        // Simulate someone "fixing" stock directly in the database.
        StockLevel::where('product_id', $product->id)->update(['on_hand' => 25]);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('do not match the movement ledger')
            ->assertFailed();
    }

    public function test_reconcile_detects_reservations_without_open_orders(): void
    {
        [$warehouse, $product] = $this->stockThroughTheLedger();

        // An order closed without releasing its reservation.
        Order::query()->update(['status' => 'cancelled']);

        $this->artisan('inventory:reconcile', ['--warehouse' => $warehouse->id])
            ->expectsOutputToContain('do not match open orders')
            ->assertFailed();
    }

    public function test_pruning_removes_expired_idempotency_keys_and_old_exports_with_their_files(): void
    {
        Storage::fake(ReportExport::DISK);
        $user = User::factory()->create();

        $this->travel(-2)->days();
        IdempotencyKey::create(['user_id' => $user->id, 'key' => 'old-key-0001', 'request_fingerprint' => str_repeat('a', 64), 'response_status' => 201, 'response_body' => '{}']);
        $this->travel(-6)->days();
        Storage::disk(ReportExport::DISK)->put('reports/old.csv', 'x');
        ReportExport::create(['user_id' => $user->id, 'type' => 'sales', 'parameters' => [], 'status' => ReportExportStatus::Completed, 'file_path' => 'reports/old.csv']);
        $this->travelBack();

        IdempotencyKey::create(['user_id' => $user->id, 'key' => 'new-key-0001', 'request_fingerprint' => str_repeat('b', 64), 'response_status' => 201, 'response_body' => '{}']);

        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class, ReportExport::class]])->assertSuccessful();

        $this->assertSame(['new-key-0001'], IdempotencyKey::pluck('key')->all());
        $this->assertSame(0, ReportExport::count());
        Storage::disk(ReportExport::DISK)->assertMissing('reports/old.csv');
    }

    public function test_maintenance_tasks_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

        $this->assertStringContainsString('inventory:reconcile', $commands);
        $this->assertStringContainsString('model:prune', $commands);
        $this->assertStringContainsString('sanctum:prune-expired', $commands);
    }
}
