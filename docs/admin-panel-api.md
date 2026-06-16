# EliteZone — Admin / Back-Office API (frontend reference)

> Generated from the actual backend code on branch `feature/spec-gaps-consultant-cap-ticketing`.
> All amounts are in **Rials**. Run `php artisan migrate` on the backend before testing.

## Auth & response envelope

- **Auth (every endpoint below):** header `Authorization: Bearer <token>` and the user must
  have the `admin` role. Middleware: `auth:sanctum` + `role.admin`.
- **Base path:** `/api/admin/*` (Podium services are under `/api/v1/podium/*`).
- **Success envelope:** `{ "status": true, "message": "…", "data": <payload> }`
- **Error envelope:** `{ "status": false, "message": "…", "errors": <…> }`
- **Paginated endpoints:** `data` is the standard Laravel structure
  `{ "data": [ … ], "links": { … }, "meta": { … } }`.
- **Raw vs Resource:** most endpoints return shaped *Resources*. A few (marked **RAW**)
  paginate the Eloquent model directly, so you receive raw DB column names.

---

## 1. Dashboard

### GET `/api/admin/dashboard`
KPI dashboard. `data`:
```jsonc
{
  "today":   { "new_users": 0, "new_orders": 0, "revenue": 0.0 },
  "pipeline":[ { "status": "pending", "count": 0, "total_amount": 0.0 } ],   // one row per order status
  "financial_alerts": {
    "has_alerts": false, "total_alerts": 0,
    "pending_deposits": 0, "pending_deposits_amount": 0.0,
    "supplier_debts": 0,  "supplier_debts_amount": 0.0,
    "pending_moadian_invoices": 0, "expired_reservations": 0
  },
  "weekly_trend":  [ { "date": "2026-06-15", "count": 0, "revenue": 0.0 } ],
  "month_summary": { "paid_revenue": 0.0, "orders_count": 0, "total_users": 0, "new_users_this_month": 0 }
}
```

---

## 2. Activity log

### GET `/api/admin/activities`
Query: `subject_type` (e.g. `Order`), `subject_id`, `causer_id` — all optional.
`data`: **paginated** `ActivityLogResource`:
```jsonc
{ "id":0, "description":"", "subject_type":"Order", "subject_id":0, "causer":"name or 'سیستم'",
  "ip_address":null, "changes":{}, "old":{}, "custom_props":{}, "created_at":"1405/03/26 10:00:00",
  "created_at_diff":"…" }
```

---

## 3. Search

| Method | Path | Query | data |
|---|---|---|---|
| GET | `/api/admin/search/users` | `query` (min 2) | paginated `UserResource` |
| GET | `/api/admin/search/transactions` | `query`/`search`, `type`, `status`, `has_receipt` (bool) | paginated WalletTransaction **RAW** (incl. `wallet.user.profile`) |

---

## 4. Users

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/users` | query: `role` (`expert\|employer\|supplier`), `search` (min 2), `is_active` (bool), `per_page` (1–100) | paginated `UserResource` |
| GET | `/api/admin/users/{user}` | — | `UserResource` (engineer, employer, supplier, contractor, wallet, profile) |
| POST | `/api/admin/users/fetch-profile` | body: `keyword` (required, min 3 — mobile or national code) | full `UserResource`, or **404** |
| GET | `/api/admin/users/{user}/orders` | — | paginated `OrderResource` |
| GET | `/api/admin/users/{user}/transactions` | — | paginated `WalletTransactionResource` |
| POST | `/api/admin/users/{user}/toggle-status` | — | `UserResource` (is_active toggled) |
| POST | `/api/admin/users/{user}/note` | body: `note` (required, max 1000) | null |
| POST | `/api/admin/users/{user}/verify-consultant` | — | `{ user_id, profile_type: "engineer\|contractor", is_verified: true, verified_at }` — **422** if no consultant profile |
| POST | `/api/admin/users/{user}/unverify-consultant` | — | `{ user_id, profile_type, is_verified: false, verified_at: null }` |

---

## 5. Suppliers

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/suppliers` | query: `search` (min 2), `is_active`, `per_page` | paginated `UserResource` |
| GET | `/api/admin/suppliers/{user}` | — | see below — **404** if user is not a supplier |
| PUT | `/api/admin/suppliers/{user}/formula` | body: `trans_commission` (bool, req), `trans_vat` (bool, req) | `{ trans_commission, trans_vat }` |
| GET | `/api/admin/suppliers/{user}/orders` | query: `status`, `per_page` | paginated `OrderResource` |

`GET /suppliers/{user}` data:
```jsonc
{
  "user": { /* UserResource */ },
  "formula_flags": { "trans_commission": true, "trans_vat": true },   // null if no supplier profile
  "order_stats": [ { "status": "delivered", "count": 0, "total_amount": 0.0 } ],
  "wallet": { "balance": 0.0, "balance_hold": 0.0 }
}
```
> `formula` controls the POO/PTS commission/VAT-on-shipping variant. Changing it affects only
> **new** orders — paid orders keep their snapshot.

---

## 6. Orders

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/orders` | query: `order_number`, `date_from`, `date_to` (≥ date_from), `status` | paginated `OrderResource` |
| GET | `/api/admin/orders/{order}` | — | `OrderResource` |
| GET | `/api/admin/orders/{order}/history` | — | `[ { event, causer_name, created_at (Jalali), changes, old } ]` |
| POST | `/api/admin/orders/{order}/status` | body: `status` (req, `pending\|processing\|shipped\|delivered\|cancelled`) | null — see note |
| POST | `/api/admin/orders/{order}/system-moadian-invoice` | body: `moadian_ref` (req, 3–100) | Doc P — releases Saze VAT hold. **409** if already submitted |

> **Status note:** forward-only state machine. `cancelled` routes through `CancelOrderAction`
> (wallet refund + stock restore + documents). An illegal jump returns **422**
> `{ current, requested }`.

---

## 7. Weighbridge

### POST `/api/admin/{order}/weighbridge`
Body: `weight_after_scale` (numeric, req, ≥ 1).
`data`:
```jsonc
{
  "order": { /* OrderResource */ },
  "weight_details": { "status": "equal|deficit|already_processed",
    "old_weight": "…", "new_weight": "…", "financial_diff": "…", "final_amount": "…" },
  "message": "…"
}
```

---

## 8. Wallet (manual transaction review)

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/wallet/transactions` | query: `status`, `type` (`deposit\|withdraw`), `user_id` | paginated `WalletTransactionResource` |
| POST | `/api/admin/wallet/transactions/{transaction}/status` | body: `status` (req, `confirmed\|rejected`), `note` (opt, max 255) | `WalletTransactionResource` |

> Confirm a deposit → credits the wallet. Reject a withdrawal → refunds the held amount.
> Double-credit safe (idempotent under lock).

---

## 9. Financial documents (all **RAW**, paginated)

| Method | Path | Query | data |
|---|---|---|---|
| GET | `/api/admin/financial/company/invoices` | `type` (`initial\|final\|command\|tax`, default initial), `payment_status` (only with type=initial) | RAW Order (+ placer.profile, items.productVariant) |
| GET | `/api/admin/financial/company/expert-shares` | `mode` (`allocation\|payment`, default allocation) | RAW WalletTransaction |
| GET | `/api/admin/financial/supplier/documents` | `doc_type` (`waybill\|tax`, default waybill) | RAW Order |
| GET | `/api/admin/financial/expert/documents` | — | RAW WalletTransaction (withdraw with invoice_path) |

---

## 10. Financial health

| Method | Path | data |
|---|---|---|
| GET | `/api/admin/financial/health` | see below |
| GET | `/api/admin/financial/pending-debts` | paginated `OrderResource` (supplier_debt_pending=true) |
| GET | `/api/admin/financial/pending-moadian` | paginated objects (see below) |

`/financial/health` data:
```jsonc
{
  "system_wallet": { "balance": 0.0, "balance_hold": 0.0 },   // or null
  "vat_wallet":    { "balance": 0.0, "balance_hold": 0.0 },   // or null (when same as system)
  "platform_totals": { "total_balance": 0.0, "total_hold": 0.0,
                       "pending_deposit_amount": 0.0, "pending_withdrawal_amount": 0.0 },
  "supplier_debts": { "count": 0, "amount": 0.0 }
}
```
`/financial/pending-moadian` item:
```jsonc
{ "id":0, "order_number":"", "status":"", "placer":"name", "final_amount":0.0,
  "weighing_completed_at":"…", "consultant_moadian_done":false,
  "supplier_moadian_done":false, "system_moadian_done":false, "has_beneficiary":false }
```

---

## 11. Podium direct deposits (شناسه واریز)

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/financial/podium-deposits` | query: `status` (`matched\|unmatched\|all`), `from`/`to` (Gregorian Y-m-d), `search` | `{ stats: {total,matched,unmatched}, records: paginated PodiumDepositRecord }` |
| POST | `/api/admin/financial/podium-deposits/poll` | body: `minutes` (opt, default 60, clamped 1–10080) | `{ window, summary:{total_incoming,new_matched,new_unmatched,skipped,errors}, errors:[] }` |
| POST | `/api/admin/financial/podium-deposits/{record}/credit` | body: `user_id` (req, exists), `note` (opt, max 255) | `{ user_id, amount_rials, amount_tomans, doc_number }` — **422** if already credited |

---

## 12. Tickets (helpdesk)

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/tickets` | query: `status` (full set), `department_id`, `search` (min 2), `per_page` | paginated `TicketResource` |
| GET | `/api/admin/tickets/kpis` | — | KPI object (below) |
| GET | `/api/admin/tickets/{ticket}` | — | full `TicketResource` (user, department, files, replies) |
| POST | `/api/admin/tickets/{ticket}/reply` | body: `message` (req, 2–5000) | `TicketResource` (status→answered, assigned, SMS to user) |
| POST | `/api/admin/tickets/{ticket}/status` | body: `status` (req, one of the statuses below) | null — `closed` sends SMS; timestamps & reopen counter are stamped |
| POST | `/api/admin/tickets/{ticket}/note` | body: `note` (req, max 2000) | null — internal staff note |

**Ticket statuses:** `open`, `in_progress`, `waiting_customer`, `waiting_supplier`,
`answered`, `resolved`, `closed`, `pending` (legacy).
**Priorities:** `low`, `normal`, `high`, `urgent`.

`/tickets/kpis` data:
```jsonc
{ "total":0, "open":0, "sla_breaches":0,
  "by_department": { "1": 0 }, "by_status": { "open": 0 },
  "avg_first_response_minutes": 0.0, "avg_resolution_minutes": 0.0, "reopen_rate": 0.0 }
```

`TicketResource` shape:
```jsonc
{
  "id":0, "code":"", "subject":"", "message":"",
  "status_info": { "key":"open", "label":"باز", "tone":"ongoing" },
  "status":"open",
  "priority": { "key":"normal", "label":"عادی" },
  "order_id":null, "province_id":null, "city_id":null,
  "sla_due_at":"…", "sla_breached":false,
  "assigned_at":null, "first_responded_at":null, "resolved_at":null, "closed_at":null,
  "reopened_count":0,
  "created_at":"…", "user":{…},
  "department":{…}, "attachments":[…], "replies":[…]
}
```

---

## 13. Shipping rates (CRUD)

| Method | Path | Body / Query | data |
|---|---|---|---|
| GET | `/api/admin/shipping-rates` | query: `factory_location_id`, `province_id`, `is_active`, `per_page` (1–200) | paginated `ShippingRateResource` |
| POST | `/api/admin/shipping-rates` | body (below) | `ShippingRateResource` — **422** on duplicate route |
| PUT | `/api/admin/shipping-rates/{shippingRate}` | body (same as POST) | `ShippingRateResource` |
| DELETE | `/api/admin/shipping-rates/{shippingRate}` | — | null |
| POST | `/api/admin/shipping-rates/{shippingRate}/toggle` | — | `ShippingRateResource` (is_active toggled) |

POST/PUT body:
```jsonc
{
  "factory_location_id": 0,        // required, exists
  "destination_province_id": 0,    // required, >=1
  "destination_city_id": null,     // nullable (null = province-level fallback)
  "flat_cost": 0,                  // required, >=0
  "per_kg_cost": 0,                // required, >=0
  "cost_basis": "weight",          // required: "weight" | "count"
  "per_count_cost": 0,             // required if cost_basis = "count"
  "is_active": true                // optional bool
}
```
`ShippingRateResource` shape:
```jsonc
{ "id":0, "factory_location_id":0, "factory_location_name":"…|null",
  "destination_province_id":0, "destination_city_id":null,
  "flat_cost":0.0, "per_kg_cost":0.0, "cost_basis":"weight|count",
  "cost_basis_label":"…", "per_count_cost":0.0, "is_active":true, "created_at":"…" }
```

---

## 14. Podium services (`/api/v1/podium/*`, admin only)

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/podium/settlements/single` | single settlement |
| GET | `/api/v1/podium/balances` | account balances |
| POST | `/api/v1/podium/settlements/batch` | batch settlement |
| GET | `/api/v1/podium/settlements/inquiry` | settlement status inquiry |
| POST | `/api/v1/podium/identity-inquiry-sc2` | identity inquiry (national code + birth date) |
| POST | `/api/v1/podium/mobile-nid-match` | national-code ↔ mobile match |

> These proxy the Podium provider; responses are the provider payload wrapped in the standard
> envelope. Use only from back-office tooling.

---

## 15. Debug (non-production only)

`GET /api/admin/debug/test-cache`, `GET /api/admin/debug/kavir-products` — registered only when
`app()->environment() !== 'production'`.
