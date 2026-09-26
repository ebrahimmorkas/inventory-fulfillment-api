<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_warehouse_manager_assigned_to_warehouses(): void
    {
        $this->actingAsRole(Role::Admin);
        $warehouses = Warehouse::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Priya Shah',
            'email' => 'priya@example.com',
            'password' => 'correct-horse-42',
            'role' => Role::WarehouseManager->value,
            'warehouse_ids' => $warehouses->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'warehouse-manager')
            ->assertJsonPath('data.warehouse_ids', $warehouses->pluck('id')->all());

        $user = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::WarehouseManager->value));
        $this->assertTrue($user->canAccessWarehouse($warehouses[0]));
    }

    public function test_weak_passwords_and_unknown_roles_are_rejected(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'short',
            'role' => 'superuser',
            'warehouse_ids' => [999],
        ])->assertUnprocessable()->assertJsonValidationErrors(['password', 'role', 'warehouse_ids.0']);
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->userWithRole(Role::Sales);
        $this->userWithRole(Role::Sales);
        $this->userWithRole(Role::WarehouseManager);

        $this->getJson('/api/v1/users?role=sales')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $this->actingAsRole(Role::Admin);
        $sales = $this->userWithRole(Role::Sales);
        $sales->createToken('laptop');

        $this->patchJson("/api/v1/users/{$sales->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $sales->tokens()->count());
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = $this->actingAsRole(Role::Admin);

        $this->patchJson("/api/v1/users/{$admin->id}", ['is_active' => false, 'role' => 'sales'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active', 'role']);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public static function nonAdminRoles(): array
    {
        return [[Role::Sales], [Role::WarehouseManager]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_manage_users(Role $role): void
    {
        $this->actingAsRole($role);
        $other = User::factory()->create();

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson("/api/v1/users/{$other->id}")->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->patchJson("/api/v1/users/{$other->id}", ['is_active' => false])->assertForbidden();

        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_users_can_view_their_own_profile(): void
    {
        $user = $this->actingAsRole(Role::Sales);

        $this->getJson("/api/v1/users/{$user->id}")->assertOk()->assertJsonPath('data.role', 'sales');
    }

    public function test_warehouse_access_is_limited_to_assigned_warehouses_unless_granted_all(): void
    {
        [$assigned, $other] = Warehouse::factory()->count(2)->create();

        $manager = $this->userWithRole(Role::WarehouseManager, [$assigned]);
        $sales = $this->userWithRole(Role::Sales);

        $this->assertTrue($manager->canAccessWarehouse($assigned));
        $this->assertFalse($manager->canAccessWarehouse($other));
        $this->assertTrue($sales->can(Permission::WarehousesAccessAll->value));
        $this->assertTrue($sales->canAccessWarehouse($other));
    }

    public function test_user_without_any_role_has_no_access(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
