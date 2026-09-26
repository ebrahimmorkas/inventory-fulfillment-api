<?php

namespace App\Enums;

enum Permission: string
{
    case UsersManage = 'users.manage';
    case CatalogManage = 'catalog.manage';
    case CustomersManage = 'customers.manage';

    /** Bypasses warehouse assignment: the user may act on every warehouse. */
    case WarehousesAccessAll = 'warehouses.access-all';

    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';

    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersCancel = 'orders.cancel';
    case OrdersFulfil = 'orders.fulfil';

    case ReportsView = 'reports.view';
}
