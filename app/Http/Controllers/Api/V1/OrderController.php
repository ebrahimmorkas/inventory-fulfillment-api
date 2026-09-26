<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const DETAIL_RELATIONS = [
        'customer:id,name,email,company_name',
        'warehouse:id,code,name',
        'items.product:id,sku,name',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'warehouse_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'number' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        [$column, $direction] = $this->sort($request, ['created_at', 'subtotal_cents', 'shipped_at'], '-created_at');

        $orders = Order::query()
            ->whereIn('warehouse_id', Warehouse::accessibleBy($request->user())->select('id'))
            ->with(['customer:id,name,email,company_name', 'warehouse:id,code,name'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->query('number'), fn ($q, $number) => $q->where('number', strtoupper($number)))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        Gate::authorize('create', [Order::class, Warehouse::findOrFail($request->integer('warehouse_id'))]);

        $order = $this->orders->place($request->validated(), $request->user());

        return (new OrderResource($order->load(self::DETAIL_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(self::DETAIL_RELATIONS));
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        Gate::authorize('cancel', $order);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $order = $this->orders->cancel($order, $request->user(), $validated['reason']);

        return new OrderResource($order->load(self::DETAIL_RELATIONS));
    }

    public function ship(Request $request, Order $order): OrderResource
    {
        Gate::authorize('ship', $order);

        $validated = $request->validate(['tracking_number' => ['required', 'string', 'max:100']]);

        $order = $this->orders->ship($order, $request->user(), $validated['tracking_number']);

        return new OrderResource($order->load(self::DETAIL_RELATIONS));
    }
}
