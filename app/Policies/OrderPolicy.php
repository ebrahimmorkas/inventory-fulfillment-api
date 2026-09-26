<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;

class OrderPolicy
{
    /** Listing is filtered to accessible warehouses by the controller. */
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::OrdersView->value);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersView->value) && $user->canAccessWarehouse($order->warehouse_id);
    }

    public function create(User $user, Warehouse $warehouse): bool
    {
        return $user->can(Permission::OrdersCreate->value) && $user->canAccessWarehouse($warehouse);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersCancel->value) && $user->canAccessWarehouse($order->warehouse_id);
    }

    public function ship(User $user, Order $order): bool
    {
        return $user->can(Permission::OrdersFulfil->value) && $user->canAccessWarehouse($order->warehouse_id);
    }
}
