<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Reports\InventoryValuationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryValuationController extends Controller
{
    public function __invoke(Request $request, InventoryValuationReport $report): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can(Permission::ReportsView->value), 403);

        return response()->json([
            'data' => $report->forWarehouses($user->hasAccessToAllWarehouses() ? null : $user->assignedWarehouseIds()),
        ]);
    }
}
