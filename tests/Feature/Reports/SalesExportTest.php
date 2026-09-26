<?php

namespace Tests\Feature\Reports;

use App\Enums\ReportExportStatus;
use App\Enums\Role;
use App\Jobs\GenerateSalesReport;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\ReportReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ReportExport::DISK);
    }

    private function shippedOrder(Warehouse $warehouse, string $shippedAt, array $customer = []): Order
    {
        $order = Order::factory()->shipped()->for($warehouse)
            ->for(Customer::factory()->state($customer))
            ->create(['shipped_at' => $shippedAt]);
        $product = Product::factory()->create(['sku' => 'SKU-'.$order->id]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price_cents' => 1250, 'line_total_cents' => 3750]);

        return $order;
    }

    public function test_requesting_an_export_queues_the_job_and_returns_202(): void
    {
        Queue::fake();
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.download_url', null);

        Queue::assertPushedOn('reports', GenerateSalesReport::class);
    }

    public function test_job_writes_a_csv_limited_to_the_requested_range_and_warehouses(): void
    {
        Notification::fake();
        [$warehouse, $other] = Warehouse::factory()->count(2)->create();
        $inRange = $this->shippedOrder($warehouse, '2026-03-10 14:00:00');
        $this->shippedOrder($warehouse, '2026-04-01 09:00:00');          // outside range
        $this->shippedOrder($other, '2026-03-11 10:00:00');              // other warehouse
        Order::factory()->for($warehouse)->create();                     // not shipped
        $manager = $this->actingAsRole(Role::WarehouseManager, [$warehouse]);

        $id = $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertAccepted()
            ->json('data.id');

        // The queue runs synchronously in tests, so the export is already complete.
        $export = ReportExport::findOrFail($id);
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame(1, $export->row_count);

        $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk(ReportExport::DISK)->get($export->file_path))));
        $this->assertSame('order_number', $lines[0][0]);
        $this->assertCount(2, $lines);
        $this->assertSame([$inRange->number, '3', '12.50', '37.50'], [$lines[1][0], $lines[1][7], $lines[1][8], $lines[1][9]]);

        Notification::assertSentTo($manager, ReportReadyNotification::class);
    }

    public function test_spreadsheet_formulas_in_customer_data_are_neutralised(): void
    {
        Notification::fake();
        $warehouse = Warehouse::factory()->create();
        $this->shippedOrder($warehouse, '2026-03-10 14:00:00', ['name' => '=HYPERLINK("http://evil.test")']);
        $user = $this->userWithRole(Role::Admin);

        $export = ReportExport::create([
            'user_id' => $user->id, 'type' => 'sales', 'status' => ReportExportStatus::Pending,
            'parameters' => ['from' => '2026-03-01', 'to' => '2026-03-31', 'warehouse_ids' => null],
        ]);
        (new GenerateSalesReport($export))->handle();

        $csv = Storage::disk(ReportExport::DISK)->get($export->fresh()->file_path);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_only_the_owner_can_view_or_download_an_export(): void
    {
        Notification::fake();
        $owner = $this->actingAsRole(Role::Admin);
        $id = $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-03-01', 'to' => '2026-03-31'])->json('data.id');

        $this->getJson("/api/v1/reports/sales-exports/{$id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->actingAsRole(Role::Admin);
        $this->getJson("/api/v1/reports/sales-exports/{$id}")->assertForbidden();
        $this->getJson("/api/v1/reports/sales-exports/{$id}/download")->assertForbidden();
        $this->getJson('/api/v1/reports/sales-exports')->assertJsonPath('meta.total', 0);
    }

    public function test_download_before_completion_returns_409(): void
    {
        Queue::fake();
        $this->actingAsRole(Role::Admin);
        $id = $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-03-01', 'to' => '2026-03-31'])->json('data.id');

        $this->getJson("/api/v1/reports/sales-exports/{$id}/download")->assertConflict();
    }

    public function test_export_validation_and_permissions(): void
    {
        Queue::fake();
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/reports/sales-exports', ['from' => '2024-01-01', 'to' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-02-01', 'to' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->actingAsRole(Role::Sales);
        $this->postJson('/api/v1/reports/sales-exports', ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertForbidden();

        $this->actingAsRole(Role::WarehouseManager, [Warehouse::factory()->create()]);
        $this->postJson('/api/v1/reports/sales-exports', [
            'from' => '2026-01-01', 'to' => '2026-01-31', 'warehouse_id' => Warehouse::factory()->create()->id,
        ])->assertForbidden();
    }

    public function test_failed_job_marks_the_export_failed_without_leaking_details(): void
    {
        $export = ReportExport::create([
            'user_id' => User::factory()->create()->id, 'type' => 'sales', 'status' => ReportExportStatus::Processing,
            'parameters' => ['from' => '2026-03-01', 'to' => '2026-03-31', 'warehouse_ids' => null],
        ]);

        (new GenerateSalesReport($export))->failed(new \RuntimeException('SQLSTATE[HY000] connection refused'));

        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
        $this->assertStringNotContainsString('SQLSTATE', $export->fresh()->error);
    }

    public function test_completed_exports_are_not_regenerated_when_a_job_is_redelivered(): void
    {
        $export = ReportExport::create([
            'user_id' => User::factory()->create()->id, 'type' => 'sales', 'status' => ReportExportStatus::Completed,
            'file_path' => 'reports/existing.csv', 'row_count' => 5,
            'parameters' => ['from' => '2026-03-01', 'to' => '2026-03-31', 'warehouse_ids' => null],
        ]);

        (new GenerateSalesReport($export))->handle();

        $this->assertSame('reports/existing.csv', $export->fresh()->file_path);
        Storage::disk(ReportExport::DISK)->assertDirectoryEmpty('reports');
    }

    public function test_guests_cannot_download(): void
    {
        $this->getJson('/api/v1/reports/sales-exports/1/download')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/reports/sales-exports/999/download')->assertNotFound();
    }
}
