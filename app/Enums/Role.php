<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case WarehouseManager = 'warehouse-manager';
    case Sales = 'sales';

    /**
     * Permissions granted to each role, installed by RolesAndPermissionsSeeder.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::WarehouseManager => [
                Permission::InventoryView,
                Permission::InventoryAdjust,
                Permission::OrdersView,
                Permission::OrdersFulfil,
                Permission::ReportsView,
            ],
            self::Sales => [
                Permission::WarehousesAccessAll,
                Permission::InventoryView,
                Permission::CustomersManage,
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::OrdersCancel,
            ],
        };
    }
}
