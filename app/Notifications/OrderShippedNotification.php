<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds between retries when the mail server is unavailable */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your order {$this->order->number} has shipped")
            ->greeting("Hello {$notifiable->name},")
            ->line("Order {$this->order->number} left our warehouse on {$this->order->shipped_at->toFormattedDayDateString()}.")
            ->line("Tracking number: {$this->order->tracking_number}")
            ->line('Thank you for your business.');
    }
}
