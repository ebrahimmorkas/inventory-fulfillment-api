<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    /**
     * @param  list<array{product_id: int, requested: int, available: int}>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Insufficient stock to fulfil the request.');
    }

    public static function forProduct(int $productId, int $requested, int $available): self
    {
        return new self([['product_id' => $productId, 'requested' => $requested, 'available' => $available]]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'shortages' => $this->shortages,
        ], 409);
    }
}
