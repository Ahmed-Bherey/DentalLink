# Product Master, Pricing, and Historical Order Value

Written in Stage 7. Companion to `ROLE_MODEL.md` (identity) and `TESTING.md`.

---

## 1. The three things that used to be one thing

Before Stage 7 the `products` table carried three unrelated responsibilities:

| Responsibility | How it appeared |
|---|---|
| Canonical identity | `name` + `category_id`, duplicated once per owner |
| A supplier's offer | `user_id` = supplier, one mutable `price` |
| A doctor's inventory | `user_id` = doctor — a **copy** created on delivery and matched back by **name string** |

Consequences:

- "Dental Composite A" from two suppliers was two unrelated rows. The catalogue could not be searched or compared.
- Delivery matched a doctor's stock on `name`, which merged two different products sharing a name and split one product whose name had been edited.
- `order_items` stored **no price**, so every monetary value was recomputed from the supplier's *current* price. Repricing a product retroactively changed the value of orders already placed.

## 2. The model now

```
ProductMaster          canonical identity. Super Admin owned. NO price.
      │
      │ product_master_id
      ▼
Product                one user's HOLDING of one canonical product
                       supplier row → offer     (base_price = cost, price = selling)
                       doctor row   → inventory
                       UNIQUE (user_id, product_master_id)
      │
      │ product_id  + frozen snapshot
      ▼
OrderItem              unit_price · line_total · quantity · product_master_id · supplier_id
                       ↑ immutable from the moment the order is placed
      │
      ▼
OrderItemRefund        an approved return, priced from the line's snapshot
```

### Why `price` was not renamed to `selling_price`

`price` already means "what the buyer pays" in the resources, the Excel import/export, order totals and package composition. Renaming it would touch all of those for no behavioural gain and would silently break the import format. `base_price` was **added** alongside instead — new meaning arrives as a new column, not by redefining an existing one.

### Why the canonical product has no price

Only a supplier's *offer* of a product has a price. Keeping price off the master is what makes the same product comparable across suppliers.

## 3. The historical price rule

> A doctor buys at 10. The supplier later charges 12. The existing order stays at 10 — unpaid, part-paid, or returned.

Enforced by freezing the terms onto `order_items` at purchase:

| Column | Meaning | Mutable? |
|---|---|---|
| `unit_price` | price of one unit in this transaction | **no** |
| `quantity` | units purchased | **no** |
| `line_total` | `unit_price × quantity` at purchase | **no** |
| `product_master_id` | canonical identity | **no** |
| `supplier_id` | who sold it | **no** |
| `returned_quantity` | cumulative approved returns | grows only |
| `pending_return_quantity` | awaiting the supplier's decision | transient |

`line_total` is stored even though it equals `unit_price × quantity` today: returns accumulate against the line, and the original contract value must stay readable afterwards.

**Always** read `OrderItem::historicalUnitPrice()` / `historicalLineTotal()`. Never `$item->product->price`.

### Quantity semantics changed

An approved return used to **decrement** `order_items.quantity`, destroying the record of what was bought and making "a return may not exceed the purchase" impossible to check across multiple returns. `quantity` is now immutable and `returned_quantity` accumulates; outstanding is the difference.

## 4. Fulfilment

**Fulfilment is a property of a LINE, not of an order.** An order may contain
lines from several suppliers; each supplier fulfils their own.

```
order_items.fulfilled_at    null → not yet delivered
                            set  → this supplier has delivered this line
```

`orders.status` describes the whole order and is derived, not set by hand:
`delivered` once every line is fulfilled, `preparing` while any remain.

`POST /api/order/update-status/{id}` with `delivered` runs
`OrderService::fulfilForSupplier()`, which acts **only on the caller's own
unfulfilled lines**, inside one transaction, with those lines and the ledger
row locked. It:

1. refuses if the supplier has no unfulfilled lines on the order (so a repeat
   request is a no-op, not a second charge);
2. refuses if any of those lines exceeds available stock — rolling the whole
   operation back, so a short line cannot leave earlier lines half-fulfilled;
3. moves stock from the supplier to the doctor;
4. stamps `fulfilled_at`;
5. charges **only the sum of that supplier's own lines** to
   `order_expenses(doctor, that supplier)`.

For a package order the charge is the agreed `orders.price`, not a sum of lines.

### Why this needed a column

Before, fulfilment was recorded only as `orders.status = 'delivered'` — one
flag for the whole order. That single flag could not say *which* supplier had
delivered *which* lines, so:

- the full order total was charged to whoever pressed "delivered" first, and
  the other supplier was never credited at all;
- nothing recorded that a line had already been fulfilled, so a repeated
  request charged again and moved stock again;
- a return could be approved against goods that were never delivered, reversing
  an obligation that had never been created.

## 5. Returns and refunds

A return is only possible against goods that were actually **delivered**:

```
returnable = fulfilled quantity − already-approved returns
```

An unfulfilled line is not returnable at all.

```
doctor requests   →  pending_return_quantity set, status delete_pending
                     NO financial effect
                     refused unless the line is fulfilled
supplier decides  →  rejected  : request discarded, nothing moves
                  →  confirmed : OrderItemRefund created, stock moves,
                                 returned_quantity grows
```

A **whole-order** return (`DELETE /api/order/delete/{id}` then the supplier
confirming) refunds every still-held line at its own frozen price, through the
same ledger mechanism. It excludes quantities already returned line-by-line, so
the same unit can never be refunded twice, and the order is marked `returned`
rather than deleted — deleting it would destroy the history the refunds exist
to explain.

Administrative deletion (`OrderService::delete()`) is a different thing and is
refused once any line has been fulfilled.

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

`remaining` is unchanged by a refund — that falls out of `(total − R) − (paid − R)`, it is not a special case.

The refund is priced from the **line's snapshot**, never the current product price. Bought 5 at 10, repriced to 12, returned 2 → refund is **20**, not 24.

## 6. Who may do what

| Action | Super Admin | Supplier | Doctor |
|---|---|---|---|
| Create / edit canonical product | ✅ | ❌ | ❌ |
| Archive canonical product | ✅ | ❌ | ❌ |
| Delete canonical product | ❌ — refused for everyone | ❌ | ❌ |
| Browse / search catalogue | ✅ | ✅ | ✅ |
| Create own offer / inventory | ❌ | ✅ | ✅ |
| Set base + selling price | ❌ | ✅ | (inventory only) |
| Edit another's offer | ❌ | ❌ | ❌ |

Reading the catalogue is open because **selection is impossible without search** — that is the mechanism replacing free-text product invention. The rule is enforced server-side by `InventoryRequest` requiring `product_master_id`, not by the UI.

`base_price` is the supplier's **cost**. It is exposed only to the owner of the offer; a doctor browsing never sees it.

This is the first genuine write capability Super Admin has. Stage 4 deliberately kept the role read-only because no admin functionality existed to attach it to; Stage 7 supplies exactly that, so the grant is narrow — catalogue only, via `ProductMasterPolicy`. The `Gate::before` allow-list in `AuthServiceProvider` remains read-only.

## 7. Adding a product as a supplier

```
GET  /api/product-master/index?search=composite   → find the canonical product
POST /api/product/store  { product_master_id, price, base_price, quantity }
```

Descriptive fields (`name`, `category_id`, `img`, `desc`) are inherited from the canonical product when omitted, and may be overridden per holding.

The Excel import (`ProductsImport`) **resolves** a canonical product by exact name and reports unmatched rows via `unmatchedProducts()`. It never creates catalogue entries — that would hand every supplier the Super Admin's job through the side door.

## 8. Known limits

- **Exact-name grouping** was used to migrate legacy rows onto canonical products. It is deterministic, but two suppliers who both sold something called "Composite A" now share one catalogue entry. Each keeps its own price and historical order values are unaffected; a Super Admin can split them.
- **Backfilled snapshots** on pre-existing order lines were reconstructed from the product's *current* price. The true historical price was never stored and is not recoverable.
- **Credit is not modelled.** If refunds exceed payments on a paid order, `paid` floors at zero and the surplus is not represented. Open business decision, carried from Stage 6.
- **No soft deletes.** Archiving a canonical product is a single `is_active` flag; the broader strategy is Stage 8's.
- **Package components have no individual price.** A package sells for one agreed price (`orders.price`) and this domain defines no rule for allocating it across components, so package order lines carry identity (`product_master_id`, `supplier_id`) but `unit_price`/`line_total` stay NULL. A per-line return on a package line is therefore **refused** rather than priced from the component's current price. Returning a package requires an allocation rule — an open business decision.
