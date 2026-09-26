<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use App\Notifications\OrderShippedNotification;

class NotifyCustomerOfShipment
{
    public function handle(OrderShipped $event): void
    {
        // The notification itself is queued; this listener only hands it off.
        $event->order->loadMissing('customer')->customer->notify(new OrderShippedNotification($event->order));
    }
}
