<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            'confirmed to shipped' => [OrderStatus::Confirmed, OrderStatus::Shipped, true],
            'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, true],
            'shipped to cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled, false],
            'cancelled to shipped' => [OrderStatus::Cancelled, OrderStatus::Shipped, false],
            'confirmed to confirmed' => [OrderStatus::Confirmed, OrderStatus::Confirmed, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_allowed_transitions(OrderStatus $from, OrderStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }
}
