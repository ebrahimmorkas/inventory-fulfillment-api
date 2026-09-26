# Interview notes — Inventory Fulfillment API

Questions an interviewer is likely to ask about this project, with answers
based on what the code actually does. File references point to the source.

---

## Architecture and Laravel

**Q: Walk me through what happens when a sales user places an order.**
`POST /api/v1/orders` passes `auth:sanctum`, `active` (deactivated users are
rejected), `throttle:api` and the `idempotent` middleware. `PlaceOrderRequest`
validates the payload (active warehouse and products, distinct product ids,
nested `ship_to`) and checks the `orders.create` permission. The controller
loads the warehouse and authorises `create` against it through `OrderPolicy`.
`OrderService::place()` opens a transaction, loads catalogue prices, creates the
order and lines, assigns the order number, then calls
`InventoryService::reserve()`, which locks the stock rows, checks every line and
writes reservation movements. The response is an `OrderResource` with `201`.
After commit, `StockLevelChanged` events are dispatched and a queued listener
checks reorder points.

**Q: Why services? Why not put this in the controller or the model?**
The same operations run from HTTP controllers, the demo seeder, tests and the
parallel-process test helper. Stock rules (locking, ledger entries, invariants)
must be in exactly one place: `InventoryService` is the only code that changes
`on_hand`/`reserved`. Controllers stay thin: validate, authorise, call a
service, return a resource.

**Q: Why are there only two custom events?**
Events are used where one action has independent reactions:
`StockLevelChanged` (low-stock alerting) and `OrderShipped` (customer email).
Creating a product or cancelling an order has no independent side effects, so
it has no event.

**Q: How are routes versioned?**
Everything is under `/api/v1` (`routes/api.php`), with named routes (`v1.*`).
A breaking change would add `/api/v2` controllers/resources while v1 continues.

**Q: What PHP 8.4 / Laravel 13 features did you use?**
Backed enums for statuses, roles and permissions (cast on models, validated with
`Rule::enum`); readonly promoted constructor properties; first-class callable
syntax (`$this->assertPositive(...)`); model attributes `#[Fillable]`/`#[Hidden]`
and command attributes `#[Signature]`; `Cache::flexible`; failover cache and
queue drivers; `createOrFirst`; `lazyById`; `cursorPaginate`.

## Database and Eloquent

**Q: Why store both current stock and a ledger?**
Current levels are one indexed lookup for "what can I sell". The ledger
explains every change (who, why, which order). It is deliberate denormalisation,
and the invariant between them is checked nightly by `inventory:reconcile`.

**Q: What stops the data from becoming inconsistent?**
Four layers: all writes go through `InventoryService`; `StockMovement` throws on
update/delete; a MySQL `CHECK (reserved <= on_hand)` constraint; and the
reconciliation command, which compares levels with ledger sums *and* reserved
quantities with open order lines, failing loudly on drift.

**Q: Which indexes did you add and why?**
Unique `(warehouse_id, product_id)` on stock levels (one row per pair, and the
lock lookup); `(warehouse_id, product_id, id)` and `(warehouse_id, id)` on the
ledger for per-product history and newest-first warehouse history (the second
was added in its own migration when the endpoint needed it);
`(status, shipped_at)` for the sales export; `(warehouse_id, status)` for order
lists; unique `(user_id, key)` for idempotency keys.

**Q: Why `RESTRICT` on foreign keys to products and warehouses?**
Deleting a product that has stock or order history would destroy audit data.
The API has no delete endpoints for them; they are deactivated with `is_active`.
`order_items` cascade with their order; pivot rows cascade with users/warehouses.

**Q: How do you avoid N+1 queries?**
Eager loading with column lists (`with(['customer:id,name,…'])`, `withCount('items')`).
`Model::shouldBeStrict()` is on outside production, so any lazy load throws; the
listing tests would fail if a relation were not eager loaded. Strict mode also
caught resources that read columns I had not selected.

**Q: Why cursor pagination for the ledger?**
It grows without bound. Offset pagination needs `COUNT(*)` and deep `OFFSET`
scans; a cursor on `id` uses the `(warehouse_id, id)` index directly.

**Q: Why is the order number assigned in the service and not in a model event?**
Originally it was set in a `created` model hook. The demo seeder wrapped work in
`Event::fakeFor()`, which also suppresses Eloquent events, and orders ended up
without numbers. A business identifier should not depend on an event firing, so
`OrderService` sets it explicitly in the same transaction (regression test:
`test_order_number_is_assigned_even_when_model_events_are_faked`).

## Concurrency and transactions

**Q: How do you prevent overselling?**
`SELECT … FOR UPDATE` on the relevant stock rows inside a transaction, then
check and write. A second request for the same rows blocks until the first
commits and then sees the updated `reserved`.

**Q: Why not optimistic locking?**
Hot SKUs are contended exactly during peaks. Optimistic locking would turn that
into retry storms. Pessimistic locks queue the requests, and each holds the lock
for only a few queries.

**Q: How do you avoid deadlocks?**
Locks are taken in a deterministic order: all stock rows for an operation in one
query ordered by `product_id`, and order rows before stock rows. InnoDB can still
deadlock on gap locks, so outermost transactions retry up to 3 times. Laravel
only retries at transaction level 1, so nested calls rethrow to the outer one.

**Q: How did you test concurrency?**
Two ways (`tests/Feature/Inventory/ConcurrentReservationTest.php`):
1. A second DB connection locks the row; with `innodb_lock_wait_timeout = 1`
   the reservation fails with MySQL error 1205, which proves it waits for the
   lock. After releasing, the reservation succeeds.
2. Six separate PHP processes (`Process::pool`) each try to reserve 3 of 10
   units; exactly 3 succeed and `reserved` ends at 9.
These tests commit real rows (no `RefreshDatabase` transaction) because other
connections must see the data, and they clean up in `tearDown`.

**Q: What happens if a multi-line order is short on one line?**
Nothing is written. `reserve()` checks every line before changing any and throws
`InsufficientStockException` listing every short line; the transaction rolls
back the order too. The client gets `409` with a `shortages` array.

**Q: How do ship and cancel avoid racing each other?**
`lockForTransition()` re-reads the order `FOR UPDATE` and validates the status
transition with `OrderStatus::canTransitionTo()`. The second request waits, then
sees the new status and gets `409`.

## API design

**Q: Why 409 for insufficient stock instead of 422?**
The request is valid; it conflicts with the current state of the resource.
Validation errors (`422`) are for malformed input.

**Q: How does idempotency work?**
See `EnsureIdempotency`. Key per user; fingerprint (SHA-256 of method, path and
body) detects reuse with a different payload (`422`); a cache lock rejects a
concurrent duplicate (`409`); the stored response is replayed with
`Idempotent-Replayed: true`. Only 2xx responses are stored so failures can be
retried. Keys are pruned after 24 hours by `model:prune`.

**Q: Could a client set its own price?**
No. `PlaceOrderRequest` has no price rules, so `validated()` drops any price
fields, and `OrderService` reads `unit_price_cents` from the products table. A
test sends `unit_price_cents: 1` and asserts the catalogue price is used.

**Q: How are sorting and filtering made safe?**
Sort columns come from an allow-list (`Controller::sort()`); unknown values fall
back to the default. Filters are validated and bound as query parameters.

## Authentication and authorisation

**Q: Why Sanctum tokens rather than sessions or Passport?**
The clients are other systems and scanners, not a first-party SPA. Sanctum's
personal access tokens are simple and stored hashed. OAuth (Passport) would add
flows nobody needs here.

**Q: What happens when a user is deactivated?**
All their tokens are deleted in the same transaction, and `EnsureUserIsActive`
rejects any request from an inactive user as a second layer. Login rejects them
with the same generic message as a wrong password.

**Q: How do you prevent user enumeration at login?**
The same error message for every failure, and a bcrypt comparison is always run
(against a dummy hash if the email is unknown), so response time does not
reveal whether the account exists. Login is rate limited per email+IP and per IP.

**Q: How is warehouse scoping enforced?**
Policies combine a permission with `canAccessWarehouse()`. List queries filter
with `Warehouse::accessibleBy($user)`. The sales export stores the warehouse ids
the user could access when it was requested, so the background job cannot widen
access.

**Q: How did you test authorisation?**
Each feature test file includes forbidden cases: managers of another warehouse
cannot see, ship or adjust; sales cannot ship or adjust; non-admins cannot
manage users or the catalogue; users cannot read other users' exports or
notifications; admins cannot deactivate themselves.

## Caching and Redis

**Q: What is cached and how is it invalidated?**
The inventory valuation aggregate: one system-wide entry with
`Cache::flexible` (fresh 60 s, stale-while-revalidate up to 300 s). It is
time-based by design; flushing on every stock movement would make the hit rate
near zero. Also: Spatie's permission cache, the low-stock cooldown key
(`Cache::add`, cleared when stock recovers), idempotency locks and rate limiter
counters.

**Q: What happens if Redis goes down?**
Both cache and queue use Laravel failover drivers backed by the database. I
verified this by stopping Redis on the running stack. Before the fix, orders
were committed but the request returned 500 because the after-commit event
could not be queued; now jobs go to the database `jobs` table and a second
worker processes them. The remaining cost is latency: failover tries Redis on
every call, so an unreachable host adds a timeout per call.

## Queues and scheduling

**Q: How do you make jobs safe to retry?**
`CheckReorderPoint` re-reads current stock and uses an atomic `Cache::add`
cooldown; `GenerateSalesReport` returns early if the export is already
completed. Notifications have `tries` and `backoff`. `failed()` on the export
job marks it failed with a user-safe message and logs the real exception.

**Q: How does the export handle large date ranges?**
`lazyById(1000)` streams rows in chunks into a `php://temp` stream, which is
written to the private disk with `writeStream`, so memory is flat. Exports run
on a separate `reports` queue so they cannot delay emails, and users are
limited to 10 export requests per hour.

**Q: What does the scheduler run?**
`inventory:reconcile` daily, `model:prune` (idempotency keys, exports and their
files), `sanctum:prune-expired`, and weekly `queue:prune-failed`, all with
`onOneServer()` so multiple scheduler instances are safe.

## Security

**Q: What security issues did you consider?**
IDOR (policies + scoped queries + owner checks), mass assignment (`#[Fillable]`
plus validated data only), SQL injection (bindings, allow-listed sorts), data
exposure (resources with explicit fields, 404s without model names, export
errors without internals), CSV formula injection in exports (cells starting with
`= + - @` are prefixed), user enumeration, brute force (rate limits), private
file serving (private disk with `serve => false`, authorised download), and
secrets (`.env` ignored, demo password from config/env). CSRF does not apply to
bearer-token APIs.

## Testing

**Q: Why run tests on MySQL?**
Row locks, the CHECK constraint, lock timeouts and some SQL are MySQL-specific.
SQLite would give false confidence for exactly the behaviour this project is about.

**Q: Which bugs did testing or verification find?**
- Strict mode found resources reading columns that were not selected.
- End-to-end testing through nginx found that files written by the root queue
  worker were unreadable by PHP-FPM, and that orders were committed but returned
  500 while Redis was down.
- Inspecting live responses found orders without numbers (model events
  suppressed by `Event::fakeFor`) and tokens without `expires_at`.
- A concurrency test was flaky because it compared arrays whose key order
  depended on which process finished first.

## Deployment

**Q: How would you deploy this?**
Build the `production` Docker target (no dev dependencies, optimised
autoloader, OPcache, non-root user), run `php artisan migrate --force`,
`config:cache`, `route:cache`, and run separate processes for PHP-FPM, queue
workers (Redis and database fallback) and the scheduler. It has not been
deployed to a public environment.

## Performance

**Q: Where are the performance risks?**
A single hot SKU serialises reservations (inherent to correct stock); the ledger
grows (indexed, cursor-paginated, could be partitioned); the valuation aggregate
(cached); exports (queued and streamed). Listings eager load relations, select
only needed columns and cap page size at 100.
