<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_obtain_a_token_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'manager@example.com']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'manager@example.com',
            'password' => 'password',
            'device_name' => 'warehouse-scanner-01',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'email']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_issued_tokens_expire_after_the_configured_lifetime(): void
    {
        config(['sanctum.expiration' => 60]);
        User::factory()->create(['email' => 'manager@example.com']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'manager@example.com',
            'password' => 'password',
            'device_name' => 'cli',
        ]);

        $this->assertEqualsWithDelta(now()->addHour()->timestamp, strtotime($response->json('expires_at')), 5);

        $this->travel(61)->minutes();
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_can_be_used_to_access_protected_endpoints(): void
    {
        User::factory()->create(['email' => 'manager@example.com']);

        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'manager@example.com',
            'password' => 'password',
            'device_name' => 'cli',
        ])->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'manager@example.com');
    }

    public function test_wrong_password_is_rejected_with_a_generic_message(): void
    {
        User::factory()->create(['email' => 'manager@example.com']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'manager@example.com',
            'password' => 'wrong-password',
            'device_name' => 'cli',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);
    }

    public function test_deactivated_user_cannot_obtain_a_token(): void
    {
        User::factory()->inactive()->create(['email' => 'former@example.com']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'former@example.com',
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);
    }

    public function test_deactivated_user_is_locked_out_even_with_an_existing_token(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->create());

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account has been deactivated.');
    }

    public function test_validation_errors_are_returned_for_missing_fields(): void
    {
        $this->postJson('/api/v1/auth/tokens', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_guests_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('laptop')->plainTextToken;
        $user->createToken('phone');

        $this->withToken($current)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());
        $this->assertNull(PersonalAccessToken::findToken($current));
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'manager@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/tokens', [
                'email' => 'manager@example.com',
                'password' => 'wrong-password',
                'device_name' => 'cli',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'manager@example.com',
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertTooManyRequests();
    }

    public function test_missing_resources_do_not_leak_model_names(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }
}
