<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Roles and permissions are reference data every test relies on. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * @param  list<Warehouse>  $warehouses  warehouses the user is assigned to
     */
    protected function userWithRole(Role $role, array $warehouses = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->warehouses()->attach(array_map(fn (Warehouse $w) => $w->id, $warehouses));

        return $user;
    }

    /**
     * @param  list<Warehouse>  $warehouses
     */
    protected function actingAsRole(Role $role, array $warehouses = []): User
    {
        $user = $this->userWithRole($role, $warehouses);
        Sanctum::actingAs($user);

        return $user;
    }
}
