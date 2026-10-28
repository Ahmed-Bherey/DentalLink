# DentHub Backend → Angular Handoff

> Everything here was read out of the codebase and re-verified endpoint by endpoint (see §24). Nothing planned or aspirational is documented as implemented. Where the frontend will plausibly need something that does not exist, it is listed in **§21 API Contract Gaps** — not invented into the reference.
>
> **Updated for Stage 7.5.** Six gaps from the original audit are closed; the affected sections are §7, §9, §10, §12 and §14. Resolved gaps are recorded — not deleted — in §21.

---

## 1. Backend Overview

Laravel 9 REST API for a **B2B dental supply marketplace**. Two trading parties:

- **Doctor** — the buyer. Browses supplier catalogues, orders, requests returns, approves payment changes.
- **Supplier** — the seller. Lists products, fulfils orders, records payments, approves returns.
- **Super Admin** — manages the canonical product catalogue; otherwise read-only oversight.

There is no server-rendered UI. Every route is JSON under `/api`.

Domain shape:

```
ProductMaster (canonical catalogue, Super Admin owned, NO price)
   └── Product  = one user's HOLDING of a canonical product
         supplier row → offer (base_price = cost, price = selling)
         doctor row   → inventory
   └── OrderItem = a purchase, with the price FROZEN at the time of sale
         └── OrderItemRefund = an approved return, at that same frozen price
   └── OrderExpense = the running balance per (doctor, supplier) pair
```

---

## 2. Base API Information

| | |
|---|---|
| Base path | `/api` |
| Format | JSON only. Send `Accept: application/json` on **every** request |
| Auth | Laravel Sanctum bearer tokens |
| Field naming | `snake_case` throughout |
| Rate limit | 60 req/min per user (or per IP when unauthenticated) |
| Total endpoints | **92** |

⚠️ **`Accept: application/json` is not optional.** Several handlers check `expectsJson()` and return `null` (falling through to an HTML error page) when it is absent.

⚠️ **Updates use `POST`, not `PUT`/`PATCH`.** Every update route in this API is `POST .../update/{id}`. This is consistent, and it means file uploads work without `_method` spoofing.

---

## 3. Authentication

### Implemented

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/register` | — | Create an account, returns the user |
| POST | `/api/login` | — | Returns user + token |
| POST | `/api/logout` | ✅ | Revokes the **current** token |
| POST | `/api/logout-all` | ✅ | Revokes **all** the user's tokens |
| GET | `/api/profile/show` | ✅ | Current user |
| POST | `/api/profile/update` | ✅ | Update own profile |
| POST | `/api/password/change` | ✅ | Change password (revokes all tokens) |
| POST | `/api/password/forgot` | — | Send reset link (throttled) |
| POST | `/api/password/reset` | — | Reset with token (revokes all tokens) |
| GET | `/api/password/policy` | — | Machine-readable password rules |

### Sending an authenticated request

```
Authorization: Bearer {token}
Accept: application/json
```

### Login

`POST /api/login` — body `{ "login": "...", "password": "..." }`

`login` accepts **email or phone**. Response `200`:

```json
{
  "status": true,
  "data": {
    "id": 1, "name": "...", "email": "...", "phone": "...", "phone2": null,
    "address": "...", "city_id": 3, "city_name": "Cairo",
    "role": "doctor",
    "department_id": 1,
    "token": "1|xxxxxxxx..."
  }
}
```

- **`role`** is the authoritative identity: `"doctor" | "supplier" | "super_admin" | null`.
- **`department_id`** is legacy, retained only for backward compatibility. **Do not branch on it.**
- `401 {"status": false, "message": "Invalid credentials"}` on bad credentials.

⚠️ The login response is a **hand-built array**, not `UserResource`. Its shape differs from `/api/profile/show` — see §22.

### Token lifetime

Tokens expire after **7 days** (`SANCTUM_TOKEN_EXPIRATION`, minutes, default 10080).

**There is no refresh endpoint.** When a token expires the API returns `401`; the user must log in again. Expired rows are pruned daily by a scheduled command.

### Registration

`POST /api/register`. Send **`role_id`** (from `GET /api/role/index`); `department_id` is the legacy alternative and only one of the two is required.

Only roles flagged self-assignable are accepted — **`super_admin` can never be claimed at registration**, through either field.

Registration returns the created user (`201`) but **not a token** — call `/api/login` afterwards.

### Password policy

`GET /api/password/policy` returns the live rules so the frontend can validate identically without hardcoding them.

`/api/password/forgot` and `/api/password/reset` are throttled to **5/min per IP and 3/min per email address**; expect `429`.

---

## 4. Authorization / RBAC

Three roles: `super_admin`, `doctor`, `supplier`. A user may also have **no role**, which means **no access** — every gate fails closed.

Authorization is enforced entirely server-side by Laravel policies. The frontend should use the role to decide **what to show**, never as the security boundary.

### Feature matrix

| Feature | Super Admin | Supplier | Doctor |
|---|---|---|---|
| **Product Master** browse / search | ✅ | ✅ | ✅ |
| **Product Master** create / update / archive | ✅ | ❌ 403 | ❌ 403 |
| **Product Master** delete | ❌ | ❌ | ❌ |
| Own product offer / inventory — create | ❌ | ✅ | ✅ |
| Own product — view / update / delete | view only | owner | owner |
| Another's product — any action | view only | ❌ 404 | ❌ 404 |
| Browse supplier catalogue (`/product/all`) | ✅ | ❌ 403 | ✅ |
| Cart | view only | ❌ 403 | ✅ own |
| Place an order | ❌ | ❌ 403 | ✅ |
| View an order | ✅ | party only | party only |
| Advance order status (`fulfil`) | ❌ | supplying only | ❌ 403 |
| Update / delete an order | ❌ | ❌ | ordering doctor |
| Request a return | ❌ | ❌ | ordering doctor |
| Decide a return | ❌ | supplying supplier | ❌ 403 |
| Record a payment | ❌ | ✅ | ❌ 403 |
| Amend / request deletion of a payment | ❌ | supplier party | ❌ |
| Decide a payment change | ❌ | ❌ | doctor party |
| Pending-payments inbox | ✅ | ❌ 403 | ✅ |
| Packages — view | ✅ | ✅ | ✅ |
| Packages — create / update / delete / toggle | ❌ | owner | ❌ |
| Packages — buy | ❌ | ❌ | ✅ |
| Receipts | view only | ✅ own | ✅ own |
| Categories / Cities — read | ✅ | ✅ | ✅ |
| Categories / Cities — mutate | ❌ | creator | creator |
| Doctor directory (`/report/doctor/all`) | ✅ | ✅ | ❌ 403 |
| Supplier directory (`/report/supplier/all`) | ✅ | ❌ 403 | ✅ |
| Dashboard `/api/statistic` | ✅ platform-wide | ✅ own | ✅ own |

### 401 vs 403 vs 404 — this distinction matters

| Status | Meaning | Frontend action |
|---|---|---|
| **401** | Not authenticated, or token expired/revoked | Clear stored token, redirect to login |
| **403** | Authenticated, genuinely not permitted | Show "not allowed". Do **not** log out |
| **404** | Either the record does not exist **or** it exists and is not yours | Treat as not found. **Do not** tell the user it exists |

⚠️ **The 404-for-forbidden behaviour is deliberate.** For resources whose ids must not be enumerable — **products, carts, receipts, favourites, categories, cities, counterparty user lookups** — a non-owner receives `404`, not `403`, so an attacker cannot probe which ids exist. Do not "helpfully" surface these as "you don't have permission": that would leak exactly what the design hides.

Two-party resources (**orders, order items, payments, packages**) return `403`, because both parties already know the record exists.

---

## 5. API Conventions

### Success envelopes — there are four, and they differ

| Helper | Shape | Used by |
|---|---|---|
| `successResponse` | `{ "status": true, "data": … }` | most GETs |
| `createSuccessResponse` | `{ "status": true, "msg": "…", "data": … }` | most creates/updates |
| `successResponseWithoutData` | `{ "status": true, "msg": "…" }` | some actions |
| `paginatedResponse` | `{ "status": true, "data": [...], "meta": {...}, "links": {...} }` | list endpoints |

⚠️ Note `msg` (success) vs `message` (error) — different keys. See §22.

### Error envelope

```json
{ "status": false, "message": "…" }
```

Except **validation**, which does **not** use the envelope (§17).

### Dates

Response dates are `Y-m-d` strings (no time, no timezone). Schedule times are `h:i A` (e.g. `"09:00 AM"`). Request dates accept anything PHP `date` parses; send `Y-m-d`.

### Money

`DECIMAL(12,2)` in the database, emitted as JSON **numbers**. Max `9999999999.99`.

⚠️ Money is **not uniformly typed** across resources — some fields are exact, some are still integer-truncated. See §22 for the exact list.

---

## 6. Product Master

The canonical catalogue. A canonical product has **no price and no owner** — that is what makes the same product comparable across suppliers.

| Method | Endpoint | Who |
|---|---|---|
| GET | `/api/product-master/index` | any authenticated |
| GET | `/api/product-master/show/{id}` | any authenticated |
| POST | `/api/product-master/store` | **Super Admin only** |
| POST | `/api/product-master/update/{id}` | **Super Admin only** |
| POST | `/api/product-master/toggle-active/{id}` | **Super Admin only** |

**There is no delete endpoint, by design** — canonical products are referenced by order history. Use `toggle-active` to withdraw one.

### `GET /api/product-master/index`

Query: `search` (name substring), `per_page` (default **15**), `include_inactive` (Super Admin only; ignored for everyone else).

Inactive products are hidden by default, so this endpoint is directly usable as a picker data source.

Paginated. Each item:

```json
{
  "id": 12, "name": "Dental Composite A", "desc": "...", "img": "http://.../storage/...",
  "unit": "box", "is_active": true,
  "category_id": 3, "category_name": "Dental Materials",
  "offers_count": 4
}
```

`category_name` appears only when the relation is loaded (it is, on this endpoint). `offers_count` is the number of holdings — suppliers *and* doctors, not suppliers alone.

### Create / update

`POST /api/product-master/store` — `multipart/form-data` if sending `img`.

| Field | Rule |
|---|---|
| `name` | required, ≤255, **unique** |
| `category_id` | nullable, must exist |
| `desc` | nullable |
| `unit` | nullable, ≤50 |
| `img` | nullable image jpg/jpeg/png ≤2 MB |
| `is_active` | nullable boolean |

Duplicate name → `422` on `name`. Non-admin → `403`.

---

## 7. Supplier Products & Pricing

A `Product` row is **one user's holding of one canonical product**.

| Field | Meaning |
|---|---|
| `product_master_id` | which canonical product — **required** |
| `price` | **SELLING price** — what the buyer pays |
| `base_price` | **supplier's COST** — nullable |
| `quantity` | stock |

⚠️ **`price` means selling price.** It was not renamed to `selling_price` because it already means "what the buyer pays" throughout the API, the Excel import/export and order totals.

🔒 **`base_price` is only returned to the owner of the offer.** A doctor browsing the catalogue does not receive the key at all — do not build UI that expects it.

### Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/product/index` | own holdings, paginated |
| GET | `/api/product/show/{id}` | one holding (owner; 404 otherwise) |
| POST | `/api/product/store` | create a holding |
| POST | `/api/product/update/{id}` | update own holding |
| DELETE | `/api/product/delete/{id}` | delete own holding |
| POST | `/api/product/multi-delete` | delete several (`ids[]`) |
| GET | `/api/product/search` | search own holdings |
| GET | `/api/product/export` | Excel export |
| POST | `/api/product/import` | Excel import |

### `POST /api/product/store`

| Field | Rule |
|---|---|
| `product_master_id` | **required**, must exist **and be active** |
| `price` | required, numeric, 0 … 9999999999.99 |
| `base_price` | nullable, numeric, same bounds |
| `quantity` | required, integer, ≥1 |
| `name`, `category_id`, `desc`, `img` | **all optional** — inherited from the canonical product |

**The catalogue rule is enforced here, server-side.** A supplier cannot invent a product; omitting `product_master_id` is a `422`. The UI picker is a convenience, not the control.

**One holding per owner per canonical product.** A second attempt returns `422` with a message telling the user to edit the existing one.

### Product response (`InventoryResource`)

```json
{
  "id": 5, "product_id": 5, "name": "...", "supplier_name": "...",
  "category_id": 3, "category_name": "...", "city_id": 2, "city_name": "...",
  "img": "http://.../storage/products/x.png", "desc": "...",
  "product_master_id": 12,
  "price": 10.5,
  "base_price": 8.0,          // OWNER ONLY — key absent otherwise
  "quantity": 20, "rating": 5,
  "status": "INSTOCK",         // INSTOCK | LOWSTOCK (≤5) | OUTOFSTOCK (0)
  "favorite": false, "is_added": false
}
```

`rating` is hardcoded `5`. `favorite` / `is_added` only appear meaningfully when those relations are eager-loaded (the browse endpoint does).

### `POST /api/product/update/{id}` (Stage 7.5)

| Field | Rule |
|---|---|
| `price` | `sometimes`, numeric, 0 … 9999999999.99 |
| `base_price` | `sometimes`, **nullable**, numeric, same bounds |
| `name`, `category_id`, `desc`, `img`, `quantity` | `sometimes` |

✅ **RESOLVED IN STAGE 7.5:** `base_price` is now editable (it was write-once), and `price` validates identically on create and update — the old `regex:/^\d+(\.0+|\.5)?$/` accepted `10.25` on create and rejected it on update. Send `base_price: null` to clear a cost.

Updating either price **cannot alter an existing order** — order lines carry their own frozen `unit_price`.

⚠️ `product_master_id` is still not accepted on update: an offer's canonical identity is fixed at creation.

### Excel import

`POST /api/product/import`. The importer **resolves** canonical products by exact name; it never creates them. Unmatched rows are skipped and collected internally — but **the endpoint does not return them** (§21).

---

## 8. Doctor Products / Inventory

Doctors hold `products` rows too — that is their **stock**, not an offer. Same table, same endpoints, same shape.

Inventory rows are created automatically when an order is marked **delivered**: the doctor gets a holding of the **same canonical product** (not a copy), or an existing holding is incremented.

### Browsing what suppliers sell

| Method | Endpoint | Who |
|---|---|---|
| GET | `/api/product/all` | doctor (+ Super Admin). **Supplier → 403** |
| GET | `/api/clinic/product/search` | any authenticated |

`/api/product/all` supports `search`, `category_id`, `supplier_id`, `min_price`, `max_price`, `per_page` (default 10) and excludes doctor-owned rows.

---

## 9. Orders

| Method | Endpoint | Who | Purpose |
|---|---|---|---|
| POST | `/api/order/store` | doctor | Place an order |
| GET | `/api/order/index-type` | party | Active orders (excludes delivered) |
| GET | `/api/order/all-delivered` | party | Delivered orders |
| GET | `/api/order/search` | party | Search orders |
| GET | `/api/order/{id}` | party | **Single order detail** (Stage 7.5) |
| GET | `/api/order/refund` | party | Orders with a pending return |
| GET | `/api/order/delivered/export` | party | Excel export |
| POST | `/api/order/update-status/{order_id}` | supplying supplier | Advance status |
| POST | `/api/order/update/{id}` | ordering doctor | Update an order |
| DELETE | `/api/order/delete/{id}` | ordering doctor | Flag for deletion |

### Placing an order

```json
POST /api/order/store
{
  "products": [ { "id": 5, "quantity": 3 } ],
  "notes": "optional",
  "payment_method": "مدفوعات"
}
```

`products[].id` is a **`products.id`** (a supplier's offer), not a `product_master_id`. `payment_method` ∈ `كاش | الكترونى | مدفوعات`.

⚠️ **`status` is not accepted on create** — a new order is always `pending`.

**Side effect: placing an order clears the doctor's ENTIRE cart**, not only the ordered lines.

**No stock validation** — an order may exceed available quantity.

### Status

`pending → preparing → delivered`, plus `rejected` and `delete_pending`. **Transitions are unrestricted** — any value may follow any other.

Marking **delivered** is the financially significant step: it moves stock, creates doctor inventory, and accrues the charge onto the ledger.

### Order response (`OrderResource`)

```json
{
  "id": 1, "name": "...", "img": null, "notes": null,
  "status": "pending", "status_name": "قيد الانتظار",
  "total_price": 30,
  "checked": true, "desc": null,
  "is_package": false, "is_order": false,
  "created_at": "2026-08-26",
  "products": [ { ...line items... } ]
}
```

- `name` is the **counterparty's** name (doctor or supplier, depending on who asks).
- `status_name` is a pre-translated Arabic label.
- `total_price` = the package price if this is a package order, otherwise the **sum of the historical line snapshots**.
- `is_order` is `true` when the order status is `delete_pending` (the name is misleading).

✅ **RESOLVED IN STAGE 7.5.** `total_price` and the line-item values are now both historical and reconcile exactly. See §10.

### `GET /api/order/{id}` — single order detail (Stage 7.5)

The endpoint an order-details screen should use. Previously the only way to reach one order was to page a list and filter client-side.

| | |
|---|---|
| Auth | required |
| Authorization | `OrderPolicy::view` — the ordering doctor, or a supplier owning ≥1 line |
| Success | `200 { "status": true, "data": { …OrderResource… } }` |
| Not a party | `403` (two-party resource — see §4) |
| Missing / non-numeric id | `404` |

The `{id}` is constrained to digits, so it cannot shadow `index-type`, `all-delivered`, `search` or `refund`.

---

## 10. Order Items

Order lines are returned **nested inside the order** as `products[]`, by `OrderItemResource`.

> **STAGE 7.5:** this response changed. Previously the line `price`/`total_price` were built from the supplier's **live** price and cast to `(int)`. They now carry the **historical** values and full decimals. See "Compatibility impact" below.

### The rule

Historical transaction data and current catalogue data are **never returned under the same key**:

| Group | Fields | Changes over time? |
|---|---|---|
| **HISTORICAL** — frozen at purchase | `unit_price`, `line_total`, `quantity`, `product_master_id`, `supplier_id` | **Never** |
| **CURRENT** — live catalogue | `current_product_price`, `quantity_available` | Whenever the supplier edits their offer |

### Shape

```json
{
  "id": 7,

  "product_id": 5,
  "product_master_id": 12,
  "supplier_id": 9,
  "supplier_name": "Acme Dental",
  "name": "Dental Composite A",
  "desc": "...", "img": "http://.../storage/...",
  "category_id": 3, "category_name": "Dental Materials",

  "quantity": 5,
  "unit_price": 10.5,
  "line_total": 52.5,

  "price": 10.5,
  "total_price": 52.5,

  "returned_quantity": 2,
  "pending_return_quantity": 1,
  "returnable_quantity": 3,
  "status": "delete_pending",

  "current_product_price": 12.0,
  "quantity_available": 40,
  "quantityAvailable": 40
}
```

### Field notes

- **`unit_price` / `line_total`** — use these. Unambiguous, frozen, exact decimals.
- **`price` / `total_price`** — retained aliases of the two above, for existing consumers.
- **`current_product_price`** — what the supplier charges *today*. Deliberately not called `price`. May be `null` if the offer has no price.
- **`id`** is the **order-item** id — the one returns are addressed by. The product is `product_id`.
- **`returned_quantity`** — cumulative **approved** returns. Only grows.
- **`pending_return_quantity`** — awaiting the supplier's decision. Non-zero means a request is already open.
- **`returnable_quantity`** = `quantity − returned_quantity`. It does **not** subtract the pending amount, because a new request **replaces** the open one rather than adding to it (one pending figure, one status per line — a second request is an amendment). This figure is exactly what the server will accept.
- **`quantityAvailable`** is retained under its original camelCase name; `quantity_available` is the same value in the house convention.

### Compatibility impact

| Field | Before | After |
|---|---|---|
| `price` | current product price, `(int)` | **historical** unit price, decimal |
| `total_price` | current price × quantity | **historical** line total |
| order-level `total_price` | number, or **string** for package orders | always a number |

The values changed because the old ones were wrong: an order's lines reported today's price while the order's own `total_price` was already historical, so they did not reconcile. New fields are additive; nothing was removed.

## 11. Payments

A payment is money the **doctor** paid the **supplier**, recorded **by the supplier**.

| Method | Endpoint | Who |
|---|---|---|
| POST | `/api/payment/store` | supplier |
| GET | `/api/payment/index` | party |
| GET | `/api/payment/search` | party |
| GET | `/api/payment/pending` | **doctor** |
| POST | `/api/payment/update/{id}` | supplier party — propose new amount |
| GET | `/api/payment/delete/{id}` | supplier party — request reversal |
| POST | `/api/payment/confirm/{id}` | **doctor** party — decide |
| GET | `/api/payment/export` | party |

### Recording a payment

```json
POST /api/payment/store
{ "doctor_id": 4, "paid": 250.50, "date": "2026-08-26" }
```

`paid`: required, numeric, **min 0.01**, max 9999999999.99. `doctor_id` must be a user whose **role is doctor**.

**Overpayment is rejected.** A payment exceeding the outstanding balance returns `422` with a message naming both figures. Paying against a doctor with no balance also returns `422`.

The write is transactional and row-locked — two concurrent payments cannot both consume the same remaining balance.

### Lifecycle

```
confirmed ──update()──► pending        ──confirm(confirmed)──► confirmed @ new amount
          ──delete()──► delete_pending ──confirm(confirmed)──► voided
                        both            ──confirm(rejected)──► confirmed @ original
```

- `POST /api/payment/update/{id}` parks the proposal in `requested_amount`; **no money moves** until the doctor decides. Only a `confirmed` payment can be amended (else `422`).
- `POST /api/payment/confirm/{id}` body `{ "status": "confirmed" | "rejected" }`. Only valid on `pending` / `delete_pending` (else `422`).
- **Approving a deletion VOIDS the payment — the row is never deleted.** It stops counting toward the balance and disappears from listings, but the record survives.

### Payment response

```json
{
  "id": 3, "name": "...", "supplier_name": "...",
  "paid": 250.5,             // THIS payment's amount (not the running total)
  "requested_paid": null,    // proposed amount while pending
  "remaining": 749.5,        // pair-level outstanding balance
  "date": "2026-08-26"
}
```

⚠️ `paid` here is **this payment's amount**, despite the name. `remaining` is the **whole relationship's** balance, not this payment's.

Listings show only `confirmed` payments. `pending` and `delete_pending` appear only in `/api/payment/pending` (doctor).

---

## 12. Returns

A return is per **order line**, and has a two-step lifecycle.

| Method | Endpoint | Who | Step |
|---|---|---|---|
| POST | `/api/order/items/{orderItemId}/return` | ordering doctor | request |
| DELETE | `/api/order/delete-item/{orderItem_id}` | ordering doctor | request (same handler) |
| POST | `/api/order/{orderItem_id}/update-status` | supplying supplier | decide |
| GET | `/api/order/refund` | party | list orders with pending returns |

### Request

```json
POST /api/order/items/7/return
{ "quantity": 2 }
```

`quantity`: required, integer, ≥1, and **must not exceed the outstanding quantity** (`quantity − returned_quantity`). Over-requesting → `422` stating what is available.

**A request has NO financial effect.** It sets the line to `delete_pending` and records the requested amount in `pending_return_quantity`. Nothing moves until the supplier decides.

A second request on an open line **amends** it rather than adding to it — there is one pending figure and one status per line. That is why `returnable_quantity` is `quantity − returned_quantity` and does not subtract the pending amount (§10).

### Decide

```json
POST /api/order/7/update-status
{ "status": "confirmed" | "rejected" }
```

- **rejected** — request discarded, line returns to `confirmed`, nothing moves.
- **confirmed** — stock returns to the supplier, leaves the doctor's inventory, `returned_quantity` grows, and a refund is recorded.

⚠️ The route is `/api/order/{orderItem_id}/update-status` — the parameter is an **order item id**, despite the `/order/` prefix. It is easy to confuse with `/api/order/update-status/{order_id}`, which takes an **order** id.

**Cumulative cap:** across any number of returns, the total can never exceed the purchased quantity. `quantity` is immutable and never decremented.

---

## 13. Refunds

An approved return creates an `OrderItemRefund` — a first-class financial event.

🚨 **There is no refund endpoint and no refund resource. Refunds are NOT CURRENTLY EXPOSED BY THE API.** They exist in the database and drive the balances the API reports, but the frontend cannot list or inspect them. See §21.

### The rule the frontend must communicate correctly

A refund is settled at the **historical purchase price**, never the current one.

```
Purchase:            5 × 10 = 50
Supplier reprices:   10 → 12
Doctor returns:      2 units
Refund:              2 × 10 = 20      ✅
                     NOT 2 × 12 = 24  ❌
```

### Effect on the balance

An approved return means the doctor has the money back, so it reduces **both** sides:

```
total = charges − approved refunds
paid  = max(0, payments − approved refunds)
```

| Scenario | total | paid | remaining |
|---|---|---|---|
| 1000 billed, 1000 paid, 300 returned | 700 | 700 | 0 |
| 100 billed, 60 paid, 30 returned | 70 | 30 | 40 |
| 100 billed, 0 paid, 100 returned | 0 | 0 | 0 |

`remaining` is **unchanged** by a refund — that falls out of `(total − R) − (paid − R)`. Do not present a refund as reducing what the doctor owes; it reduces the debt *and* the credit equally.

---

## 14. Financial Values

The backend is authoritative. **Do not compute financial values in Angular.**

| Value | Exposed? | Where |
|---|---|---|
| Original order total | ✅ | `total_price` on `OrderResource` |
| Net order total after returns | ❌ | **NOT CURRENTLY EXPOSED** (`net_order_price` exists on the model, unused) |
| Pair total (charges − refunds) | ✅ | `total` in the directory endpoints |
| Effective paid (payments − refunds) | ✅ | `paid` in the directory endpoints |
| Remaining | ✅ | `remaining` in directories and `PaymentResource` |
| Total payments (gross) | ❌ | **NOT CURRENTLY EXPOSED** |
| Total refunds | ❌ | **NOT CURRENTLY EXPOSED** |
| Individual payment amount | ✅ | `paid` on `PaymentResource` |
| Historical line unit price | ✅ | `unit_price` on `OrderItemResource` (Stage 7.5) |
| Historical line total | ✅ | `line_total` on `OrderItemResource` (Stage 7.5) |
| Current product price (per line) | ✅ | `current_product_price` — **not** a transaction value |

The authoritative balance for a relationship comes from `GET /api/report/supplier/all` (doctor's view) or `GET /api/report/doctor/all` (supplier's view):

```json
{ "id": 4, "name": "...", "paid": 100.75, "total": 1000.25, "remaining": 899.5, ... }
```

All three are exact decimals. Every endpoint that reports `remaining` uses the same definition.

---

## 15. Pagination

Applied by `paginatedResponse`:

```json
{
  "status": true,
  "data": [ ... ],
  "meta":  { "current_page": 1, "last_page": 5, "per_page": 10, "total": 47 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

| | |
|---|---|
| Page parameter | `page` (Laravel default) |
| Size parameter | `per_page` |
| Default size | **10** everywhere **except `/api/product-master/index`, which is 15** |
| Maximum size | **None enforced** — `per_page=100000` is accepted (§21) |

⚠️ `meta` has no `from`/`to`. Not every list endpoint paginates — `/api/category/index`, `/api/city/index`, `/api/role/index` and `/api/department/index` return bare arrays.

---

## 16. Search / Filters / Sorting

| Endpoint | Parameters |
|---|---|
| `/api/product-master/index` | `search`, `per_page`, `include_inactive` (admin) |
| `/api/product/all` | `search`, `category_id`, `supplier_id`, `min_price`, `max_price`, `per_page` |
| `/api/product/index`, `/api/product/search` | `search`, `per_page` |
| `/api/clinic/product/search` | `search`, `per_page` |
| `/api/order/search` | `search` (doctor name or Arabic status label), `per_page` |
| `/api/order/all-delivered` | `search` (doctor name), `supplier_id`, `from_date`, `to_date`, `per_page` |
| `/api/payment/index`, `/api/payment/search` | `search` (party name), `doctor_id`, `supplier_id`, `from_date`, `to_date`, `per_page` |
| `/api/receipt/index` | `per_page` |

**There is no sort parameter anywhere.** Ordering is fixed per endpoint (usually `created_at desc`; the catalogue is `name asc`).

`/api/order/search` matches Arabic status labels (`"قيد الانتظار"` → `pending`), not English ones.

---

## 17. Error Handling

### 422 — validation (note: this does **not** use the standard envelope)

```json
{
  "message": "Validation errors",
  "errors": { "price": ["سعر المنتج مطلوب"] }
}
```

### 422 — business rule rejection (**does** use the envelope, and has no `errors` key)

```json
{ "status": false, "message": "المبلغ المطلوب (5000.00) يتجاوز المتبقي (100.00)." }
```

⚠️ **Two different shapes share status 422.** Branch on the presence of `errors`.

### Other statuses

| Status | Body | When |
|---|---|---|
| **401** | `{"message": "يجب تسجيل الدخول أولاً."}` — no `status` key | missing/expired/invalid token |
| **403** | `{"status": false, "message": "…"}` | authenticated but not permitted |
| **404** | `{"status": false, "message": "…"}` | missing **or** forbidden-and-hidden |
| **429** | Laravel default + `Retry-After` | rate limit |
| **500** | Laravel default | unhandled |

**409 is never returned.** Conflicts (duplicate offer, duplicate name) surface as `422`.

Most messages are **Arabic and user-displayable**. Some are English (`"Invalid credentials"`) — see §22.

---

## 18. API Endpoint Reference

All routes are `/api/...`. **Auth** = Sanctum bearer required.

### Auth & profile
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| POST | `/register` | — | — |
| POST | `/login` | — | — |
| POST | `/logout` | ✅ | any |
| POST | `/logout-all` | ✅ | any |
| GET | `/profile/show` | ✅ | any |
| POST | `/profile/update` | ✅ | self |
| POST | `/profile/update-fcmToken` | ✅ | any |
| GET | `/profile/delete-fcmToken` | ✅ | any |
| POST | `/password/change` | ✅ | any |
| POST | `/password/forgot` | — | throttled |
| POST | `/password/reset` | — | throttled |
| GET | `/password/policy` | — | — |
| GET | `/role/index` | — | — |
| GET | `/department/index` | — | legacy |

### Product Master
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| GET | `/product-master/index` | ✅ | any |
| GET | `/product-master/show/{id}` | ✅ | any |
| POST | `/product-master/store` | ✅ | **super_admin** |
| POST | `/product-master/update/{product_master}` | ✅ | **super_admin** |
| POST | `/product-master/toggle-active/{product_master}` | ✅ | **super_admin** |

### Products / inventory
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| GET | `/product/index` | ✅ | any (own) |
| GET | `/product/show/{id}` | ✅ | owner |
| POST | `/product/store` | ✅ | any |
| POST | `/product/update/{id}` | ✅ | owner |
| DELETE | `/product/delete/{id}` | ✅ | owner |
| POST | `/product/multi-delete` | ✅ | owner (all) |
| GET | `/product/search` | ✅ | any (own) |
| GET | `/product/all` | ✅ | doctor |
| GET | `/product/export` | ✅ | any |
| POST | `/product/import` | ✅ | any |
| GET | `/clinic/product/search` | ✅ | any |
| DELETE | `/product/delete/fcmtoken` | ✅ | any |

### Cart & favourites
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| GET | `/cart/index` | ✅ | doctor |
| POST | `/cart/store` | ✅ | doctor |
| POST | `/cart/update/{id}` | ✅ | owner |
| DELETE | `/cart/delete/{id}` | ✅ | owner |
| GET | `/favorite-product/index` | ✅ | doctor |
| GET | `/favorite-product/add/{product_id}` | ✅ | doctor |
| DELETE | `/favorite-product/remove/{product_id}` | ✅ | owner |

### Orders & returns
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| POST | `/order/store` | ✅ | doctor |
| GET | `/order/index-type` | ✅ | party |
| GET | `/order/all-delivered` | ✅ | party |
| GET | `/order/search` | ✅ | party |
| GET | `/order/{id}` | ✅ | party (403 otherwise) |
| GET | `/order/refund` | ✅ | party |
| GET | `/order/delivered/export` | ✅ | party |
| POST | `/order/update-status/{order_id}` | ✅ | supplying supplier |
| POST | `/order/update/{id}` | ✅ | ordering doctor |
| DELETE | `/order/delete/{id}` | ✅ | ordering doctor |
| POST | `/order/update-item/{orderItem_id}` | ✅ | ordering doctor |
| POST | `/order/items/{orderItemId}/return` | ✅ | ordering doctor |
| DELETE | `/order/delete-item/{orderItem_id}` | ✅ | ordering doctor |
| POST | `/order/{orderItem_id}/update-status` | ✅ | supplying supplier |

### Payments
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| POST | `/payment/store` | ✅ | supplier |
| GET | `/payment/index` | ✅ | party |
| GET | `/payment/search` | ✅ | party |
| GET | `/payment/pending` | ✅ | doctor |
| POST | `/payment/update/{payment_id}` | ✅ | supplier party |
| GET | `/payment/delete/{payment_id}` | ✅ | supplier party |
| POST | `/payment/confirm/{payment_id}` | ✅ | doctor party |
| GET | `/payment/export` | ✅ | party |
| GET | `/payment/getFcm-token` | ✅ | any |

### Packages
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| GET | `/package/index` | ✅ | any |
| GET | `/package/shopping-all` | ✅ | any |
| GET | `/package/show/{package_id}` | ✅ | any |
| POST | `/package/store` | ✅ | supplier |
| POST | `/package/update/{package}` | ✅ | owner |
| DELETE | `/package/delete/{package}` | ✅ | owner |
| GET | `/package/toggle-status/{package}` | ✅ | owner |
| POST | `/package/buy/{packageId}` | ✅ | doctor |
| GET | `/package/remain-products/{packageId}` | ✅ | owner |

### Receipts, reference data, reports
| Method | Endpoint | Auth | Role |
|---|---|---|---|
| GET | `/receipt/index` · `show/{id}` | ✅ | owner |
| POST | `/receipt/store` · `update/{id}` · `deleteByDate` | ✅ | owner |
| DELETE | `/receipt/delete/{id}` | ✅ | owner |
| GET | `/category/index` · `show/{id}` | ✅ | any |
| POST | `/category/store` · `update/{id}` | ✅ | any / creator |
| DELETE | `/category/delete/{id}` | ✅ | creator |
| GET | `/city/index` | — | public |
| GET | `/city/show/{id}` | ✅ | any |
| POST | `/city/store` · `update/{id}` | ✅ | any / creator |
| DELETE | `/city/delete/{id}` | ✅ | creator |
| GET | `/report/doctor/all` | ✅ | supplier |
| GET | `/report/doctor/{doctor_id}/details` | ✅ | supplier |
| GET | `/report/supplier/all` | ✅ | doctor |
| GET | `/report/supplier/{doctor_id}/details` | ✅ | doctor |
| GET | `/statistic` | ✅ | any with a role |
| GET | `/notification/user` | ✅ | any |

---

## 19. Frontend Integration Flows

### Supplier adds a product

1. `GET /api/product-master/index?search=composite` — picker source (already active-only)
2. User selects a canonical product
3. `POST /api/product/store` with `product_master_id`, `price` (selling), `base_price` (cost), `quantity`
4. `422` on `product_master_id` → nothing selected; `422` with the "already added" message → the supplier already lists it

### Super Admin adds a canonical product

1. `POST /api/product-master/store` with `name` (unique), optional `category_id`, `desc`, `unit`, `img`
2. `422` on `name` → duplicate; `403` → not Super Admin

### Doctor buys

1. `GET /api/product/all?search=&category_id=&supplier_id=` — browse
2. `POST /api/cart/store { product_id, quantity }`
3. `GET /api/cart/index` — review
4. `POST /api/order/store { products: [{id, quantity}] }` — **this clears the whole cart**
5. `GET /api/order/index-type` — track

### Supplier fulfils

1. `GET /api/order/index-type` — incoming
2. `POST /api/order/update-status/{order_id} { "status": "delivered" }` — moves stock and bills the doctor

### Doctor requests a return

1. `GET /api/order/all-delivered` — find the order; take the **line's `id`** from `products[]`
2. `POST /api/order/items/{orderItemId}/return { "quantity": 2 }`
3. `422` → exceeds what is available; the message states the maximum

### Supplier decides a return

1. `GET /api/order/refund` — pending returns
2. `POST /api/order/{orderItem_id}/update-status { "status": "confirmed" }`
3. Refresh the balance from `/api/report/doctor/all`

### Payment cycle

1. Supplier: `POST /api/payment/store { doctor_id, paid, date }` — `422` if it exceeds the balance
2. Supplier amends: `POST /api/payment/update/{id}` → doctor sees it in `GET /api/payment/pending`
3. Doctor: `POST /api/payment/confirm/{id} { "status": "confirmed" }`

---

## 20. Angular Integration Recommendations

- **`AuthService`** — login/logout, token in `localStorage`, expose `role$`. No refresh exists; treat `401` as terminal.
- **`AuthInterceptor`** — attach `Authorization: Bearer` and `Accept: application/json` to every request.
- **`ErrorInterceptor`** — central mapping:
  - `401` → clear token, redirect to login
  - `403` → "not allowed" toast, stay put
  - `404` → not-found view (**never** "you lack permission" — that defeats the 404-hiding design)
  - `422` → if `errors` present, bind to the form; otherwise show `message` as a business error
  - `429` → back off using `Retry-After`
- **`RoleGuard`** — route-level UX gating only. The backend remains authoritative; a guard is never a security control.
- **Feature services** — `ProductMasterService`, `ProductService`, `OrderService`, `PaymentService`, `ReturnService`, `ReportService`.
- **Models** — TypeScript interfaces per resource. Mark `base_price` optional (`base_price?: number`) since the key is absent for non-owners.
- **A `PaginatedResponse<T>` generic** matching `{status, data, meta, links}` — but note some endpoints return bare arrays (§15).
- **Money** — arrives as JSON numbers. Format for display only. Never recompute totals client-side.

---

## 21. API Contract Gaps

### RESOLVED IN STAGE 7.5

Kept on the record rather than deleted, so the history of each is visible.

| # | Gap | Resolution |
|---|---|---|
| **1** | *Order lines exposed the CURRENT product price, not the snapshot.* Lines did not sum to the order total after a reprice. | **RESOLVED.** New `OrderItemResource` emits `unit_price`, `line_total`, `product_master_id`, `supplier_id`. `price`/`total_price` retained as historical aliases; the live price moved to `current_product_price`. §10 |
| **2** | *Line-item money was integer-truncated* — 10.50 rendered as 10. | **RESOLVED.** Line money is exact decimal. Order-level `total_price` also always serialises as a number (package orders previously emitted a string). §10 |
| **4** | *`pending_return_quantity` not exposed* — the UI could not show "awaiting approval". | **RESOLVED.** Exposed, alongside a computed `returnable_quantity` matching what the server accepts. §10, §12 |
| **5** | *No single-order detail endpoint* — deep links had to page a list. | **RESOLVED.** `GET /api/order/{id}`, authorized by `OrderPolicy::view`, digit-constrained so it shadows no sibling route. §9 |
| **6** | *Supplier could not update `base_price`* — write-once at creation. | **RESOLVED.** `base_price` accepted on `POST /api/product/update/{id}`, owner-only, nullable to clear. §7 |
| **7** | *`price` validation differed between create and update* — `10.25` accepted on create, rejected on update. | **RESOLVED.** Both now `numeric, min:0, max:9999999999.99`, matching the `DECIMAL(12,2)` column. §7 |

One further defect was found and fixed while closing #4: the order-level `desc` message describing a pending return read `returned_quantity` (cumulative **approved**, therefore `0` on a fresh request) and reported "return of (0)". It now reads `pending_return_quantity`.

### STILL OPEN

**None of the following were implemented** — they are for a future backend decision.

#### HIGH

**3. Refunds are not exposed at all.** No endpoint, no resource, no field. A doctor cannot see what was refunded, when, at what price, or by whom — even though the data exists with `approved_by`/`approved_at`. *Angular need:* return history, refund audit.

**8. Excel import gives no feedback on unmatched rows.** `ProductsImport::unmatchedProducts()` collects skipped product names, but the controller does not return them. A supplier sees a success response and silently missing products.

#### MEDIUM

**9. No `per_page` upper bound.** Any list endpoint accepts an unbounded page size.

**10. No sorting anywhere.** No endpoint accepts a sort parameter.

**11. Net order total after returns is not exposed.** `Order::getNetOrderPriceAttribute()` exists on the model but no resource emits it.

**12. Gross payments and total refunds are not exposed.** Only the net figures are, so a payment-history screen cannot reconcile.

**13. No endpoint lists a supplier's offers of one canonical product.** A doctor cannot compare prices for the same product across suppliers — arguably the main benefit of the catalogue.

**14. Doctor inventory is not separable from supplier offers.** Both are `products` rows on the same endpoints; `/api/product/index` returns whatever the caller owns, with no `type` discriminator.

#### LOW

**15. `/api/order/update/{id}` is effectively unreachable** — `OrderPolicy::update` allows only the ordering doctor, and the endpoint returns `403` even for them.

**16. `rating` is hardcoded to `5`** in product resources.

**17. No `from`/`to` in pagination `meta`.**

**18. `product_master_id` cannot be changed on an existing offer.** Deliberate — an offer's canonical identity is fixed at creation — but noted in case re-pointing is ever wanted.

---

## 22. Contract Risks

Things most likely to cause Angular bugs.

| # | Risk | Why it bites |
|---|---|---|
| 1 | **Current vs historical price** | Largely resolved in Stage 7.5: on an order line `price`/`unit_price`/`line_total` are all **historical**, and the live figure is `current_product_price`. The residual trap is that `price` on a **product** resource is the live selling price, while `price` on an **order line** is the historical one — same key, different timeframes, different resources. |
| 2 | **`msg` vs `message`** | Success uses `msg`, errors use `message`. A generic toast helper reading one key will silently show nothing for the other. |
| 3 | **Two different 422 shapes** | Validation has `errors` and no `status`; business rejections have `status:false` and no `errors`. Branch on `errors`. |
| 4 | **401 has no `status` key** | `{"message": "..."}` only — unlike every other error. |
| 5 | **404 means "forbidden" too** | Never render it as a permission error. |
| 6 | **`paid` is overloaded** | `PaymentResource.paid` = this payment's amount; `DoctorResource.paid` = the relationship's effective paid. |
| 7 | **`remaining` is pair-level** | On `PaymentResource` it is the whole relationship's balance, not the payment's. |
| 8 | **Mixed money types** | Exact: payments, receipts, product `price`/`base_price`, directory figures, **and all order money** (Stage 7.5). **Still integer-truncated:** cart `price` and `total_price`, favourite `price`, package `total_price`, package line `price`. |
| 9 | **Two `update-status` routes** | `/order/update-status/{order_id}` (order) vs `/order/{orderItem_id}/update-status` (order **item**). Transposing them silently targets the wrong entity. |
| 10 | **`products[].id` is the ORDER ITEM id** | Not the product id — that is `product_id`. Returns need the former. |
| 11 | **`is_order` means `delete_pending`** | Misleading name. |
| 12 | **Order `products[]` mixes English and camelCase** | `quantityAvailable` among otherwise snake_case keys. |
| 13 | **Enum values are Arabic** | `payment_method` ∈ `كاش | الكترونى | مدفوعات`. Status codes are English but `status_name` is Arabic. |
| 14 | **`department_id` still present** | Legacy. Branching on it instead of `role` will break. |
| 15 | **`base_price` key is absent, not null** | For non-owners the key is missing entirely — use optional typing, not `=== null`. |
| 16 | **Login vs profile shapes differ** | Login returns a hand-built array with `city_name` and `token`; `/profile/show` returns `UserResource` with `role_details`, `schedule` and no token. |
| 17 | **Mixed message languages** | Mostly Arabic, but `"Invalid credentials"` and `"Validation errors"` are English. |
| 18 | **Not everything paginates** | Categories, cities, roles, departments return bare arrays. |
| 19 | **IDs are integers**, not UUIDs, and are sequential — hence the 404-hiding design. |
| 20 | **`quantityAvailable` is live stock**, unrelated to the order. |

---

## 23. Known Limitations

- **No stock validation on ordering** — an order may exceed available quantity.
- **Order status transitions are unrestricted** — any status may follow any other.
- **Placing an order clears the doctor's entire cart.**
- **A cart may contain the same product twice** — no merge.
- **No refresh token.** Sessions end hard after 7 days.
- **No soft deletes anywhere.** Withdrawing a canonical product is `is_active`; supplier offers have no equivalent.
- **Credit is not modelled.** If refunds exceed payments, `paid` floors at zero and the surplus is invisible.
- **No payment idempotency.** A double-submit creates two genuine payments.
- **Legacy `products` grouped by exact name** during the Stage 7 migration; two suppliers who both sold a "Composite A" now share one catalogue entry.
- **Backfilled snapshots** on pre-Stage-7 order lines were reconstructed from the then-current price; the true historical price was never stored.
- **Catalogue search is `LIKE '%term%'`** with no index support.

---

## 24. Stage 7 Status

Backend remediation is **complete through Stage 7**, plus **Stage 7.5** (API contract hardening for this handoff), and stopped there by decision.

| Stage | Delivered |
|---|---|
| 1 | Emergency security hardening |
| 2 | Test foundation + CI |
| 3 | Role model (`role` authoritative, `department` legacy) |
| 4 | Policy-based RBAC |
| 5 | Database integrity (FKs, deletion rules, uniqueness) |
| 6 | Financial ledger — exact decimal money, atomic locked payments |
| 7 | Product Master, base/selling price, historical price snapshot, returns & refunds |
| 7.5 | Angular API contract hardening — order-line snapshot exposure, decimal money on orders, `pending_return_quantity`, single-order endpoint, `base_price` update, price validation parity |

Verified intact at handoff: Product Master ✅ · supplier base/selling price ✅ · historical `unit_price` and `line_total` ✅ · returns ✅ · refunds ✅ · Stage 6 ledger ✅ · Stage 5 integrity ✅ · Stage 4 RBAC ✅.

**Test suite: 439 tests, 1574 assertions, all passing.**

Reference docs in the repository: `PRODUCT_MODEL.md` (catalogue, pricing, returns), `ROLE_MODEL.md` (identity), `TESTING.md` (test database and safety guard).
