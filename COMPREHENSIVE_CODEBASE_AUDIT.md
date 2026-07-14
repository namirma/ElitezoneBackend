# 🔍 COMPREHENSIVE CODEBASE AUDIT — EliteZone Backend (v2, design-conformance)

**Auditor:** Principal Laravel Architect / Lead Security Auditor
**Date:** 2026-06-07
**Method:** Manual trace (routes → controllers → actions/services → models) **plus 6 specialised sub-agents run in parallel**, each cross-checking the code against the business design spec `C:\Users\hp\Desktop\پروسه خرید (2).md`. Read-only — **no source files were modified.**
**Spec used as source of truth:** `پروسه خرید (2).md` (2699 lines) — documents 0–T, POO/PTS/PTC formulas, wallet ledger movements, document numbering, payment limits, ticketing, Passargad/SHEBA.

> This v2 supersedes the first pass. It adds a full **Design-Spec Conformance** section and dozens of new findings from the parallel deep-dive (wallet ledger, documents, ticketing, deposit/withdrawal security, pricing N+1).

---

## 0. Executive Summary

The internal **wallet settlement math is correct** and the core money-movement actions (`WalletTransactionEngine`, direct-purchase, reservation) are well-engineered (locking + idempotency + balanced ledger). But the audit found the product is **substantially incomplete vs. its own design spec** and carries several **financial-integrity and security defects**:

- **External payment is non-functional** (gateway `verify()` undefined → fatal; redirect → localhost; Passargad — the spec's actual bank — is absent; deposits return `dummy-gateway.com`).
- **Withdrawals confirm-and-debit on request** with no hold/admin-approval; **deposits credit a user-declared amount** on a blind admin click; **idempotency is opt-in** (no-op without a client header).
- **Buy-for-others has no consent gate**, **supplier role is self-assignable** (affecting settlement flags).
- **Document model diverges from the spec** (letter `K`=waybill in spec but `=supplementary` in code; docs **O/Q/K not generated**; doc **R freight-settlement entirely missing** — the spec says everything depends on R).
- **Ticketing is ~20% implemented** (no SLA, no admin side, no notifications, attachments silently dropped).
- The biggest performance hit is the **pricing pipeline issuing per-row queries** on the two hottest endpoints.

| Severity | Count | Theme |
|---|---|---|
| 🔴 Critical | 11 | Broken/insecure payments, withdrawal & deposit fraud vectors, idempotency bypass, self-assigned supplier role, buy-for-others consent, admin state-machine bypass |
| 🟠 Performance | 8 | Pricing pipeline N+1, role-resolver `fresh()`, resource N+1s, broken `getSalesStats`, missing indexes |
| 🟡 Architecture | 9 | Dead enums, gateway-naming, god-resources, nested wallet transactions, magic numbers, doc/CLAUDE.md drift |
| 🔵 Admin panel | 8 | State bypass, SQL crash, search logic bug, stubs, raw-model leakage, no audit trail |
| 🟢 Best practice | 10 | Hardening + consistency |
| 📐 Spec conformance | — | Formulas ✅, ledger ⚠️ partial, documents ⚠️, numbering ~✅, features ❌ many missing |

---

## 📐 1. Design-Spec Conformance (پروسه خرید) — the headline section

### 1.1 Settlement formulas — ✅ CORRECT (and the spec contradicts itself)

`SettlementCalculatorService` was verified **formula-by-formula** against spec lines 1299–1685. **All four ±%TC/±%VAT variants of POO, POOM, PTS, PTSM match exactly**, and the consultant gross derivation `(POOM−PTSM)×%CO/(%CO+%SSG)` is algebraically equal to the spec's intended PTC (the spec's PTC table is copy-paste-corrupted; the code's derivation is actually more robust). Badge splits and the VAT multiplier `(vat/100)/(1+vat/100)` match.

> ⚠️ **Important nuance:** the audit brief's "%OW = 60% × %TC" comes from the spec **glossary (line 1528)**, which **contradicts the spec's own calculation section (lines 1635–1636)** where `%OW = 40% × %TC` and `%CO+%SSG = 60% × %TC`. The code uses **40%** (`SettlementCalculatorService.php:202-205`), matching the authoritative calculation section. **Do NOT "fix" it to 60%** — that would break every badge split and underpay Saze/consultants. Recommend correcting the spec glossary instead.

Residual (not bugs): consultant **lifetime cap is inert** (`consultantLifetimeCeiling()` returns `null`, `ProcessWeighbridgeResultAction.php:306`) — a gold/silver consultant can exceed the 2× lifetime ceiling; and **`%TC` is hardcoded at 1%** (`SettlementCalculatorService.php:35`) though the spec says it's super-admin-editable per supplier.

### 1.2 Wallet ledger movements per stage — ⚠️ mostly correct, with deliberate & accidental deviations

| Stage | Spec legs | Code | Verdict |
|---|---|---|---|
| Direct purchase | A:(POO,WO,A)→(POO,WSSG,A); B:(PTS,WSSG,A)→(PTS,WS,A) | `ProcessPreScalePaymentAction.php:104-121` | ✅ MATCH |
| Reservation start | E:(PTR,WO,A)→(PTR,WSSG,**H**) | `ProcessReservationPaymentAction.php:51-53` | ✅ MATCH |
| Reservation completion | G:(POO−PTR,WO,A)→WSSG; H:(PTS,WSSG)→WS | `ProcessReservationCompletionAction.php:110-122` | ✅ MATCH (+justified hold-release leg) |
| Reserve cancel (expiry) | i:(PTR,WSSG,**A**)→(PTR,WO,A) | `CancelOrderAction.php:106-108` subtracts from WSSG **hold** | ⚠️ code uses Hold (more correct than spec's `A`); reconcile spec |
| Weighbridge LO/LS | (POO−POOM) WSSG→WO ; (PTS−PTSM) WS→WSSG | `ProcessWeighbridgeResultAction.php:131-165` | ⚠️ buyer refund credited to **Hold** when supplier can't cover (deliberate safety extension) |
| Weighbridge VAT legs | O:(%VATPTSM,WVAT,**A**); Q:(%VATPTC,WVAT,**A**); P:(%VATPOOM,WVAT,**H**) | code puts **supplier & consultant VAT in HOLD** and releases on Moadian submission; P basis = `%VAT of (POOM−PTSM−consultantGross)` not `%VAT of POOM` | ⚠️ **systematic Hold/Available + basis mismatch** vs spec |

🔴 **Ledger leak — `PayHoldFeeAction`** (`PayHoldFeeAction.php:70`): the hold fee is **debited from WO with no offsetting credit to any wallet** — double-entry is violated; the money vanishes from the ledger. Must credit WSSG (or wherever the fee accrues).

### 1.3 Documents — ⚠️ letter scheme diverged; several docs never generated

Spec documents: `0, A–H, i, j, K(=waybill), LO, LS, O, P, Q, R(=freight settlement), S(buyer survey), T(consultant survey)`.

| Issue | Detail | Evidence |
|---|---|---|
| 🔴 **Doc R missing entirely** | Spec says R (freight settlement + delivery receipt) is the doc **"everything depends on"**. Constant `DOC_FREIGHT_SETTLEMENT='R'` exists but is **never generated**, has no wallet legs, no view, no action. The carrier/freight money path does not exist. | `FinancialCodes.php:87`, zero call sites |
| 🟠 **Letter `K` mismatch** | Spec `K = waybill (بارنامه)`; code `DOC_SUPPLEMENTARY_REPORT='K'`. Code files the waybill under unused `DOC_BILL_OF_LADING='N'`, and `addWaybill` never generates any doc. | `FinancialCodes.php:68,76`; `SupplierOrderController.php:35-52` |
| 🟠 **Invented letters** | Code adds capital `I`(exit permit), `J`(supplementary receipt), `M`(refund-weigh) with **no spec basis**; spec only has lowercase `i`/`j`. | `FinancialCodes.php:64,66,74` |
| 🟠 **Docs O & Q never auto-generated** | `RecordSupplierMoadianInvoiceAction` (O) and `ReleaseEliteYarHoldAction` (Q) release holds but **never call `InvoiceGenerator::generate()`** — only P is generated. **CLAUDE.md's doc-table claiming O/Q are emitted is inaccurate (doc/code drift).** | both action files; cf. `RecordSystemMoadianInvoiceAction.php:120` |
| 🟢 **Recorded invoice amount wrong** | For LO/LS/O/P/Q the stored `invoices.amount` defaults to `final_amount` regardless of the doc's true subject (PTSM, VAT figure, …). | `InvoiceGenerator.php:104-108` |

### 1.4 Document numbering — ~✅ correct except increment step

`DocumentSequenceService` implements `EZ-[TYPE]-[CTX]-YYYYMM-SEQ` correctly: all TYPE/CTX codes present, SEQ prefixed `703`, 5-digit counter from `00100`, **Jalali** `YYYYMM` (`verta()->format('Ym')`), monthly reset via month-scoped prefix query (`DocumentSequenceService.php:49-76`). **One discrepancy:** spec example implies a **step of +100** per item (00100→00200); code increments by **+1** (line 72). The Persian wording is ambiguous — confirm with the author. (Tickets bypass this entirely and use a non-spec `id+100000` scheme — `TicketObserver.php:12` — leaving TCK/CTX constants dead.)

### 1.5 Missing / partial features vs spec

| Feature | Status | Evidence |
|---|---|---|
| 🔴 **Buy-for-others consent OTP gate** | **PARTIAL — not enforced.** OTP send/verify exists (`BeneficiaryController`) but placement never checks consent; `StoreOrderRequest.php:20` accepts any `beneficiary_user_id` as `exists:users,id`; `confirmVerification` stores no persistent consent flag. Any user can name an arbitrary beneficiary (→ commission routing). Legal-person BackOffice approval also absent. | `StoreOrderRequest.php:20`, `PlaceOrderAction.php:298`, `BeneficiaryController.php:138-141` |
| 🔴 **Passargad gateway** | **ABSENT.** Spec's bank is Passargad; code only has stubbed `shaparak` + `podium`/`tesna` (external sync). (Spec notes gateway is pending license, so partly expected — but `shaparak` naming is misleading and broken.) | `config/services.php`, `Services/Payment/Gateways/*` |
| 🟠 **4 dedicated agreements** | **PARTIAL.** Only 3 slugs mapped (`consultant/employer/supplier_terms`); spec also requires `elitezone_terms` + `privacy_policy` (5 total). **No agreement seeder exists**, so acceptance is a silent no-op today. Legal metadata (accepted_at/ip/user_agent) IS captured. | `SignAgreementAction.php:24-29,40-51` |
| 🟠 **Surveys S (buyer) & T (consultant)** | **PARTIAL.** `OrderSurvey` has no `type`/role column; the first submitter (buyer **or** consultant) sets `SURVEY_COMPLETED` and blocks the other. Cannot record both S and T. | `OrderSurveyController.php:27-40`, `OrderSurvey.php` |
| 🟠 **Reservation threshold** | **MISMATCH.** Code default `1,950,000,000` Rials (195M Toman); spec payment limit = **200M Toman**. Orders 195–200M Toman are needlessly forced into reservation. | `config/financial.php:21` |
| ✅ Stock "bell" subscription | PRESENT | `StockSubscriptionController`, `stock_subscriptions` |
| ✅ SHEBA refund collection | PRESENT | `UserBankAccount`, `ShebaInquiryService` |

### 1.6 Ticketing — ❌ ~20% of spec implemented

Implemented: user creates a ticket against a department, lists own tickets (owner-scoped), views one, posts a reply; core fields (date/time/department/subject/message). **Everything operational is missing:**

| Spec requirement | Status |
|---|---|
| 6 statuses (New/InProgress/Waiting-Customer/Waiting-Supplier/Resolved/Closed) | ❌ only 3 (`pending/answered/closed`) and never transitioned |
| Claim / Escalation / Change-department / Close | ❌ columns scaffolded (`admin_id`, `previous_department_id`) but no endpoints |
| SLA timers, first-response/resolution, reopen rate | ❌ no timestamps, no concept |
| SMS on every op + Email on status change | ❌ none (no events/listeners/SMS for tickets) |
| Link to order & transaction | ❌ no `order_id`/`transaction_id` on tickets |
| Dynamic intake (priority, order/pre-invoice no., province/city, attachments) | ❌ only subject/message/department |
| KPI/management dashboard | ❌ `AdminTicketController` is an empty stub |
| Per-ticket notes & per-user general notes | ❌ not modeled |
| Attachments | 🔴 **accepted, validated, then silently dropped** — `TicketDTO::toArray()` omits the file; no `ticket_files` table/relation; `TicketResource` references a non-existent `files()` relation. Data loss + false success. |

Ticket-code bugs: `CanSeeTicket.php:18-23` has **no admin bypass** (explicit TODO) and is **null-unsafe** (500 on missing/soft-deleted ticket); reply **never updates status**; list endpoint N+1 on `user`; reply error returns raw message with HTTP code `0`.

---

## 🔴 2. Critical Bugs & Security Flaws

### C-1 — Online payment callback fatal-errors (customer charged, wallet never credited)
`ProcessPaymentCallbackAction::execute()` calls `$gateway->verify()/isSuccessful()/getReferenceId()` — **none exist** (the interface declares only `pay()`/`getName()`). A PHP `Error` results, **not caught** by `catch (Exception)` in `PaymentController::callback`. `ShaparakGateway::pay()` returns a fake `'SH-'.rand()`; deposits return `dummy-gateway.com`.
*Files:* `app/Actions/Financial/ProcessPaymentCallbackAction.php:33-42`, `app/Services/Payment/Contracts/PaymentGatewayInterface.php`, `app/Services/Payment/Gateways/ShaparakGateway.php:24`, `WalletController.php:85`.
**Fix:** one gateway contract with `verify()` + typed `Receipt`; implement the real **Passargad** driver (spec) or fence off the route so customers can't be charged into a void.

### C-2 — Payment redirect always goes to `localhost`
`config('app.frontend_url', 'http://localhost:3000')` — wrong key (real key `app.front_url`). Every gateway redirect lands on localhost in prod. *`PaymentController.php:74`.*

### C-3 — Idempotency middleware is a silent no-op without a client header
`if (empty($key)) return $next($request);` → every "idempotency-protected" financial route (`pay-full`, `pay-hold-fee`, `withdraw`, `storeReceipt`) is **replayable** simply by omitting the header. *`Idempotency.php:30-32`.*
**Fix:** for financial routes, require the header (400 if absent) or derive a server-side key (user+route+payload). Double-credit guards must be server-side.

### C-4 — Withdrawal confirms-and-debits on request (no hold / no admin approval)
`DebitWalletAction` decrements `balance` and immediately writes a `STATUS_CONFIRMED` transaction. The spec requires a `Pending-approval` (در انتظار تایید) state; funds should move to `balance_hold` and only settle on admin approval (reverse on reject). *`DebitWalletAction.php:42-50`, `RequestWithdrawalAction.php:34-50`.*

### C-5 — Deposit credits a user-declared amount on a blind admin click
`ProcessTransactionAction` does `increment(BALANCE, $transaction->amount)` using the **user-supplied** amount, with no verification the uploaded receipt actually shows it. Primary deposit-fraud vector. *`WalletController.php:89-112`, `ProcessTransactionAction.php:40-41`.*
**Fix:** admin must confirm/override the credited amount; credit the verified value.

### C-6 — Self-service supplier role grants settlement-affecting flags
Any user can `POST /profile/type` with `roles=['supplier']` → `addRole('supplier')` with no admin gate, creating a `SupplierProfile`. `trans_commission`/`trans_vat` (which **snapshot into settlement**) are `$fillable`, so any future endpoint forwarding input lets a supplier flip its own commission/VAT formula. *`SyncUserRolesAction.php:41-49`, `SupplierProfile.php:74-75`.*
**Fix:** gate supplier role behind admin approval; remove the two flags from `$fillable`.

### C-7 — Buy-for-others has no consent enforcement (financial IDOR)
Order placement never verifies the named beneficiary consented; commissions are routed to an arbitrary `beneficiary_user_id`. *`StoreOrderRequest.php:20`, `PlaceOrderAction.php:298`.* (See §1.5.)

### C-8 — Admin `changeStatus` bypasses the order state machine
`$order->update(['status'=>...])` — sets `cancelled`/`delivered` directly, skipping `CancelOrderAction` (no refund, no supplier-leg reversal, no stock restore, no events). Accepts bogus `shipped`. *`AdminOrderController.php:66-72`.*

### C-9 — Predictable OTP reused as password-reset token
`generateVerifyCode()` uses `mt_rand` (non-CSPRNG) in a restricted range; the same `secret` is returned as the reset token and effectively never expires after verification. *`app/Http/Helper/function.php:42-45`, `AuthController.php:140`.*
**Fix:** `random_int`; separate, short-lived, single-use reset token.

### C-10 — Latent wallet double-credit (settle doesn't re-check status under lock)
`settle()` locks the wallet but never re-validates the transaction is still `pending`; retried gateway callbacks can double-increment. *`ProcessPaymentCallbackAction.php:48-66`.* (Masked today only because C-1 crashes first.)

### C-11 — `is_active` admin filter crashes (column doesn't exist) + user-mgmt stubs
`AdminUserController::index` filters a non-existent `users.is_active` column → 500; `toggleStatus`/`addNote` return `501`. *`AdminUserController.php:36-39,56-86`.*

---

## 🟠 3. Performance Bottlenecks

- **P-1 (biggest)** — **Pricing pipeline issues per-row queries.** `SetBasePrice` runs `variant->prices()->where(...)->first()` instead of reading the already-eager-loaded `prices` collection; `ApplyPriceFilters::getFilters()` re-fetches the `PriceFilterCategory`+filters **uncached for every line**. For V variants × P prices ≈ V×P pipeline runs × 1–2 queries each — on product-detail and cart, the hottest endpoints. *`Services/Pricing/Pipes/SetBasePrice.php:21-26`, `ApplyPriceFilters.php:81-97`, `VariantPriceHydrator.php:39-49`.* **Fix:** `firstWhere()` on the loaded collection; request-cache the resolved category+filters per role code.
- **P-2** — `PricingRoleResolver::resolve()` does `User::fresh(['roles','employer'])` on **every** active-code lookup, called multiple times per request. *`PricingRoleResolver.php:11`, `CartResolver.php:43-54`.* **Fix:** memoize per request.
- **P-3** — `getSalesStats()` is **broken**: `OrderItem::scopeSumQuantity()` ignores its argument and returns a scalar, then `->groupBy()` is chained on it → fatal/incorrect. *`InventoryIntelligenceService.php:105-110`, `OrderItem.php:112-115`.*
- **P-4** — `OrderResource` reads `$item->product_variant` (snake_case) which has no relation/accessor (relation is `productVariant`) → always null, falls back to `product_name`; eager-loaded `items.productVariant` wasted. *`OrderResource.php:96`.*
- **P-5** — `AdminOrderController::history` N+1 + null-pointer on `causer->profile` (not eager-loaded). *`AdminOrderController.php:21-29`.*
- **P-6** — `CalculateCustomerRfm` per-customer queries inside the loop; no chunking. Jobs `CheckSupplierBalancesJob`/`CheckReservationExpiryJob` `->get()` without `chunkById`. *`CalculateCustomerRfm.php:33-68`.*
- **P-7** — Non-sargable searches: leading-wildcard `LIKE '%..%'` (admin user/transaction/order search) and `whereJsonContains('meta->payment_method',…)` (`WalletController.php:161-165`), `DATE(created_at)` / `JSON_EXTRACT` aggregations in `AnalyticsService`.
- **P-8 (indexes)** — Missing for hot paths: `wallet_transactions(wallet_id,order_id,status,type)` (financial cron/refund lookup), `wallet_transactions(wallet_id,created_at)`, `searches(created_at)` & `(query)`, `user_notifications(user_id,read_at)`. Note `order_items.unit_price/total_price` are `decimal(15,2)` while wallets are `decimal(20,0)` — scale mismatch invites rounding drift.

---

## 🟡 4. Architecture, SOLID & DRY

- **A-1** — **All 6 enums in `app/Enums/**` are 100% unused dead code** (0 references); code uses model string constants. Adopt or delete.
- **A-2** — **Two gateway abstractions** are actually distinct subsystems: `Services/Gateways/*` = external product/identity **sync** (used by `SyncKavirProducts`, `DebugController`); `Services/Payment/Gateways/*` = payment. Not redundant, but the identical naming caused the C-1 confusion — rename `Services/Gateways` → `Services/ExternalSync`. (`DebugController` referencing a prod factory is its own smell.)
- **A-3** — `OrderResource::toArray` is a ~130-line god-method mixing auth (`Auth::user()->hasRole()`), URL generation, i18n fallback, and the `items_summary` heuristic; least-cohesive class and the main N+1 surface. Move to a presenter.
- **A-4** — `WalletTransactionEngine::process()` opens a **nested `DB::transaction` + re-locks the wallet per leg**; `ProcessWeighbridgeResultAction` calls it ~10× inside its own transaction, re-locking the same wallet repeatedly. Add a batch method that locks each wallet once.
- **A-5** — `WalletTransactionResource` hardcodes Persian status/type strings, **duplicating** the model's `getStatusLabel/Color` and violating the `__()` rule. *`WalletTransactionResource.php:17-31`.*
- **A-6** — **CLAUDE.md ↔ code drift:** CLAUDE.md claims docs O and Q are emitted by their actions — they are **not** (only holds released). Doc must be corrected.
- **A-7** — Magic numbers that should be config: RFM tiers (`CalculateCustomerRfm.php:104-108`), OTP TTLs hardcoded in `BeneficiaryController.php:107`/`AccountSecurityController.php:138` (while `RequestOtpAction` is configurable), invoice validity `addDays(2)` (`InvoiceDataBuilder.php:83`), deposit limits hardcoded in requests.
- **A-8** — Repository pattern applied to only 4 models (Order/Product/Ticket/TicketReply); the rest use Eloquent directly. Commit or remove.
- **A-9** — Validation-in-DTO instead of FormRequest for tickets (`TicketDTO::fromRequest`), and several inline `$request->validate()` in Auth/Admin controllers — breaks the Controller→FormRequest→DTO convention; the weak reply file rules are a consequence.

---

## 🔵 5. Admin Panel Audit

| # | Endpoint | Finding | Sev |
|---|---|---|---|
| 1 | `POST /admin/orders/{order}/status` | State-machine bypass (C-8) | 🔴 |
| 2 | `GET /admin/users?is_active=` | SQL crash, column missing (C-11) | 🔴 |
| 3 | `POST /admin/users/{user}/toggle-status`,`/note` | 501 stubs — user mgmt non-functional | 🟠 |
| 4 | `GET /admin/orders` (`index`) | **Orphaned `orWhere`** — combining `order_number`+`status` silently drops the status filter (`AdminOrderController.php:44-46`) | 🔴-logic |
| 5 | `GET /admin/orders/{order}/history` | N+1 + null-pointer (P-5) | 🟠 |
| 6 | `AdminFinancial*` / `AdminSearch*` lists | Raw Eloquent models returned — leaks every column incl. `meta` (SHEBA, holder, settlement internals) | 🟠 |
| 7 | `AdminWalletController::changeStatus`, `WalletController::requestWithdrawal`, `UserBankAccountController` | Leak raw `$e->getMessage()`; one returns `['debug'=>…]` to the client | 🟠 |
| 8 | `changeStatus` + `submitSystemMoadianInvoice` (releases WVAT hold) | **No audit-trail / `causedBy(admin)` activity log** on money/state actions | 🟠 |

Plus: `AdminActivityLogController` builds a class name from user input (`'App\\Models\\'.ucfirst($request->subject_type)`) — fragile; use an allow-list. Admin authorization is a single coarse `admin` role (Laratrust `role:admin` works) with no granular permissions on destructive paths.

---

## 🟢 6. Best-Practice Recommendations

1. **Harden auth throttling** — `verify-code`, `password/check`, `change-password` have no dedicated limiter (only global 60/min). Add `throttle:5,1`; key the `sms` limiter by mobile too.
2. **CSPRNG** for OTP (`random_int`) and remove `rand()` in `ShaparakGateway`.
3. **Mask PII in responses** — `UserBankAccountResource` returns full SHEBA + 16-digit card; mask to last-4. `OrderResource` exposes beneficiary `national_id` to suppliers — gate it (`national_id` only when `!$isSupplier`).
4. **Sanitize download filenames** — `Content-Disposition` uses `getClientOriginalName()` (header-injection/content-spoof). *`WalletController.php:183`.*
5. **Money as integer Rials** end-to-end (currently `float` into `decimal:2`) to avoid precision drift.
6. **Wrap all `orWhere` groups** in nested closures; add a review rule.
7. **Return Resources, never raw models**, from every admin/list endpoint.
8. **Audit-trail every admin money/state action** (`activity()->causedBy()`).
9. **Move financial limits/TTLs to `config`** (deposit caps, reservation threshold = gateway limit, OTP TTLs, RFM tiers).
10. **Translate all user-facing strings** (`__()`), incl. the literal-key bug `return success([], 'auth.forget_password_code_sent_successfully')` (`AuthController.php:218`) and hardcoded Persian in `CanSeeTicket`/`DebitWalletAction`/`ProcessTransactionAction`.

---

## 7. What's genuinely good (keep)

- Settlement **formulas are correct** across all 4 variants; consultant derivation is robust.
- `WalletTransactionEngine` / direct-purchase / reservation actions: `DB::transaction` + `lockForUpdate` + idempotency re-checked **under** the lock; refunds reverse **both** ledger legs (no money-minting).
- `PlaceOrderAction` (single locked transaction, decomposed). Policies + admin-bypass `before()`. `address_id` ownership scoping. IDOR ownership checks **are present and correct** on notifications, addresses, eduix, job-experiences, wishlists, my-items, wallet receipts. Sensitive `User` fields hidden. Document-numbering format. `ShouldQueue` on listeners.

---

## 8. Suggested remediation order

1. **Stop the bleeding (small, high-impact):** C-2 (redirect), C-8 (admin status bypass), C-3 (idempotency gate), admin #4 (orWhere), P-3/P-4 (broken stats / wasted load).
2. **Payments & money safety:** C-1 + A-2 (gateway contract, Passargad driver or fence-off) + C-10 (settle status guard); C-4 (withdrawal hold/approval); C-5 (verified deposit amount).
3. **Authorization:** C-6 (supplier role gate + unfill flags); C-7 (buy-for-others consent); C-9 (OTP CSPRNG) + best-practice #1.
4. **Spec conformance:** Doc R freight settlement; document letter renumber (K=waybill); generate O/Q; VAT hold/available reconciliation; surveys S/T; agreements (5 slugs + seeder); reservation threshold.
5. **Ticketing:** build the admin side, SLA, notifications, attachments, order/transaction links.
6. **Performance/architecture:** P-1/P-2 (pricing), indexes (P-8), dead enums (A-1), god-resource (A-3), config/i18n cleanup.

*End of report. Analysis only — no files modified. Findings produced by 6 parallel sub-agents cross-checking code against `پروسه خرید (2).md`, plus the lead manual trace.*
