<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to)
    {
        parent::__construct("An order that is {$from->value} cannot be moved to {$to->value}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_status' => $this->from->value,
        ], 409);
    }
}
