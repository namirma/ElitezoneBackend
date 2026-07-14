# Changelog — branch `feature/spec-gaps-elite yar-cap-ticketing`

Backend changes since `main`. **All 203 tests pass; Pint clean.** Run `php artisan migrate`
after deploy (see `docs/DEPLOY-runbook.md`).

## 🔒 Security fixes
- **Order invoice download authorization** — buyer documents (initial/final/tax) were
  downloadable by any order party (supplier/elite yar). Now restricted per-document to the
  rightful audience; others get 403. (`OrderInvoiceController`)
- **Financial receipts off the public disk** — wallet/supplier/order receipts moved to a
  private `financial` disk (no longer reachable at `/storage/...`). Data migration relocates
  existing files. Download link unchanged.
- **Invoice PDFs off the public disk** — archived invoices moved to the private disk too
  (download regenerates on the fly, so no API change).
- **Admin deposit-confirm double-credit (race)** — `ProcessTransactionAction` now re-checks
  the transaction under `lockForUpdate` inside the transaction.
- **Order cancel double-refund/double-restore (race)** — `CancelOrderAction` is now idempotent
  (lock + already-cancelled guard).
- **Email-token verification** — `checkToken` matched ANY token (array-where bug); now matches
  the exact token (wrong token → 400).
- **Mobile change** — the new number is now persisted in the normalized `09…` form.

## ✨ Features
- **EliteYAR lifetime commission cap** — wired (`users.badge_purchase_amount` ×
  `cap_multiplier`); dormant until that field is populated (Saze Nod import — see CLAUDE.md).
- **Ticketing helpdesk model** — full status state-machine, priority, order/location link,
  SLA stamping, internal notes (`ticket_notes`), opening-message attachments
  (`ticket_files.ticket_id`), KPI endpoint, admin note endpoint.
- **Pre-checkout shipping quote** — `POST /cart/shipping-quote` + `GET /shipping-methods`;
  shared logistics with `PlaceOrderAction` so the quote equals the charge.
- **Pasargad gateway scaffold** — config-driven, fail-safe; fill the two INTEGRATION POINTs
  when bank credentials arrive.

## 🐞 Bug fixes (frontend-reported)
- Engineer/Contractor role auto-attach used wrong UserType slug (`elite yar` → `expert`).
- Job-experience dates rejected valid Jalali dates (`date_format` → regex).
- `ProductVariantResource` now exposes purchase limits (min/max order value, limit unit).
- Removed dead `EmployerController::updateEliteYAR`.
- `OrderResource` variant name (snake_case relation never resolved).
- Reservation-threshold code fallback aligned with config (1_950M → 2_000M).
- `OrderRepository` my-orders N+1 (eager-load `Order::resourceRelations()`).

## 📚 Docs
- `CLAUDE.md` updated across all the above.
- `docs/admin-panel-api.md` + `docs/admin-api.json` — full back-office API reference.
- `docs/frontend-changes.md` — consolidated frontend change notes.

## 🗄️ New migrations (run on deploy)
1. `users.badge_purchase_amount`
2. ticketing fields on `tickets` (+ widen `status` to varchar)
3. `ticket_notes` table
4. `ticket_files.ticket_id`
5. move financial receipts → private disk (data migration)
6. move invoice PDFs → private disk (data migration)

## ⚙️ New env vars (all optional — have safe defaults)
- `ELITE_YAR_LIFETIME_CAP_MULTIPLIER` (default 2)
- `TICKET_SLA_URGENT_HOURS` / `_HIGH_` / `_NORMAL_` / `_LOW_` (defaults 2/8/24/72)
- Pasargad (only when going live): `PASARGAD_BASE_URL`, `PASARGAD_API_KEY`,
  `PASARGAD_TERMINAL_ID`, `PASARGAD_MERCHANT_ID`, `PASARGAD_CALLBACK_URL`
