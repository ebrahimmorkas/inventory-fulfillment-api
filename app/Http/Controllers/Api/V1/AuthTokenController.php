<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\IssueTokenRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    /** bcrypt hash of a throwaway value, used when no user matches the email. */
    private const TIMING_EQUALISER_HASH = '$2y$12$xq/NSBcyUBLLtc8AbOp8veOuZyEsMLmkv6vLW4eQZycFUl.RUBHxK';

    public function store(IssueTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Always run one bcrypt comparison, even for unknown emails, so response
        // time does not reveal whether an account exists.
        $passwordMatches = Hash::check($request->validated('password'), $user->password ?? self::TIMING_EQUALISER_HASH);

        // Same message for unknown email, wrong password and deactivated accounts,
        // so the endpoint cannot be used to enumerate users.
        if (! $user || ! $passwordMatches || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $token = $user->createToken($request->validated('device_name'));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
