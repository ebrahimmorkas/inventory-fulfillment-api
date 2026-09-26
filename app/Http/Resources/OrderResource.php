<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'subtotal_cents' => $this->subtotal_cents,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'company_name' => $this->customer->company_name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'line_total_cents' => $item->line_total_cents,
            ])),
            'ship_to' => [
                'name' => $this->ship_to_name,
                'line1' => $this->ship_to_line1,
                'city' => $this->ship_to_city,
                'postal_code' => $this->ship_to_postal_code,
                'country' => $this->ship_to_country,
            ],
            'notes' => $this->notes,
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
