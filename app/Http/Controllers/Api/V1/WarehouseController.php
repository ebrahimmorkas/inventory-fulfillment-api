<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\WarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WarehouseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        [$column, $direction] = $this->sort($request, ['code', 'name', 'created_at'], 'code');

        $warehouses = Warehouse::query()
            ->accessibleBy($request->user())
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->query('country_code'), fn ($q, string $code) => $q->where('country_code', strtoupper($code)))
            ->orderBy($column, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return WarehouseResource::collection($warehouses);
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $warehouse = Warehouse::create($request->validated());

        return (new WarehouseResource($warehouse))->response()->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('view', $warehouse);

        return new WarehouseResource($warehouse);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $warehouse->update($request->validated());

        return new WarehouseResource($warehouse);
    }
}
