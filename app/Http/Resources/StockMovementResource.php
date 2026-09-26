<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'on_hand_delta' => $this->on_hand_delta,
            'reserved_delta' => $this->reserved_delta,
            'on_hand_after' => $this->on_hand_after,
            'reserved_after' => $this->reserved_after,
            'reason' => $this->reason,
            'reference' => $this->reference_type ? ['type' => $this->reference_type, 'id' => $this->reference_id] : null,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
