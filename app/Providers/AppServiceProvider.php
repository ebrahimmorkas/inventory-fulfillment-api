<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries and silently discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Store short aliases instead of PHP class names in polymorphic columns,
        // so renaming a class never breaks existing rows.
        Relation::enforceMorphMap([
            'order' => Order::class,
            'user' => User::class,
        ]);

        Password::defaults(fn () => Password::min(12)->letters()->numbers());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Each export is an expensive background job; cap how many a user can queue.
        RateLimiter::for('report-exports', function (Request $request) {
            return Limit::perHour(10)->by($request->user()->id);
        });
    }
}
