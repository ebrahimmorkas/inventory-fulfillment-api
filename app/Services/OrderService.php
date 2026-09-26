<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderShipped;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Create a confirmed order and reserve its stock in one transaction.
     *
     * Prices are always taken from the catalogue at the time of ordering and
     * snapshotted on the order lines; clients cannot supply them.
     *
     * @param  array{customer_id: int, warehouse_id: int, items: list<array{product_id: int, quantity: int}>, ship_to: array<string, string>, notes?: string|null}  $data
     */
    public function place(array $data, User $actor): Order
    {
        return DB::transaction(function () use ($data, $actor) {
            $quantities = collect($data['items'])->mapWithKeys(fn (array $item) => [(int) $item['product_id'] => (int) $item['quantity']]);
            $products = Product::whereKey($quantities->keys())->get(['id', 'unit_price_cents'])->keyBy('id');

            $lines = $quantities->map(fn (int $quantity, int $productId) => [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_cents' => $products[$productId]->unit_price_cents,
                'line_total_cents' => $products[$productId]->unit_price_cents * $quantity,
            ])->values();

            $order = Order::create([
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'created_by' => $actor->id,
                'status' => OrderStatus::Confirmed,
                'subtotal_cents' => $lines->sum('line_total_cents'),
                'ship_to_name' => $data['ship_to']['name'],
                'ship_to_line1' => $data['ship_to']['line1'],
                'ship_to_city' => $data['ship_to']['city'],
                'ship_to_postal_code' => $data['ship_to']['postal_code'],
                'ship_to_country' => $data['ship_to']['country'],
                'notes' => $data['notes'] ?? null,
            ]);
            $order->items()->createMany($lines->all());

            // Throws InsufficientStockException, rolling back the order as well.
            $this->inventory->reserve($order->warehouse_id, $quantities->all(), $order, $actor);

            return $order;
        });
    }

    public function cancel(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = $this->lockForTransition($order, OrderStatus::Cancelled);

            $this->inventory->release($order->warehouse_id, $this->quantities($order), $order, $actor, $reason);

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $order;
        });
    }

    public function ship(Order $order, User $actor, string $trackingNumber): Order
    {
        return DB::transaction(function () use ($order, $actor, $trackingNumber) {
            $order = $this->lockForTransition($order, OrderStatus::Shipped);

            $this->inventory->ship($order->warehouse_id, $this->quantities($order), $order, $actor);

            $order->update([
                'status' => OrderStatus::Shipped,
                'shipped_at' => now(),
                'tracking_number' => $trackingNumber,
            ]);

            // Dispatched after the transaction commits (see the event class).
            OrderShipped::dispatch($order);

            return $order;
        });
    }

    /**
     * Re-read the order under a row lock so two requests (e.g. ship and cancel)
     * cannot both act on the same confirmed order. The order row is always
     * locked before its stock rows, keeping lock ordering consistent.
     */
    private function lockForTransition(Order $order, OrderStatus $next): Order
    {
        $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->canTransitionTo($next)) {
            throw new InvalidOrderTransitionException($locked->status, $next);
        }

        return $locked->load('items:id,order_id,product_id,quantity');
    }

    /** @return array<int, int> product_id => quantity */
    private function quantities(Order $order): array
    {
        return $order->items->mapWithKeys(fn ($item) => [$item->product_id => $item->quantity])->all();
    }
}
