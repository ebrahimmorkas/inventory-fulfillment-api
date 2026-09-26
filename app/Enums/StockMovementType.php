<?php

namespace App\Enums;

enum StockMovementType: string
{
    /** Goods received into the warehouse (increases on hand). */
    case Receipt = 'receipt';

    /** Manual correction after a count, damage or loss (either direction). */
    case Adjustment = 'adjustment';

    /** Units set aside for an order (increases reserved). */
    case Reservation = 'reservation';

    /** Reserved units returned to available stock (order cancelled). */
    case Release = 'release';

    /** Reserved units physically shipped (decreases on hand and reserved). */
    case Shipment = 'shipment';
}
