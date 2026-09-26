<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'min_price_cents' => ['nullable', 'integer', 'min:0'],
            'max_price_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        [$column, $direction] = $this->sort($request, ['sku', 'name', 'unit_price_cents', 'created_at'], 'name');

        $products = Product::query()
            ->when($request->query('search'), function ($query, string $search) {
                // SKU matches are prefix searches so they can use the unique index.
                $query->where(fn ($q) => $q->where('sku', 'like', strtoupper($search).'%')->orWhere('name', 'like', "%{$search}%"));
            })
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('min_price_cents'), fn ($q) => $q->where('unit_price_cents', '>=', $request->integer('min_price_cents')))
            ->when($request->filled('max_price_cents'), fn ($q) => $q->where('unit_price_cents', '<=', $request->integer('max_price_cents')))
            ->orderBy($column, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product);
    }
}
