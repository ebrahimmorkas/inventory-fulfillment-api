<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->can(Permission::UsersManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }
}
