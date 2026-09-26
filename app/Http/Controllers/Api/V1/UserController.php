<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        [$column, $direction] = $this->sort($request, ['name', 'email', 'created_at'], 'name');

        $users = User::query()
            ->with(['roles:id,name', 'warehouses:id'])
            ->when($request->query('search'), function ($query, string $search) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($request->query('role'), fn ($query, string $role) => $query->role($role))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy($column, $direction)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->assignRole($request->validated('role'));
            $user->warehouses()->sync($request->validated('warehouse_ids', []));

            return $user;
        });

        return (new UserResource($user->load(['roles:id,name', 'warehouses:id'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['roles:id,name', 'warehouses:id']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        DB::transaction(function () use ($request, $user) {
            $user->update($request->safe()->only(['name', 'email', 'is_active']));

            if ($request->has('role')) {
                $user->syncRoles([$request->validated('role')]);
            }

            if ($request->has('warehouse_ids')) {
                $user->warehouses()->sync($request->validated('warehouse_ids'));
            }

            // Deactivation takes effect immediately: existing tokens are revoked.
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }
        });

        return new UserResource($user->load(['roles:id,name', 'warehouses:id']));
    }
}
