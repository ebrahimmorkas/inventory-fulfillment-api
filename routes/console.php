<?php

use App\Models\IdempotencyKey;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Schedule;

/*
 * The scheduler runs in its own container (`php artisan schedule:work`).
 * onOneServer() takes a cache lock, so tasks run once even if several
 * scheduler instances are deployed.
 */

// Detect stock changed outside InventoryService (exits non-zero and logs critical).
Schedule::command('inventory:reconcile')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Expired idempotency keys and week-old report exports (including their files).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, ReportExport::class]])
    ->daily()
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->onOneServer();
