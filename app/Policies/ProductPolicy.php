<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /** The product catalogue is visible to every active member of staff. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CatalogManage->value);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can(Permission::CatalogManage->value);
    }
}
