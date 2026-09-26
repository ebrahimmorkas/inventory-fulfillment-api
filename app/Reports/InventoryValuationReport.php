<?php

namespace App\Reports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stock quantity and value per warehouse.
 *
 * The aggregate scans every stock level, so it is cached once for the whole
 * system (not per user) and filtered to the caller's warehouses afterwards.
 * Cache::flexible serves the cached value for FRESH_SECONDS; between FRESH and
 * STALE seconds it still returns the cached value immediately but recomputes
 * it after the response is sent, so callers never wait on the aggregate and
 * concurrent requests do not stampede the database.
 */
class InventoryValuationReport
{
    public const CACHE_KEY = 'reports:inventory-valuation';

    public const FRESH_SECONDS = 60;

    public const STALE_SECONDS = 300;

    /**
     * @param  list<int>|null  $warehouseIds  null for every warehouse
     * @return array{generated_at: string, warehouses: list<array<string, int|string>>, totals: array<string, int>}
     */
    public function forWarehouses(?array $warehouseIds): array
    {
        $report = Cache::flexible(self::CACHE_KEY, [self::FRESH_SECONDS, self::STALE_SECONDS], fn () => $this->compute());

        $rows = collect($report['warehouses'])
            ->when($warehouseIds !== null, fn ($rows) => $rows->whereIn('warehouse_id', $warehouseIds))
            ->values();

        return [
            'generated_at' => $report['generated_at'],
            'warehouses' => $rows->all(),
            'totals' => [
                'units_on_hand' => $rows->sum('units_on_hand'),
                'units_reserved' => $rows->sum('units_reserved'),
                'value_cents' => $rows->sum('value_cents'),
            ],
        ];
    }

    private function compute(): array
    {
        $rows = DB::table('warehouses')
            ->leftJoin('stock_levels', 'stock_levels.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('products', 'products.id', '=', 'stock_levels.product_id')
            ->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name')
            ->orderBy('warehouses.code')
            ->select([
                'warehouses.id as warehouse_id',
                'warehouses.code',
                'warehouses.name',
                DB::raw('COUNT(stock_levels.id) as sku_count'),
                DB::raw('COALESCE(SUM(stock_levels.on_hand), 0) as units_on_hand'),
                DB::raw('COALESCE(SUM(stock_levels.reserved), 0) as units_reserved'),
                DB::raw('COALESCE(SUM(stock_levels.on_hand * products.unit_price_cents), 0) as value_cents'),
                DB::raw('COALESCE(SUM(stock_levels.reorder_point > 0 AND stock_levels.on_hand - stock_levels.reserved <= stock_levels.reorder_point), 0) as items_below_reorder_point'),
            ])
            ->get()
            ->map(fn (object $row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'code' => $row->code,
                'name' => $row->name,
                'sku_count' => (int) $row->sku_count,
                'units_on_hand' => (int) $row->units_on_hand,
                'units_reserved' => (int) $row->units_reserved,
                'value_cents' => (int) $row->value_cents,
                'items_below_reorder_point' => (int) $row->items_below_reorder_point,
            ]);

        return [
            'generated_at' => now()->toIso8601String(),
            'warehouses' => $rows->all(),
        ];
    }
}
