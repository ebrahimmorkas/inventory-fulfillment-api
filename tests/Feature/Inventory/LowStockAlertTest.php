<?php

namespace Tests\Feature\Inventory;

use App\Enums\Role;
use App\Events\StockLevelChanged;
use App\Exceptions\InsufficientStockException;
use App\Listeners\CheckReorderPoint;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Notifications\LowStockAlert;
use App\Services\InventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(InventoryService::class);
    }

    public function test_falling_below_the_reorder_point_alerts_the_warehouse_managers_and_admins(): void
    {
        Notification::fake();
        [$warehouse, $otherWarehouse] = Warehouse::factory()->count(2)->create();
        $level = StockLevel::factory()->for($warehouse)->quantity(20)->create(['reorder_point' => 10]);

        $manager = $this->userWithRole(Role::WarehouseManager, [$warehouse]);
        $admin = $this->userWithRole(Role::Admin);
        $otherManager = $this->userWithRole(Role::WarehouseManager, [$otherWarehouse]);
        $sales = $this->userWithRole(Role::Sales);
        $inactiveManager = $this->userWithRole(Role::WarehouseManager, [$warehouse]);
        $inactiveManager->update(['is_active' => false]);

        $this->inventory->adjust($warehouse->id, $level->product_id, -5, null, 'Damaged'); // 15 left: fine
        Notification::assertNothingSent();

        $this->inventory->adjust($warehouse->id, $level->product_id, -6, null, 'Damaged'); // 9 left: alert

        Notification::assertSentTo([$manager, $admin], LowStockAlert::class,
            fn (LowStockAlert $n) => $n->level->available === 9);
        Notification::assertNotSentTo([$otherManager, $sales, $inactiveManager], LowStockAlert::class);
    }

    public function test_alerts_are_not_repeated_until_stock_recovers(): void
    {
        Notification::fake();
        $warehouse = Warehouse::factory()->create();
        $level = StockLevel::factory()->for($warehouse)->quantity(12)->create(['reorder_point' => 10]);
        $manager = $this->userWithRole(Role::WarehouseManager, [$warehouse]);

        $this->inventory->adjust($warehouse->id, $level->product_id, -3, null, 'Count');
        $this->inventory->adjust($warehouse->id, $level->product_id, -3, null, 'Count');
        Notification::assertSentToTimes($manager, LowStockAlert::class, 1);

        $this->inventory->receive($warehouse->id, $level->product_id, 50, null);
        $this->inventory->adjust($warehouse->id, $level->product_id, -50, null, 'Count');
        Notification::assertSentToTimes($manager, LowStockAlert::class, 2);
    }

    public function test_no_event_is_dispatched_when_the_transaction_rolls_back(): void
    {
        Event::fake([StockLevelChanged::class]);
        $warehouse = Warehouse::factory()->create();
        $ok = StockLevel::factory()->for($warehouse)->quantity(10)->create();
        $short = StockLevel::factory()->for($warehouse)->quantity(1)->create();

        try {
            $this->inventory->reserve($warehouse->id, [$ok->product_id => 2, $short->product_id => 5], Order::factory()->create(), null);
        } catch (InsufficientStockException) {
        }

        Event::assertNotDispatched(StockLevelChanged::class);
    }

    public function test_listener_is_queued(): void
    {
        Event::fake();

        Event::assertListening(StockLevelChanged::class, CheckReorderPoint::class);
        $this->assertContains(ShouldQueue::class, class_implements(CheckReorderPoint::class));
    }

    public function test_users_only_see_and_mark_their_own_notifications(): void
    {
        $warehouse = Warehouse::factory()->create();
        $level = StockLevel::factory()->for($warehouse)->quantity(5)->create(['reorder_point' => 10]);
        $manager = $this->userWithRole(Role::WarehouseManager, [$warehouse]);
        $manager->notify(new LowStockAlert($level->load('product', 'warehouse')));
        $notificationId = $manager->notifications()->value('id');

        $this->actingAsRole(Role::Sales);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('total', 0);
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.data.sku', $level->product->sku);
        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNoContent();
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonPath('total', 0);
    }
}
