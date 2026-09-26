<?php

namespace App\Notifications;

use App\Models\StockLevel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly StockLevel $level) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = $this->level->product;
        $warehouse = $this->level->warehouse;

        return (new MailMessage)
            ->subject("Low stock: {$product->sku} at {$warehouse->code}")
            ->line("{$product->name} ({$product->sku}) in {$warehouse->name} has {$this->level->available} units available.")
            ->line("The reorder point is {$this->level->reorder_point}.");
    }

    /** Stored for the in-app notifications endpoint. */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_stock',
            'warehouse_id' => $this->level->warehouse_id,
            'warehouse_code' => $this->level->warehouse->code,
            'product_id' => $this->level->product_id,
            'sku' => $this->level->product->sku,
            'available' => $this->level->available,
            'reorder_point' => $this->level->reorder_point,
        ];
    }
}
