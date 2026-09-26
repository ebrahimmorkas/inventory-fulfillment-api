<?php

namespace Tests\Feature\Catalog;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseAndCustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_deactivate_a_warehouse(): void
    {
        $this->actingAsRole(Role::Admin);

        $id = $this->postJson('/api/v1/warehouses', [
            'code' => 'lon-01',
            'name' => 'London DC',
            'country_code' => 'gb',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'LON-01')
            ->assertJsonPath('data.country_code', 'GB')
            ->json('data.id');

        $this->patchJson("/api/v1/warehouses/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_managers_only_see_their_assigned_warehouses(): void
    {
        [$assigned, $other] = Warehouse::factory()->count(2)->create();
        $this->actingAsRole(Role::WarehouseManager, [$assigned]);

        $this->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);

        $this->getJson("/api/v1/warehouses/{$assigned->id}")->assertOk();
        $this->getJson("/api/v1/warehouses/{$other->id}")->assertForbidden();
    }

    public function test_sales_can_see_every_warehouse(): void
    {
        Warehouse::factory()->count(3)->create();
        $this->actingAsRole(Role::Sales);

        $this->getJson('/api/v1/warehouses')->assertJsonPath('meta.total', 3);
    }

    public function test_managers_cannot_create_warehouses(): void
    {
        $this->actingAsRole(Role::WarehouseManager);

        $this->postJson('/api/v1/warehouses', ['code' => 'X', 'name' => 'X', 'country_code' => 'GB'])->assertForbidden();
    }

    public function test_sales_can_create_and_search_customers(): void
    {
        $this->actingAsRole(Role::Sales);
        Customer::factory()->create(['company_name' => 'Globex Corporation']);

        $this->postJson('/api/v1/customers', [
            'name' => 'Dana Scully',
            'email' => 'dana@acme.test',
            'company_name' => 'Acme Industrial',
        ])->assertCreated();

        $this->getJson('/api/v1/customers?search=acme')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'dana@acme.test');
    }

    public function test_customer_email_must_be_unique(): void
    {
        $this->actingAsRole(Role::Sales);
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/customers', ['name' => 'X', 'email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_managers_can_view_but_not_edit_customers(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAsRole(Role::WarehouseManager);

        $this->getJson("/api/v1/customers/{$customer->id}")->assertOk();
        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Changed'])->assertForbidden();
    }
}
