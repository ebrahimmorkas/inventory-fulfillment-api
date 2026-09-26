<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Events\StockLevelChanged;
use App\Exceptions\InsufficientStockException;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only code path that changes stock quantities.
 *
 * Every operation runs in a transaction, locks the affected stock_levels rows
 * with SELECT ... FOR UPDATE and records one ledger entry per row changed.
 * Rows are always locked in ascending product_id order so that two concurrent
 * multi-line operations cannot deadlock by acquiring locks in opposite order.
 *
 * InnoDB can still report a deadlock (e.g. gap locks taken while inserting a new
 * stock level), so the outermost transactions are retried a few times. Laravel
 * only retries at the outermost level, so nested calls stay correct.
 */
class InventoryService
{
    public const DEADLOCK_ATTEMPTS = 3;

    public function receive(int $warehouseId, int $productId, int $quantity, ?User $user, ?string $reason = null): StockMovement
    {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($warehouseId, $productId, $quantity, $user, $reason) {
            $level = $this->lockOrCreateLevel($warehouseId, $productId);

            return $this->apply($level, StockMovementType::Receipt, $quantity, 0, $user, $reason);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Correct on-hand stock after a count, damage or loss. On-hand stock may never
     * drop below what is already reserved for open orders.
     */
    public function adjust(int $warehouseId, int $productId, int $delta, ?User $user, string $reason): StockMovement
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('Adjustment quantity must not be zero.');
        }

        return DB::transaction(function () use ($warehouseId, $productId, $delta, $user, $reason) {
            $level = $this->lockOrCreateLevel($warehouseId, $productId);

            if ($level->on_hand + $delta < $level->reserved) {
                throw InsufficientStockException::forProduct($productId, abs($delta), $level->available);
            }

            return $this->apply($level, StockMovementType::Adjustment, $delta, 0, $user, $reason);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Reserve every line or none of them.
     *
     * @param  array<int, int>  $quantities  product_id => quantity
     *
     * @throws InsufficientStockException listing every line that cannot be satisfied
     */
    public function reserve(int $warehouseId, array $quantities, Model $reference, ?User $user): void
    {
        array_map($this->assertPositive(...), $quantities);

        DB::transaction(function () use ($warehouseId, $quantities, $reference, $user) {
            $levels = $this->lockLevels($warehouseId, array_keys($quantities));

            $shortages = [];
            foreach ($quantities as $productId => $quantity) {
                $available = $levels->get($productId)?->available ?? 0;

                if ($available < $quantity) {
                    $shortages[] = ['product_id' => $productId, 'requested' => $quantity, 'available' => $available];
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }

            foreach ($quantities as $productId => $quantity) {
                $this->apply($levels->get($productId), StockMovementType::Reservation, 0, $quantity, $user, null, $reference);
            }
        });
    }

    /**
     * Return reserved units to available stock, e.g. when an order is cancelled.
     *
     * @param  array<int, int>  $quantities  product_id => quantity
     */
    public function release(int $warehouseId, array $quantities, Model $reference, ?User $user, ?string $reason = null): void
    {
        $this->consumeReservation($warehouseId, $quantities, $reference, $user, $reason, StockMovementType::Release);
    }

    /**
     * Convert reserved units into a shipment: they leave on-hand stock.
     *
     * @param  array<int, int>  $quantities  product_id => quantity
     */
    public function ship(int $warehouseId, array $quantities, Model $reference, ?User $user): void
    {
        $this->consumeReservation($warehouseId, $quantities, $reference, $user, null, StockMovementType::Shipment);
    }

    /**
     * @param  array<int, int>  $quantities
     */
    private function consumeReservation(int $warehouseId, array $quantities, Model $reference, ?User $user, ?string $reason, StockMovementType $type): void
    {
        array_map($this->assertPositive(...), $quantities);

        DB::transaction(function () use ($warehouseId, $quantities, $reference, $user, $reason, $type) {
            $levels = $this->lockLevels($warehouseId, array_keys($quantities));

            foreach ($quantities as $productId => $quantity) {
                $level = $levels->get($productId);

                // A reservation always precedes a release or shipment, so this
                // indicates corrupted data rather than a user error.
                if (! $level || $level->reserved < $quantity) {
                    throw new LogicException("Reservation for product {$productId} in warehouse {$warehouseId} is missing.");
                }

                $onHandDelta = $type === StockMovementType::Shipment ? -$quantity : 0;

                $this->apply($level, $type, $onHandDelta, -$quantity, $user, $reason, $reference);
            }
        });
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, StockLevel> keyed by product_id
     */
    private function lockLevels(int $warehouseId, array $productIds): Collection
    {
        sort($productIds);

        return StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
    }

    private function lockOrCreateLevel(int $warehouseId, int $productId): StockLevel
    {
        $level = $this->lockLevels($warehouseId, [$productId])->first();

        if ($level) {
            return $level;
        }

        // createOrFirst tolerates a concurrent insert of the same row (unique index),
        // after which we lock whichever row won.
        $created = StockLevel::createOrFirst(['warehouse_id' => $warehouseId, 'product_id' => $productId]);

        return StockLevel::whereKey($created->id)->lockForUpdate()->firstOrFail();
    }

    private function apply(
        StockLevel $level,
        StockMovementType $type,
        int $onHandDelta,
        int $reservedDelta,
        ?User $user,
        ?string $reason,
        ?Model $reference = null,
    ): StockMovement {
        $level->on_hand += $onHandDelta;
        $level->reserved += $reservedDelta;
        $level->save();

        // Held until the outermost transaction commits; discarded on rollback.
        StockLevelChanged::dispatch($level->warehouse_id, $level->product_id);

        return StockMovement::create([
            'warehouse_id' => $level->warehouse_id,
            'product_id' => $level->product_id,
            'type' => $type,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after' => $level->on_hand,
            'reserved_after' => $level->reserved,
            'reason' => $reason,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'user_id' => $user?->id,
        ]);
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be a positive integer.');
        }
    }
}
