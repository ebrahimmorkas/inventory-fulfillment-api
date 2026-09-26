<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id', 'warehouse_id', 'created_by', 'status', 'subtotal_cents',
    'ship_to_name', 'ship_to_line1', 'ship_to_city', 'ship_to_postal_code', 'ship_to_country',
    'notes', 'tracking_number', 'shipped_at', 'cancelled_at', 'cancellation_reason',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Human-facing order number derived from the auto-increment id. It is
     * assigned by OrderService right after insert, in the same transaction.
     */
    public static function numberFor(int $id): string
    {
        return sprintf('SO-%07d', $id);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
