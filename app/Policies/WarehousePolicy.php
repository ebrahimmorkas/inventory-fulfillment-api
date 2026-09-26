<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    /** Listing is filtered to accessible warehouses by the controller. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->canAccessWarehouse($warehouse);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CatalogManage->value);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->can(Permission::CatalogManage->value);
    }
}
