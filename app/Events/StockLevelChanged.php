<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for every stock movement, after the inventory transaction commits.
 *
 * Carries ids rather than models: listeners are queued and must read the
 * current quantities when they run, not a snapshot from dispatch time.
 */
class StockLevelChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $warehouseId,
        public readonly int $productId,
    ) {}
}
