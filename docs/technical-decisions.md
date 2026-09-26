# Technical decisions

Each entry records the decision, why it was made, and what it costs.

---

## 1. Pessimistic row locks for stock reservations

**Decision.** `InventoryService` reads the affected `stock_levels` rows with
`SELECT … FOR UPDATE` inside a transaction, checks availability, then writes.

**Why.** The failure we must prevent is two requests both reading "5 available"
and both reserving 5. Alternatives considered:

| Option | Why not |
|---|---|
| Atomic conditional update (`UPDATE … SET reserved = reserved + ? WHERE on_hand - reserved >= ?`) | Works for one line, but a multi-line order needs all-or-nothing across rows and a report of *every* short line; that means reading the rows anyway. |
| Optimistic locking (version column + retry) | Hot SKUs are contended exactly when it matters (a sale, a big order); retry storms waste work and make latency unpredictable. |
| Redis distributed lock per SKU | Adds a second source of truth; if Redis and MySQL disagree, MySQL wins anyway. The database already provides the lock we need. |

**Deadlocks.** Rows are locked in ascending `product_id` order with one query, so
two multi-line orders cannot hold locks in opposite order. The order row is
always locked before its stock rows (ship/cancel). InnoDB can still report a
deadlock (for example gap locks while two requests insert the first stock level
for a product), so the outermost transactions are retried up to 3 times
(`DB::transaction($callback, attempts: 3)`).

**Evidence.** `ConcurrentReservationTest` holds a row lock on a second MySQL
connection and shows the reservation waits (error 1205 with a 1-second lock
timeout), then runs six separate PHP processes competing for 10 units and
asserts exactly three reservations of 3 succeed.

**Cost.** Throughput for a single hot SKU is serialised. That is the correct
behaviour for stock; the lock is held only for a few short queries.

## 2. Append-only ledger alongside current quantities

**Decision.** `stock_levels` stores current quantities for fast reads;
`stock_movements` stores every change as an immutable row (deltas *and* the
resulting quantities, the user, reason and a polymorphic reference to the order).

**Why.** Current levels answer "what can I sell now" in one indexed lookup.
The ledger answers "why is this number what it is", which is what finance and
warehouse staff actually ask after a stock discrepancy. Storing both is
deliberate denormalisation; the invariant between them is verified by
`inventory:reconcile` every night.

**Protections.** The model throws on update/delete; application code only
writes quantities through `InventoryService::apply()`; a MySQL
`CHECK (reserved <= on_hand)` constraint rejects impossible states even if
someone bypasses the application.

**Cost.** The ledger grows forever. It is indexed for the two access patterns
(per product, per warehouse newest-first) and read with cursor pagination.
Archiving/partitioning would be the next step at large volume.

## 3. Reservation model: `on_hand` and `reserved`

`available = on_hand - reserved`. Placing an order increases `reserved`;
shipping decreases both; cancelling decreases `reserved`. This keeps stock that
is physically in the building (`on_hand`) separate from stock that is promised.
Adjustments may not push `on_hand` below `reserved`, because that would mean
orders already promised to customers can no longer be fulfilled.

## 4. Idempotency keys stored in MySQL, guarded by a cache lock

**Decision.** `EnsureIdempotency` middleware on `POST /orders`. Responses of
successful requests are stored in `idempotency_keys` (unique per user + key)
with a SHA-256 fingerprint of method, path and body. A `Cache::lock` prevents
two concurrent requests with the same key from both executing.

**Why.** Mobile/scanner clients retry after timeouts; without this, a timeout
after commit creates a duplicate order and a duplicate reservation. MySQL is the
durable store (a cache eviction must not re-enable duplicates within the
retention window); the lock only needs to live as long as the request.

**Trade-offs.**
- Only 2xx responses are stored, so a request that failed on stock can be
  retried later with the same key.
- The stored record is written after the order transaction commits. If that
  single insert failed, a retry could duplicate the order. Closing that gap
  would require writing the key inside the order transaction.
- The lock expires after 30 seconds; a slower request could be executed twice
  by a concurrent retry.

## 5. Authorisation: permissions + warehouse scope, enforced in policies

**Decision.** Spatie roles give coarse permissions (`orders.fulfil`,
`inventory.adjust`, …). Policies combine a permission with
`User::canAccessWarehouse()`. Listing queries use `Warehouse::accessibleBy()`
so users never receive rows they may not see.

**Why.** "Can a manager ship orders?" and "can *this* manager ship *this*
order?" are different questions. Roles alone would give every manager access to
every warehouse. A separate permission `warehouses.access-all` (held by admins
and sales) avoids hard-coding role names in policies.

**Cost.** Two checks per request; assigned warehouse ids are memoised per user
instance and Spatie caches permissions.

## 6. Events only where there are independent reactions

Two events exist:

- `StockLevelChanged` — `InventoryService` should not know about alerting.
  The queued `CheckReorderPoint` listener re-reads the stock level when it runs
  (the event carries only ids), so it acts on current data, not a snapshot.
- `OrderShipped` — notifies the customer.

Both implement `ShouldDispatchAfterCommit`, so a rolled-back transaction never
sends mail about stock or shipments that do not exist
(`LowStockAlertTest::test_no_event_is_dispatched_when_the_transaction_rolls_back`).

Operations without independent side effects (creating a product, cancelling an
order) do not fire custom events.

## 7. Queues

| Work | Why queued |
|---|---|
| Emails (shipment, low stock) | SMTP latency and failures must not affect API responses; retries with backoff. |
| Low-stock check | Runs on every stock movement; moves an extra query and fan-out off the request. |
| Sales CSV export | Can scan months of orders; returns `202` immediately, runs on a separate `reports` queue so exports cannot delay emails. |

**Idempotency of jobs.** `CheckReorderPoint` uses `Cache::add` (atomic) as a
cooldown, so duplicate deliveries or bursts send one alert.
`GenerateSalesReport` returns early if the export is already completed.
`failed()` records a user-safe message; the exception is logged.

**Redis outage.** The queue connection is Laravel's `failover` driver (Redis,
then the database `jobs` table), with a second worker on the database
connection. This was added after an end-to-end test with Redis stopped showed
that orders were committed but the request returned 500 (see §9).

## 8. Caching the valuation report with stale-while-revalidate

**Decision.** One system-wide cache entry, filtered per user after reading,
using `Cache::flexible()` (fresh 60 s, stale-but-served up to 300 s while it
recomputes after the response).

**Why.** The aggregate scans all stock levels. Caching per user would multiply
entries and misses; filtering a few rows in PHP is cheap. Event-based
invalidation was rejected: every stock movement would flush it, making the hit
rate near zero on a busy day. A valuation that is up to 5 minutes old is
acceptable, and the response includes `generated_at`.

## 9. Redis failure behaviour

Cache store and queue connection both use `failover` drivers backed by the
database, and Redis connections have 2-second timeouts. Verified by stopping
the Redis container on the running stack. Known cost: Laravel's failover
drivers try Redis first on every call. When Redis refuses connections this is
instant; when the host is unreachable each call waits for the timeout, and in
the stopped-container test an order request took about 24 seconds. A circuit
breaker is the next improvement.

## 10. Testing against MySQL, not SQLite

Row locks, `CHECK` constraints, lock wait timeouts and some SQL (`SUM` of a
boolean expression) behave differently or not at all in SQLite. The suite runs
against a `testing` database in the same MySQL 8.4 image used in development and
CI. It takes longer, but the tests prove the behaviour that matters.
`Model::shouldBeStrict()` is enabled outside production, so a lazy-loaded
relation (an N+1) throws in tests.

## 11. Money as integer cents

Prices and totals are integers (`unit_price_cents`, `line_total_cents`,
`subtotal_cents`) to avoid floating-point rounding. Line prices are copied from
the catalogue when the order is placed, so later price changes do not alter
historical orders, and clients cannot choose their own price.

## 12. Docker layout

One image with `development` and `production` targets. In development the
source is bind-mounted and `vendor/` lives in a named volume (much faster on
Windows/macOS). All PHP processes (FPM, queue workers, scheduler, artisan) run
as `www-data`. This was introduced after the queue worker (root) wrote export
files that FPM (`www-data`) could not read. The production target installs
without dev dependencies, optimises the autoloader, enables OPcache without
timestamp checks and runs as a non-root user.

## Scalability notes

- API servers are stateless (token auth, cache/queue external), so they scale horizontally.
- Scheduled tasks use `onOneServer()`, so several scheduler instances are safe.
- The hottest contention point is a single popular SKU in one warehouse; that is
  inherent to correct stock accounting. Splitting stock into bins or allocating
  from several warehouses would spread it.
- Reporting queries could move to a read replica.
- The ledger could be partitioned by month once it reaches hundreds of millions of rows.
