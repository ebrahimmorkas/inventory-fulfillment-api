<?php

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLevel */
class StockLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->on_hand - $this->reserved,
            'reorder_point' => $this->reorder_point,
            'below_reorder_point' => $this->isBelowReorderPoint(),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'unit_price_cents' => $this->product->unit_price_cents,
                'is_active' => $this->product->is_active,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
