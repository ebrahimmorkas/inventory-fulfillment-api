<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes unsafe requests safe to retry.
 *
 * If a client repeats a request with the same Idempotency-Key, the stored
 * response of the first successful attempt is returned instead of executing
 * the action again (e.g. placing the same order twice after a network timeout).
 *
 * - Keys are scoped per user and kept for IdempotencyKey::RETENTION_HOURS.
 * - Reusing a key with a different payload is rejected (422).
 * - A concurrent request with the same key gets 409 while the first is running.
 * - Only 2xx responses are stored, so a failed attempt can be retried.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key must be 8-100 characters of letters, digits, "-" or "_".'], 400);
        }

        $userId = $request->user()->id;
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $lock = Cache::lock("idempotency:{$userId}:{$key}", 30);

        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is already in progress.'], 409);
        }

        try {
            $stored = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();

            if ($stored) {
                if (! hash_equals($stored->request_fingerprint, $fingerprint)) {
                    return response()->json(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
                }

                return response($stored->response_body, $stored->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'user_id' => $userId,
                    'key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                ]);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
