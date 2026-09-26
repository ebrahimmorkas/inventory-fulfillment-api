<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** @var list<int>|null */
    private ?array $assignedWarehouseIds = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Warehouses this user is assigned to work in. */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user');
    }

    public function hasAccessToAllWarehouses(): bool
    {
        return $this->hasPermissionTo(Permission::WarehousesAccessAll->value);
    }

    public function canAccessWarehouse(Warehouse|int $warehouse): bool
    {
        if ($this->hasAccessToAllWarehouses()) {
            return true;
        }

        $id = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;

        return in_array($id, $this->assignedWarehouseIds(), true);
    }

    /**
     * IDs of the warehouses the user is assigned to, memoised per instance because
     * policies may ask repeatedly within one request.
     *
     * @return list<int>
     */
    public function assignedWarehouseIds(): array
    {
        return $this->assignedWarehouseIds ??= $this->warehouses()->pluck('warehouses.id')->all();
    }
}
