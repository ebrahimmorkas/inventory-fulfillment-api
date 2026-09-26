<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CustomersManage->value, Permission::OrdersView->value]);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersManage->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersManage->value);
    }
}
