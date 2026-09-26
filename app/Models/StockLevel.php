<?php

namespace App\Models;

use Database\Factories\StockLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current quantity of one product in one warehouse.
 *
 * on_hand and reserved are only ever changed through InventoryService,
 * which locks the row and writes a matching StockMovement.
 */
#[Fillable(['warehouse_id', 'product_id', 'reorder_point'])]
class StockLevel extends Model
{
    /** @use HasFactory<StockLevelFactory> */
    use HasFactory;

    protected $attributes = [
        'on_hand' => 0,
        'reserved' => 0,
        'reorder_point' => 0,
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'reorder_point' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function available(): Attribute
    {
        return Attribute::get(fn (): int => $this->on_hand - $this->reserved);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->reorder_point > 0 && $this->available <= $this->reorder_point;
    }

    public function scopeBelowReorderPoint(Builder $query): void
    {
        $query->where('reorder_point', '>', 0)
            ->whereRaw('on_hand - reserved <= reorder_point');
    }
}
