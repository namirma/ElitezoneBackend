# EliteZone — Frontend Change Notes

> Backend branch: `feature/spec-gaps-consultant-cap-ticketing`.
> Most screens already exist; the items below **changed behaviour or contract** and the
> existing frontend must be updated. First: backend runs `php artisan migrate`.
> Response envelope everywhere: `{ status: bool, message: string, data: … }`.

---

## A) User-facing app — CHANGED behaviour (update existing screens)

### A1. Order invoice download is now authorization-restricted  ⚠️ breaking
`GET /api/profile/orders/{order}/invoice/{type}`  (`type` ∈ `initial | final | tax`)
- These are **buyer documents** → only the order's **buyer (placer)** (or an admin) may download.
- If a user who is the **supplier or consultant** of that order requests them → **403** (was 200).
- Action: hide these buttons for non-buyer roles, and handle 403 gracefully.

### A2. Email-token verification now returns 400 on a wrong token  ⚠️ behaviour change
`POST /api/profile/check-email-token`  body `{ token }`
- Previously it almost always “succeeded” (a backend bug). Now: invalid/unknown token → **400**;
  only the correct token → **200** with `{ email }`.
- Action: the verify-email page must handle the 400 path (show “invalid/expired link”).

### A3. Job-experience dates now accept Jalali (Persian) dates
`POST /api/profile/job-experiences` — `start_date` / `end_date`
- Format is `YYYY-MM-DD` **Jalali** (e.g. `1402-06-31`). Previously valid Jalali dates were rejected.
- `end_date` must be `>= start_date` (else 422 with `job.end_before_start`).
- Action: if the frontend was converting these to Gregorian or blocking day 31 of months 1–6,
  remove that — send the Jalali string as-is.

### A4. Technical-consultant role now actually attaches after profile completion
`POST /api/profile/engineer` and `POST /api/profile/contractor`
- A backend bug meant the `engineer` / `contractor` **role** was never attached. It now attaches
  when the user’s type is the technical-consultant type.
- Action: any role-gated UI (menus, permissions) should expect the `engineer`/`contractor` role to
  appear in the user’s roles right after they complete that profile.

### A5. Product variant now exposes purchase limits
`ProductVariantResource` (everywhere a variant is returned) gained:
- `min_order_value`, `max_order_value` (string|null), `limit_order_unit_id` (int|null), and
  `limit_order_unit: { id, name, amount }` (only when the relation is eager-loaded).
- Action: validate min/max **before** submit instead of only reacting to a 422 at add-to-cart.

### A6. Order item name in summaries may now show the variant name
`OrderResource.items_summary[].name`
- Previously always fell back to the product name (a snake_case bug). Now it shows the
  **product-variant name** when available, else the product name.
- Action: cosmetic — just be aware the displayed item name can differ from before.

---

## B) User-facing app — NEW endpoints to implement

### B1. Pre-checkout shipping quote  (replaces hardcoded `shipping_method_id = 1`)
`POST /api/cart/shipping-quote`  (auth)  body `{ address_id }`
```jsonc
data: {
  "total": "string(Rials)",
  "per_line": { "<cart_item_id>": "string" },
  "shipments": [ {
    "factory_id": 0, "factory_location_id": 0, "address_id": 0,
    "destination_province_id": 0, "destination_city_id": 0,
    "weight": "string", "count": "string", "cost_basis": "weight|count", "cost": "string"
  } ]
}
```
- `address_id` must belong to the user (else 403). The quoted `total` equals the freight charged
  at checkout (same engine). Show shipping cost on the cart/checkout review before payment.

`GET /api/shipping-methods`  (public) → list of `{ id, name, code }`. Use it to pick a method
instead of hardcoding `1`.

### B2. Ticketing — richer create + response (helpdesk model)
Create ticket (existing endpoint) — new **optional** body fields:
- `priority`: `low | normal | high | urgent` (default `normal`)
- `order_id`: link the ticket to an order (must belong to the user, else 403)
- `province_id`, `city_id`: delivery province/city
- `file[]`: attachments — **now actually stored** (previously validated then dropped)

Ticket response (`TicketResource`) — new fields:
```jsonc
{
  "status_info": { "key": "open", "label": "باز", "tone": "ongoing" },   // use label/tone, don't hardcode
  "priority": { "key": "normal", "label": "عادی" },
  "order_id": null, "province_id": null, "city_id": null,
  "sla_due_at": "datetime|null", "sla_breached": false,
  "assigned_at": null, "first_responded_at": null, "resolved_at": null, "closed_at": null,
  "reopened_count": 0,
  "attachments": [ … ]   // now populated for the opening message too
}
```
⚠️ `status_info.key` now has more values: `open, in_progress, waiting_customer,
waiting_supplier, answered, resolved, closed` (+ legacy `pending`). Render via
`status_info.label` / `status_info.tone`, not a hardcoded map.

---

## C) Admin panel (back-office) — NEW / changed endpoints

> Full admin spec is in `docs/admin-panel-api.md` and `docs/admin-api.json`. Highlights that are new:

### C1. Ticket helpdesk (admin)
- `GET /api/admin/tickets/kpis` → `{ total, open, sla_breaches, by_department, by_status,
  avg_first_response_minutes, avg_resolution_minutes, reopen_rate }`
- `POST /api/admin/tickets/{ticket}/note` → body `{ note }` (internal staff note)
- `GET /api/admin/tickets` status filter and `POST /api/admin/tickets/{ticket}/status` now accept the
  **full status set** above (not just open/answered/pending/closed).

### C2. No contract change, but safer now (no UI change needed)
- `POST /api/admin/wallet/transactions/{transaction}/status` (confirm/reject) is now double-credit
  safe — same request/response.

---

## D) No frontend change (FYI only)
- Financial receipts moved to a private disk — the **download link is unchanged** (still the
  authenticated `…/receipts/transactions/{transaction}/download` route).
- Mobile-change now stores the normalized `09…` number (internal correctness).
- Consultant lifetime commission cap, Pasargad gateway scaffold — backend only.

---

## E) Quick checklist for the frontend team
- [ ] Hide buyer-invoice buttons for supplier/consultant; handle 403 (A1).
- [ ] Handle 400 on email-token verify (A2).
- [ ] Send Jalali dates as-is for job experience; stop blocking day 31 (A3).
- [ ] Expect engineer/contractor role after profile completion (A4).
- [ ] Use variant min/max for pre-submit validation (A5).
- [ ] Call `POST /cart/shipping-quote` + `GET /shipping-methods`; stop hardcoding shipping_method_id=1 (B1).
- [ ] Add ticket priority/order/location/attachment fields; render new statuses via label/tone (B2).
- [ ] Admin: wire ticket KPIs + internal notes; allow full ticket status set (C1).
