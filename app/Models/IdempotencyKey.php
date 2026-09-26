<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Stored response for a request made with an Idempotency-Key header.
 */
#[Fillable(['user_id', 'key', 'request_fingerprint', 'response_status', 'response_body'])]
class IdempotencyKey extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** How long a key can be replayed; clients must not retry after this. */
    public const RETENTION_HOURS = 24;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
