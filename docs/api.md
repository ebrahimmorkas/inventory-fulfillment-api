# API reference

Base URL: `http://localhost:8080/api/v1` (Docker setup). Every request must send
`Accept: application/json`. All endpoints except `POST /auth/tokens` require
`Authorization: Bearer <token>`.

## Conventions

**Pagination.** List endpoints return Laravel's paginated resource format:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 25, "total": 40, "last_page": 2, ... }
}
```

`per_page` defaults to 25 and is capped at 100. `GET /warehouses/{id}/stock-movements`
uses cursor pagination (`links.next` contains a `cursor` parameter; no `total`).

**Sorting.** `sort=<field>` ascending, `sort=-<field>` descending. Unknown fields
fall back to the endpoint's default.

**Errors.**

| Status | Body |
|---|---|
| 401 | `{"message": "Unauthenticated."}` |
| 403 | `{"message": "This action is unauthorized."}` (or a specific reason) |
| 404 | `{"message": "Resource not found."}` |
| 409 | Business conflict, see each endpoint |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` |
| 429 | `{"message": "Too Many Attempts."}` with `Retry-After` header |

Money is always an integer number of cents (`unit_price_cents`, `subtotal_cents`).

---

## Authentication

### `POST /auth/tokens`

Rate limit: 5/min per email+IP, 20/min per IP.

```json
{ "email": "sales@example.com", "password": "secret", "device_name": "pos-terminal-3" }
```

`201 Created`

```json
{
  "token": "12|mQ0h...",
  "token_type": "Bearer",
  "expires_at": "2026-10-03T09:12:44+00:00",
  "user": { "id": 5, "name": "Sam Patel", "email": "sales@example.com", "is_active": true, "created_at": "..." }
}
```

`422` with `errors.email = ["These credentials do not match our records."]` for a
wrong password, unknown email or deactivated account.

### `DELETE /auth/tokens/current` → `204`
Revokes the token used for the request only.

### `GET /auth/me` → `200 {"data": User}`

---

## Users (admin only)

| Method | Path | Notes |
|---|---|---|
| GET | `/users` | Filters: `search` (name/email), `role`, `is_active`. Sort: `name`, `email`, `created_at`. |
| POST | `/users` | `name`, `email`, `password` (min 12, letters + numbers), `role` (`admin`, `warehouse-manager`, `sales`), `warehouse_ids[]` |
| GET | `/users/{id}` | Users may also view their own profile. |
| PATCH | `/users/{id}` | Any of `name`, `email`, `role`, `is_active`, `warehouse_ids[]`. Setting `is_active=false` revokes all the user's tokens. Admins cannot deactivate or demote themselves (`422`). |

User resource: `id, name, email, is_active, role, warehouse_ids, created_at`.

---

## Catalogue

Warehouses, products and customers support `GET` (list), `POST`, `GET /{id}` and
`PATCH /{id}`. They cannot be deleted because stock and orders reference them;
deactivate with `is_active=false` (warehouses, products).

| Resource | Write permission | Create fields | List filters | Sort |
|---|---|---|---|---|
| `/warehouses` | admin | `code` (A-Z0-9-, uppercased), `name`, `country_code` (ISO-2), `address_line?`, `city?`, `is_active?` | `is_active`, `country_code` | `code`, `name`, `created_at` |
| `/products` | admin | `sku` (uppercased, unique), `name`, `unit_price_cents`, `description?`, `is_active?` | `search` (SKU prefix or name), `is_active`, `min_price_cents`, `max_price_cents` | `sku`, `name`, `unit_price_cents`, `created_at` |
| `/customers` | admin, sales | `name`, `email` (unique), `company_name?`, `phone?` | `search` (name, email, company) | `name`, `email`, `created_at` |

Warehouse lists only include warehouses the user may access.

---

## Stock

### `GET /warehouses/{id}/stock`
Permission `inventory.view` + warehouse access.
Filters: `search`, `below_reorder_point=1`, `in_stock=0|1`. Sort: `sku`, `on_hand`, `available`, `updated_at`.

```json
{
  "warehouse_id": 1, "product_id": 40,
  "on_hand": 393, "reserved": 2, "available": 391,
  "reorder_point": 30, "below_reorder_point": false,
  "product": { "id": 40, "sku": "YO-1601-BY", "name": "Pallet wrap 23 micron", "unit_price_cents": 4115, "is_active": true },
  "updated_at": "..."
}
```

### `GET /products/{id}/stock`
The product's stock in every warehouse the user may access (not paginated).

### `PATCH /warehouses/{id}/stock/{product}`
Permission `inventory.adjust` + warehouse access. Body `{"reorder_point": 25}`. Always `200`.

### `POST /warehouses/{id}/stock-receipts`
Permission `inventory.adjust` + warehouse access.

```json
{ "product_id": 12, "quantity": 250, "reason": "PO-4471" }
```

`201` with the created stock movement.

### `POST /warehouses/{id}/stock-adjustments`

```json
{ "product_id": 12, "quantity_delta": -3, "reason": "Damaged in handling" }
```

`reason` is required. `409` if on-hand stock would drop below reserved stock:

```json
{ "message": "Insufficient stock to fulfil the request.",
  "shortages": [ { "product_id": 12, "requested": 3, "available": 1 } ] }
```

### `GET /warehouses/{id}/stock-movements`
The ledger, newest first, cursor-paginated. Filters: `product_id`, `type`
(`receipt`, `adjustment`, `reservation`, `release`, `shipment`), `from`, `to`.

```json
{
  "id": 981, "type": "reservation", "warehouse_id": 1, "product_id": 12,
  "on_hand_delta": 0, "reserved_delta": 5, "on_hand_after": 120, "reserved_after": 17,
  "reason": null, "reference": { "type": "order", "id": 61 },
  "product": { "id": 12, "sku": "CT-0300-BK", "name": "Cable tie 300mm" },
  "user": { "id": 5, "name": "Sam Patel" },
  "created_at": "..."
}
```

---

## Orders

### `POST /orders`
Permission `orders.create` + access to the warehouse. Supports `Idempotency-Key`.

```http
POST /api/v1/orders
Idempotency-Key: checkout-2f9c1d7a
```

```json
{
  "customer_id": 1,
  "warehouse_id": 1,
  "items": [
    { "product_id": 12, "quantity": 5 },
    { "product_id": 40, "quantity": 2 }
  ],
  "ship_to": { "name": "Receiving Dock 4", "line1": "1 High St", "city": "London", "postal_code": "E1 6AN", "country": "GB" },
  "notes": "Deliver before noon"
}
```

- Products and warehouse must be active; each product may appear once; 1–100 lines.
- Prices come from the catalogue; any price sent by the client is ignored.
- `201` with the order (status `confirmed`, stock reserved).
- `409` with `shortages` if any line cannot be reserved — nothing is saved.

**Idempotency-Key** (8–100 chars of `A-Z a-z 0-9 _ -`):

| Situation | Response |
|---|---|
| First request succeeds | Normal response; stored for 24 hours |
| Same key, same body | Stored response replayed with header `Idempotent-Replayed: true` |
| Same key, different body | `422` |
| Same key while the first request is still running | `409` |
| First request failed (4xx/5xx) | Not stored; the key can be retried |
| Malformed key | `400` |

### `GET /orders`
Filters: `status`, `warehouse_id`, `customer_id`, `number`, `from`, `to`.
Sort: `created_at` (default `-created_at`), `subtotal_cents`, `shipped_at`.
Only orders from accessible warehouses are returned. Each item includes `items_count`.

### `GET /orders/{id}`

```json
{
  "data": {
    "id": 61, "number": "SO-0000061", "status": "confirmed", "subtotal_cents": 82316,
    "customer": { "id": 1, "name": "...", "email": "...", "company_name": "..." },
    "warehouse": { "id": 1, "code": "LON-01", "name": "London Distribution Centre" },
    "items": [ { "product_id": 12, "sku": "...", "name": "...", "quantity": 2, "unit_price_cents": 41158, "line_total_cents": 82316 } ],
    "ship_to": { "name": "...", "line1": "...", "city": "...", "postal_code": "...", "country": "GB" },
    "notes": null, "tracking_number": null, "shipped_at": null,
    "cancelled_at": null, "cancellation_reason": null,
    "created_by": 5, "created_at": "..."
  }
}
```

### `POST /orders/{id}/ship`
Permission `orders.fulfil` + warehouse access. Body `{"tracking_number": "1Z999AA10123456784"}`.
Moves reserved stock out of on-hand stock, sets `shipped_at`, and queues a shipment
email to the customer.

### `POST /orders/{id}/cancel`
Permission `orders.cancel` + warehouse access. Body `{"reason": "Customer request"}`.
Releases the reservation.

Both return `409` when the order is not `confirmed`:

```json
{ "message": "An order that is shipped cannot be moved to cancelled.", "current_status": "shipped" }
```

---

## Reports (permission `reports.view`)

### `GET /reports/inventory-valuation`

```json
{
  "data": {
    "generated_at": "2026-09-26T05:35:36+00:00",
    "warehouses": [
      { "warehouse_id": 1, "code": "LON-01", "name": "London Distribution Centre",
        "sku_count": 30, "units_on_hand": 7025, "units_reserved": 113,
        "value_cents": 204847807, "items_below_reorder_point": 0 }
    ],
    "totals": { "units_on_hand": 7025, "units_reserved": 113, "value_cents": 204847807 }
  }
}
```

Limited to accessible warehouses. May be up to 5 minutes old (`generated_at`).

### `POST /reports/sales-exports`
Rate limit: 10 per hour per user.

```json
{ "from": "2026-09-01", "to": "2026-09-26", "warehouse_id": 1 }
```

`warehouse_id` is optional (defaults to all accessible warehouses). The range may
not exceed 366 days and may not end in the future. Returns `202 Accepted`:

```json
{ "data": { "id": 2, "type": "sales", "status": "pending",
            "parameters": { "from": "2026-09-01", "to": "2026-09-26" },
            "row_count": null, "error": null, "download_url": null,
            "completed_at": null, "created_at": "..." } }
```

Poll `GET /reports/sales-exports/{id}` until `status` is `completed` (or
`failed`); the requester also receives an in-app notification.

### `GET /reports/sales-exports` — the current user's exports
### `GET /reports/sales-exports/{id}/download`
CSV attachment (only the user who requested it). `409` if not completed yet.

Columns: `order_number, shipped_at, warehouse_code, customer_name, customer_email,
sku, product_name, quantity, unit_price, line_total`.

---

## Notifications

### `GET /notifications?unread=1`

```json
{
  "data": [
    { "id": "9d1c…", "data": { "type": "low_stock", "warehouse_id": 1, "warehouse_code": "LON-01",
      "product_id": 12, "sku": "CT-0300-BK", "available": 9, "reorder_point": 10 },
      "read_at": null, "created_at": "..." }
  ],
  "current_page": 1, "per_page": 25, "total": 1, ...
}
```

Types: `low_stock`, `report_ready`.

### `POST /notifications/{id}/read` → `204`
Only the owner's notifications can be marked; others return `404`.
