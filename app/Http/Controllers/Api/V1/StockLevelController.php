<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockLevelResource;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockLevelController extends Controller
{
    /** Stock of every product in one warehouse. */
    public function index(Request $request, Warehouse $warehouse): AnonymousResourceCollection
    {
        Gate::authorize('viewStock', $warehouse);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'below_reorder_point' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
        ]);

        $sorts = [
            'sku' => 'products.sku',
            'on_hand' => 'stock_levels.on_hand',
            'available' => 'available',
            'updated_at' => 'stock_levels.updated_at',
        ];
        [$column, $direction] = $this->sort($request, array_keys($sorts), 'sku');

        $levels = StockLevel::query()
            ->select('stock_levels.*')
            ->selectRaw('stock_levels.on_hand - stock_levels.reserved as available')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->where('stock_levels.warehouse_id', $warehouse->id)
            ->with('product:id,sku,name,unit_price_cents,is_active')
            ->when($request->query('search'), function ($q, string $search) {
                $q->where(fn ($q) => $q->where('products.sku', 'like', strtoupper($search).'%')->orWhere('products.name', 'like', "%{$search}%"));
            })
            ->when($request->boolean('below_reorder_point'), fn ($q) => $q->belowReorderPoint())
            ->when($request->has('in_stock'), fn ($q) => $request->boolean('in_stock')
                ? $q->whereColumn('stock_levels.on_hand', '>', 'stock_levels.reserved')
                : $q->whereColumn('stock_levels.on_hand', '<=', 'stock_levels.reserved'))
            ->orderBy($sorts[$column], $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return StockLevelResource::collection($levels);
    }

    /** Stock of one product across the warehouses the user may access. */
    public function forProduct(Request $request, Product $product): AnonymousResourceCollection
    {
        abort_unless($request->user()->can(Permission::InventoryView->value), 403);

        $levels = $product->stockLevels()
            ->whereIn('warehouse_id', Warehouse::accessibleBy($request->user())->select('id'))
            ->with('warehouse:id,code,name')
            ->orderBy('warehouse_id')
            ->get();

        return StockLevelResource::collection($levels);
    }

    public function updateReorderPoint(Request $request, Warehouse $warehouse, Product $product): JsonResponse
    {
        Gate::authorize('manageStock', $warehouse);

        $validated = $request->validate([
            'reorder_point' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $level = StockLevel::updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            $validated,
        );

        // Upsert: always 200, even when the stock level row did not exist yet.
        return (new StockLevelResource($level->load('product:id,sku,name,unit_price_cents,is_active')))->response()->setStatusCode(200);
    }
}
