# Inventory Fulfillment API

A REST API for a B2B distributor that holds stock in several warehouses and
fulfils customer orders from them. Built with **Laravel 13**, **PHP 8.4**,
**MySQL 8.4** and **Redis 7**.

The main engineering problem it solves: **never oversell stock, even when many
orders for the same item arrive at the same time**, while keeping a complete,
auditable history of every stock change.

[![tests](https://github.com/ebrahimmorkas/inventory-fulfillment-api/actions/workflows/tests.yml/badge.svg)](https://github.com/ebrahimmorkas/inventory-fulfillment-api/actions/workflows/tests.yml)

---

## Contents

- [Business problem](#business-problem)
- [Features](#features)
- [Technology stack](#technology-stack)
- [Architecture](#architecture)
- [Database overview](#database-overview)
- [Getting started (Docker)](#getting-started-docker)
- [Environment configuration](#environment-configuration)
- [Authentication and roles](#authentication-and-roles)
- [API documentation](#api-documentation)
- [Queues, scheduler and Redis](#queues-scheduler-and-redis)
- [Testing](#testing)
- [Security](#security)
- [Known limitations](#known-limitations)
- [Future improvements](#future-improvements)

Further reading: [`docs/technical-decisions.md`](docs/technical-decisions.md) ·
[`docs/api.md`](docs/api.md) · [`docs/interview-notes.md`](docs/interview-notes.md)

---

## Business problem

A distributor sells through a sales team and ships from three warehouses.
Before this system:

- Two sales people could promise the **same last units** to different customers.
- Stock corrections were made directly in a spreadsheet with **no audit trail**.
- Warehouse managers found out an item was running low **after** it ran out.
- Finance asked engineering for ad-hoc sales exports.

## Features

**Core**

- Token authentication (Laravel Sanctum) with expiring tokens and logout.
- Three roles — admin, warehouse manager, sales — with permission-based
  authorisation and **warehouse scoping** (managers only see and act on the
  warehouses they are assigned to).
- Catalogue management: warehouses, products (SKU), customers.
- Stock receipts, audited stock adjustments and per-warehouse reorder points.
- Order lifecycle: `confirmed` (stock reserved) → `shipped` or `cancelled`.

**Advanced**

- **Concurrency-safe reservations**: stock rows are locked with
  `SELECT … FOR UPDATE` in a fixed order; multi-line orders are all-or-nothing
  and report every short line (`409 Conflict`).
- **Append-only stock ledger**: every change writes an immutable movement row;
  a scheduled command verifies that levels always equal the sum of the ledger.
- **Idempotent order placement** via an `Idempotency-Key` header, so a client
  retrying after a timeout cannot create a duplicate order.
- **Queued low-stock alerts** (mail + in-app) with an atomic cooldown so a burst
  of orders produces one alert, not fifty.
- **Queued CSV sales export** streamed with `lazyById()`, stored privately and
  downloadable only by the requester.
- **Cached inventory valuation report** using stale-while-revalidate.
- Scheduled maintenance: reconciliation, pruning of idempotency keys, exports,
  expired tokens and failed jobs.

## Technology stack

| Concern | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.4) |
| Database | MySQL 8.4 (InnoDB row locks, CHECK constraint) |
| Cache / queue / locks | Redis 7 (cache falls back to the database store) |
| Auth | Laravel Sanctum personal access tokens |
| Authorisation | Policies + `spatie/laravel-permission` |
| Tests | PHPUnit 12 against a real MySQL database |
| Code style | Laravel Pint |
| Runtime | Docker Compose: PHP-FPM, nginx, MySQL, Redis, queue workers, scheduler |
| CI | GitHub Actions (Pint + full test suite on MySQL) |

## Architecture

```
HTTP ─► nginx ─► PHP-FPM ─► routes/api.php (/api/v1, auth:sanctum, active, throttle)
                                 │
                 Form Requests ──┤  validation + first authorisation check
                 Policies ───────┤  permission + warehouse scope
                 Controllers ────┤  thin: translate HTTP ⇄ service calls
                                 ▼
                 OrderService ──► InventoryService ──► stock_levels (locked) + stock_movements
                                 │                        │
                                 │ OrderShipped            │ StockLevelChanged
                                 ▼ (after commit)          ▼ (after commit)
                 NotifyCustomerOfShipment        CheckReorderPoint (queued)
                   └► OrderShippedNotification     └► LowStockAlert (mail + database)
                      (queued mail)

Queue workers: notifications, listeners, GenerateSalesReport (queue "reports")
  queue          → Redis connection (normal operation)
  queue-fallback → database connection (jobs written while Redis was unavailable)
Scheduler: inventory:reconcile, model:prune, sanctum:prune-expired, queue:prune-failed
```

Key components:

| Path | Responsibility |
|---|---|
| `app/Services/InventoryService.php` | The **only** code that changes stock quantities. Locks rows, validates, writes the ledger. |
| `app/Services/OrderService.php` | Places, cancels and ships orders inside transactions; snapshots prices. |
| `app/Http/Middleware/EnsureIdempotency.php` | Stores and replays responses for `Idempotency-Key`. |
| `app/Policies/*` | Permission + warehouse-scope rules, enforced server-side. |
| `app/Listeners/CheckReorderPoint.php` | Queued, idempotent low-stock detection. |
| `app/Jobs/GenerateSalesReport.php` | Streaming CSV export with retry/failure handling. |
| `app/Reports/InventoryValuationReport.php` | Cached aggregate report. |
| `app/Console/Commands/ReconcileInventory.php` | Integrity check of ledger and reservations. |

## Database overview

| Table | Purpose | Notable constraints / indexes |
|---|---|---|
| `users`, `warehouse_user` | Staff and their warehouse assignments | composite PK on pivot |
| `roles`, `permissions`, … | Spatie permission tables | |
| `warehouses` | Fulfilment locations | unique `code` |
| `products` | Catalogue (price in integer cents) | unique `sku`, index `(is_active, name)` |
| `customers` | B2B customers | unique `email` |
| `stock_levels` | Current `on_hand`, `reserved`, `reorder_point` per warehouse/product | unique `(warehouse_id, product_id)`, **`CHECK (reserved <= on_hand)`**, FKs `RESTRICT` |
| `stock_movements` | Immutable ledger (deltas + resulting quantities + reference) | indexes `(warehouse_id, product_id, id)`, `(warehouse_id, id)` |
| `orders`, `order_items` | Orders with snapshotted line prices and shipping address | unique `number`, `(order_id, product_id)`, indexes for status/date filters |
| `idempotency_keys` | Stored responses for idempotent requests | unique `(user_id, key)` |
| `report_exports` | Export requests and their files | index `(user_id, created_at)` |
| `notifications`, `jobs`, `failed_jobs`, `cache` | Laravel infrastructure tables | |

Money is stored as integer cents. Catalogue rows referenced by stock or orders
cannot be deleted (`RESTRICT`); they are deactivated instead.

## Getting started (Docker)

Requirements: Docker with Compose v2. Nothing else needs to be installed locally.

```bash
git clone https://github.com/ebrahimmorkas/inventory-fulfillment-api.git
cd inventory-fulfillment-api
cp .env.example .env                    # then set DB_PASSWORD / DB_ROOT_PASSWORD

docker compose build
docker compose up -d mysql redis
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d                    # app, nginx, queue workers, scheduler
docker compose exec app php artisan migrate --seed

# Optional: realistic demo data (warehouses, staff, stock, 60 orders)
docker compose exec app php artisan db:seed --class=DemoDataSeeder
```

The API is served at `http://localhost:8080/api/v1`. MySQL is exposed on
`localhost:33061` for database tools.

On Linux, set `UID`/`GID` in your shell (e.g. `export UID GID=$(id -g)`) before
building so files written by the containers belong to your user.

`DemoDataSeeder` creates `admin@example.com`, `sales@example.com` and one
manager per warehouse (`manager.london@example.com`, …). They use
`DEMO_USER_PASSWORD` from `.env`, or a random password printed by the seeder.

## Environment configuration

| Variable | Meaning |
|---|---|
| `DB_*` | MySQL connection. Host `mysql` inside Docker. |
| `DB_ROOT_PASSWORD` | Used by the MySQL container and its healthcheck. |
| `CACHE_STORE=failover` | Redis cache, falling back to the database cache store. |
| `QUEUE_CONNECTION=failover` | Jobs go to Redis, or to the database `jobs` table if Redis refuses them. |
| `REDIS_TIMEOUT`, `REDIS_READ_TIMEOUT` | Seconds before a Redis call gives up and the failover store/queue takes over (default 2). |
| `SANCTUM_TOKEN_EXPIRATION` | Token lifetime in minutes (default 7 days). |
| `DEMO_USER_PASSWORD` | Password for demo accounts (local only). |
| `APP_PORT`, `FORWARD_DB_PORT` | Host ports for nginx and MySQL. |

For production: `APP_ENV=production`, `APP_DEBUG=false`, a real mail driver,
and build the `production` Docker target (`docker build --target production .`),
which installs without dev dependencies, optimises the autoloader, enables
OPcache without timestamp checks and runs as `www-data`.

## Authentication and roles

```http
POST /api/v1/auth/tokens
{ "email": "sales@example.com", "password": "…", "device_name": "cli" }
```

Returns `201` with a bearer token. Send it as `Authorization: Bearer <token>`.
Login is throttled to 5 attempts per minute per email+IP. Deactivated users are
rejected at login **and** on every request; deactivation also revokes all tokens.

| Permission | Admin | Warehouse manager | Sales |
|---|:-:|:-:|:-:|
| users.manage, catalog.manage | ✓ | | |
| customers.manage | ✓ | | ✓ |
| warehouses.access-all | ✓ | | ✓ |
| inventory.view | ✓ | ✓ (own) | ✓ |
| inventory.adjust | ✓ | ✓ (own) | |
| orders.view | ✓ | ✓ (own) | ✓ |
| orders.create, orders.cancel | ✓ | | ✓ |
| orders.fulfil | ✓ | ✓ (own) | |
| reports.view | ✓ | ✓ (own) | |

"(own)" = only for warehouses the user is assigned to.

## API documentation

All endpoints are under `/api/v1`, return JSON and require a bearer token
except `POST /auth/tokens`. The full reference with request/response examples
is in [`docs/api.md`](docs/api.md).

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/tokens`, `DELETE /auth/tokens/current`, `GET /auth/me` |
| Users | `GET/POST /users`, `GET/PATCH /users/{id}` |
| Catalogue | `GET/POST/PATCH` on `/warehouses`, `/products`, `/customers` |
| Stock | `GET /warehouses/{id}/stock`, `PATCH /warehouses/{id}/stock/{product}`, `POST /warehouses/{id}/stock-receipts`, `POST /warehouses/{id}/stock-adjustments`, `GET /warehouses/{id}/stock-movements`, `GET /products/{id}/stock` |
| Orders | `GET/POST /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`, `POST /orders/{id}/ship` |
| Reports | `GET /reports/inventory-valuation`, `GET/POST /reports/sales-exports`, `GET /reports/sales-exports/{id}`, `GET /reports/sales-exports/{id}/download` |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read` |

Conventions:

- Lists are paginated (`per_page`, max 100) and sortable with an allow-listed
  `sort` parameter (`sort=-created_at` for descending). The stock ledger uses
  cursor pagination.
- Status codes: `201` created, `202` accepted (queued export), `204` no content,
  `401` unauthenticated, `403` forbidden, `404` not found, `409` business
  conflict (insufficient stock, invalid state transition, export not ready,
  idempotency key in use), `422` validation error, `429` rate limited.

## Queues, scheduler and Redis

**Queued work** (Redis, processed by the `queue` container):

| Job | Queue | Retries |
|---|---|---|
| `OrderShippedNotification` (mail) | default | 5, backoff 30s/2m/10m |
| `CheckReorderPoint` listener | default | 3 |
| `LowStockAlert` (mail + database) | default | 5, backoff 30s/2m/10m |
| `GenerateSalesReport` | reports | 3, backoff 1m/5m, timeout 10m |

Failed jobs are stored in `failed_jobs` (`php artisan queue:failed`,
`queue:retry`). `GenerateSalesReport::failed()` marks the export as failed
with a user-safe message and logs the real error.

**Scheduler** (`scheduler` container runs `schedule:work`): `inventory:reconcile`
daily at 02:00, `model:prune` (idempotency keys > 24h, exports > 7 days with
their files) daily, `sanctum:prune-expired` daily, `queue:prune-failed` weekly.
All use `onOneServer()`.

**What Redis is used for**

| Use | Why |
|---|---|
| Queue backend | Fast, supports delayed retries; decouples mail/report work from requests. |
| Inventory valuation cache | Aggregate over all stock rows; `Cache::flexible` fresh 60s, served stale up to 5 min while refreshing after the response. |
| Low-stock alert cooldown | `Cache::add` is atomic, so concurrent workers send one alert per item per 12h; the key is cleared when stock recovers. |
| Idempotency locks | `Cache::lock` rejects a second in-flight request with the same key. |
| Rate limiting | Login, API and export-request limiters. |
| Spatie permission cache | Avoids loading roles/permissions on every request. |

**If Redis is unavailable:** both the cache store and the queue connection
are `failover` drivers.

- Caching, locks and rate limiting fall back to the database `cache` table.
- Jobs are written to the database `jobs` table and processed by the
  `queue-fallback` worker, so placing and shipping orders keeps working.
- This was verified by stopping the Redis container on the running stack:
  login, listings and order placement returned 2xx, and the fallback worker ran
  the queued listener. `tests/Feature/Infrastructure/RedisFailoverTest.php`
  covers the same configuration automatically.
- Cost: Laravel's failover drivers try Redis first on **every** call. When
  Redis refuses connections this is instant; when the host is unreachable each
  call waits up to `REDIS_TIMEOUT`. In the stopped-container test (where DNS
  lookup of the missing host is slow) an order request took about 24 seconds
  instead of well under one. A circuit breaker is listed under future
  improvements.
- Cached values are not shared between Redis and the database store, so the
  valuation report is recomputed and alert cooldowns start afresh.

## Testing

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
```

105 tests run against a dedicated MySQL `testing` database (created by
`docker/mysql/create-testing-database.sql`), because the behaviour under test
— row locks, the CHECK constraint, foreign keys — is MySQL-specific.

| Area | Examples |
|---|---|
| Authentication | token issue/revoke, deactivated users, rate limiting, generic errors |
| Authorisation | every role against every protected action, cross-warehouse access, owner-only exports and notifications |
| Validation | products, orders (nested items), adjustments, users, exports |
| Business logic | all-or-nothing reservations, price snapshotting, state transitions, ledger totals |
| Concurrency | a second DB connection holds a row lock → reservation waits (lock timeout 1205); six parallel PHP processes race for 10 units → exactly 3 succeed |
| Idempotency | replay, payload mismatch, per-user scope, in-flight lock, failures not stored |
| Queues / events | shipment mail, low-stock alert recipients and cooldown, no events on rollback, export job, failure handling, redelivery |
| Scheduling | reconciliation detects ledger and reservation drift; pruning deletes rows and files |
| Performance | strict mode throws on lazy loading, so listing tests prove eager loading; valuation served from cache |

CI runs Pint and the full suite on every push (`.github/workflows/tests.yml`).

## Security

- Authorisation is enforced server-side in Form Requests, policies and scoped
  queries; tests cover forbidden access for each role.
- IDOR: orders, stock and reports are scoped to accessible warehouses; exports
  and notifications to their owner.
- Mass assignment: models declare `#[Fillable]`; controllers pass only
  validated data. Order prices come from the catalogue, never the request.
- Sorting uses allow-lists; all queries use bindings.
- Login returns one generic error and always performs a bcrypt comparison, to
  avoid account enumeration.
- 404 responses do not reveal model class names.
- Exports are stored on a private disk, served only through an authorised
  controller, and protected against CSV formula injection.
- No secrets are committed; `.env.example` contains placeholders only.
- nginx denies dotfiles and sends `nosniff`, `DENY` framing and no-referrer headers.

## Known limitations

- An order ships from a single warehouse; split shipments are not supported.
- Partial shipments and returns are not modelled.
- Prices are single-currency integer cents; no tax or discounts.
- If the database write of an idempotency record fails after the order has
  committed, a retry with the same key would create a second order (very
  unlikely; would need an outbox/same-transaction write to close fully).
- The idempotency lock lasts 30 seconds; a request slower than that could be
  processed twice if retried concurrently.
- The valuation report can be up to 5 minutes stale by design.
- No API documentation generator (OpenAPI) — the reference is hand-written.
- Not deployed to a public environment.

## Future improvements

- OpenAPI specification generated from Form Requests and Resources.
- Purchase orders feeding stock receipts.
- Multi-warehouse split fulfilment and partial shipments.
- Webhooks for order status changes.
- A circuit breaker around Redis so an outage costs one timeout, not one per call.
- Horizon for queue monitoring; Pulse/Telescope for observability.
- Read replica for reporting queries.

## License

MIT
