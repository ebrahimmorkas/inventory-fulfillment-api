<?php

namespace App\Listeners;

use App\Enums\Permission;
use App\Events\StockLevelChanged;
use App\Models\StockLevel;
use App\Models\User;
use App\Notifications\LowStockAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class CheckReorderPoint implements ShouldQueue
{
    /** Do not alert again for the same item within this window. */
    public const ALERT_COOLDOWN_HOURS = 12;

    public int $tries = 3;

    public function handle(StockLevelChanged $event): void
    {
        $level = StockLevel::with(['product:id,sku,name', 'warehouse:id,code,name'])
            ->where('warehouse_id', $event->warehouseId)
            ->where('product_id', $event->productId)
            ->first();

        if (! $level) {
            return;
        }

        $cooldownKey = "low-stock-alerted:{$level->warehouse_id}:{$level->product_id}";

        // Stock recovered: allow the next dip to alert straight away.
        if (! $level->isBelowReorderPoint()) {
            Cache::forget($cooldownKey);

            return;
        }

        // Cache::add is atomic, so concurrent workers handling a burst of
        // movements for the same item send a single alert.
        if (! Cache::add($cooldownKey, true, now()->addHours(self::ALERT_COOLDOWN_HOURS))) {
            return;
        }

        Notification::send($this->recipients($level), new LowStockAlert($level));
    }

    /**
     * Active users who can manage stock in this warehouse: its assigned managers
     * plus anyone with access to every warehouse (administrators).
     */
    private function recipients(StockLevel $level)
    {
        return User::query()
            ->where('is_active', true)
            ->permission(Permission::InventoryAdjust->value)
            ->where(fn ($query) => $query
                ->whereHas('warehouses', fn ($q) => $q->whereKey($level->warehouse_id))
                ->orWhereIn('id', User::permission(Permission::WarehousesAccessAll->value)->select('id')))
            ->get();
    }
}
