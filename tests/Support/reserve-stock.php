<?php

/*
 * Helper for ConcurrentReservationTest: boots the application in its own PHP
 * process and attempts a single reservation, printing the outcome.
 *
 * Usage: php tests/Support/reserve-stock.php <warehouse> <product> <quantity> <order>
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $warehouseId, $productId, $quantity, $orderId] = $argv;

try {
    $app->make(InventoryService::class)->reserve(
        (int) $warehouseId,
        [(int) $productId => (int) $quantity],
        Order::findOrFail($orderId),
        null,
    );
    echo 'reserved';
} catch (InsufficientStockException) {
    echo 'insufficient';
}
