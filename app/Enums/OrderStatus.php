<?php

namespace App\Enums;

enum OrderStatus: string
{
    /** Stock has been reserved in the fulfilling warehouse. */
    case Confirmed = 'confirmed';

    /** Stock has left the warehouse; reservation converted to a shipment. */
    case Shipped = 'shipped';

    /** Order was cancelled before shipment; reservation released. */
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Confirmed => in_array($next, [self::Shipped, self::Cancelled], true),
            self::Shipped, self::Cancelled => false,
        };
    }
}
