<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies the two invariants the inventory model relies on:
 *
 *  1. Each stock level equals the sum of its ledger movements.
 *  2. Each reserved quantity equals the quantities of open (confirmed) orders.
 *
 * Any drift means stock was changed outside InventoryService (e.g. a manual SQL
 * fix) and must be investigated. Read-only: it reports, it never repairs.
 */
#[Signature('inventory:reconcile {--warehouse= : Only check one warehouse id}')]
#[Description('Check stock levels against the movement ledger and open orders')]
class ReconcileInventory extends Command
{
    public function handle(): int
    {
        $warehouseId = $this->option('warehouse');

        $ledgerDrift = $this->ledgerDrift($warehouseId);
        $reservationDrift = $this->reservationDrift($warehouseId);

        if ($ledgerDrift->isEmpty() && $reservationDrift->isEmpty()) {
            $this->info('Inventory is consistent with the ledger and open orders.');

            return self::SUCCESS;
        }

        if ($ledgerDrift->isNotEmpty()) {
            $this->error('Stock levels that do not match the movement ledger:');
            $this->table(
                ['warehouse_id', 'product_id', 'on_hand', 'ledger_on_hand', 'reserved', 'ledger_reserved'],
                $ledgerDrift->map(fn ($row) => (array) $row),
            );
        }

        if ($reservationDrift->isNotEmpty()) {
            $this->error('Reserved quantities that do not match open orders:');
            $this->table(
                ['warehouse_id', 'product_id', 'reserved', 'open_order_quantity'],
                $reservationDrift->map(fn ($row) => (array) $row),
            );
        }

        Log::critical('Inventory reconciliation found discrepancies', [
            'ledger' => $ledgerDrift->all(),
            'reservations' => $reservationDrift->all(),
        ]);

        return self::FAILURE;
    }

    private function ledgerDrift(?string $warehouseId): Collection
    {
        $ledger = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id')
            ->selectRaw('SUM(on_hand_delta) as on_hand_sum, SUM(reserved_delta) as reserved_sum')
            ->groupBy('warehouse_id', 'product_id');

        return DB::table('stock_levels as sl')
            ->leftJoinSub($ledger, 'm', fn ($join) => $join
                ->on('m.warehouse_id', '=', 'sl.warehouse_id')
                ->on('m.product_id', '=', 'sl.product_id'))
            ->when($warehouseId, fn ($q) => $q->where('sl.warehouse_id', $warehouseId))
            ->where(fn ($q) => $q
                ->whereRaw('sl.on_hand <> COALESCE(m.on_hand_sum, 0)')
                ->orWhereRaw('sl.reserved <> COALESCE(m.reserved_sum, 0)'))
            ->orderBy('sl.warehouse_id')
            ->orderBy('sl.product_id')
            ->get([
                'sl.warehouse_id', 'sl.product_id', 'sl.on_hand',
                DB::raw('COALESCE(m.on_hand_sum, 0) as ledger_on_hand'),
                'sl.reserved',
                DB::raw('COALESCE(m.reserved_sum, 0) as ledger_reserved'),
            ]);
    }

    private function reservationDrift(?string $warehouseId): Collection
    {
        $open = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Confirmed->value)
            ->select('orders.warehouse_id', 'order_items.product_id')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->groupBy('orders.warehouse_id', 'order_items.product_id');

        return DB::table('stock_levels as sl')
            ->leftJoinSub($open, 'o', fn ($join) => $join
                ->on('o.warehouse_id', '=', 'sl.warehouse_id')
                ->on('o.product_id', '=', 'sl.product_id'))
            ->when($warehouseId, fn ($q) => $q->where('sl.warehouse_id', $warehouseId))
            ->whereRaw('sl.reserved <> COALESCE(o.quantity, 0)')
            ->orderBy('sl.warehouse_id')
            ->orderBy('sl.product_id')
            ->get(['sl.warehouse_id', 'sl.product_id', 'sl.reserved', DB::raw('COALESCE(o.quantity, 0) as open_order_quantity')]);
    }
}
