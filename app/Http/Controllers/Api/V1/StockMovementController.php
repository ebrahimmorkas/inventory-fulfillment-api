<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Requests\Inventory\StockReceiptRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * The ledger grows without bound, so it uses cursor pagination (no COUNT(*)
     * and no deep OFFSET scans), newest first.
     */
    public function index(Request $request, Warehouse $warehouse): AnonymousResourceCollection
    {
        Gate::authorize('viewStock', $warehouse);

        $request->validate([
            'product_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $movements = $warehouse->stockMovements()
            ->with(['product:id,sku,name', 'user:id,name'])
            ->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request))
            ->withQueryString();

        return StockMovementResource::collection($movements);
    }

    public function receive(StockReceiptRequest $request, Warehouse $warehouse): JsonResponse
    {
        $movement = $this->inventory->receive(
            $warehouse->id,
            $request->integer('product_id'),
            $request->integer('quantity'),
            $request->user(),
            $request->validated('reason'),
        );

        return (new StockMovementResource($movement))->response()->setStatusCode(201);
    }

    public function adjust(StockAdjustmentRequest $request, Warehouse $warehouse): JsonResponse
    {
        $movement = $this->inventory->adjust(
            $warehouse->id,
            $request->integer('product_id'),
            $request->integer('quantity_delta'),
            $request->user(),
            $request->validated('reason'),
        );

        return (new StockMovementResource($movement))->response()->setStatusCode(201);
    }
}
