# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

EliteZone Backend — a Laravel 10 API for a B2B construction materials marketplace (rebar, brick, etc.). The codebase lives entirely inside the `api/` directory. It handles user authentication (OTP-based), product catalog with dynamic pricing, shopping cart, order lifecycle (including reservation flow), wallet/financial operations, ticketing, and an admin panel.

## Common Commands

All commands must be run from the `api/` directory:

```bash
cd api

# Install dependencies
composer install

# Run migrations
php artisan migrate

# Run all tests
php artisan test

# Run a single test file
php artisan test --filter=OrderTest

# Run PHPUnit directly
./vendor/bin/phpunit tests/Unit/SomeTest.php

# Lint with Pint
./vendor/bin/pint

# Clear all caches (config, route, view)
php artisan optimize:clear

# Generate API docs (Scramble)
php artisan scramble:export

# Regenerate IDE helper files (after adding models or migrations)
composer ide-helper
# This runs three artisan commands:
#   ide-helper:generate  → _ide_helper.php       (facade type hints)
#   ide-helper:models    → _ide_helper_models.php (Eloquent @property docblocks)
#   ide-helper:meta      → .phpstorm.meta.php     (container resolution)
```

## Directory Structure

### Http/Requests — Domain Subfolders

`app/Http/Requests/` is organized into domain subfolders (43 files moved, commit `2b7cabe`):

```
Http/Requests/
  Auth/        — StoreUserRequest, ChangeMobileRequest, VerifyEmailRequest, StorePasswordRequest, UpdateMobileRequest
  Cart/        — AddToCartRequest, UpdateCartItemRequest, UpdateCartFiltersRequest, SavePurchaseParametersRequest, UpdateCustomerTypeRequest
  Order/       — StoreOrderRequest, PayFullOrderRequest, PayHoldFeeRequest, AddWaybillRequest, SubmitWeighbridgeRequest,
                 UploadOrderReceiptRequest, StoreOrderSurveyRequest, FinalizeOrderInvoiceRequest, OrderPriceRequest,
                 SubmitMoadianInvoiceRequest  ← shared by Doc Q (consultant) and Doc O (supplier) endpoints
  Product/     — ProductIndexRequest, ProductVariantFilterRequest, ProductVariantPriceRequest, SearchRequest, ToggleStockSubscriptionRequest
  Profile/     — UpdateRealProfileRequest, UpdateLegalProfileRequest, StoreUserTypeRequest, StoreEngineerProfileRequest,
                 StoreContractorProfileRequest, StoreEmployerProfileRequest, StoreSupplierProfileRequest,
                 StoreJobExperienceRequest, StoreListopherRequest, InviteConsultantRequest, RejectInvitationRequest,
                 StoreUserProfileRequest, UpdateUserProfileRequest
  Financial/   — WalletDepositRequest, WalletWithdrawalRequest, StoreDepositReceiptRequest, StoreUserBankAccountRequest
  Address/     — StoreAddressRequest, UpdateAddressRequest
  Wishlist/    — StoreWishlistRequest, UpdateWishlistRequest
  Shared/      — StoreQuestionRequest
  Admin/       — (already existed) OrderFilterRequest, UserSearchRequest, FinancialFilterRequest, GeneralSearchRequest, UpdateTransactionStatusRequest
  Payment/     — (already existed) PayWithWalletRequest
  Podium/      — (already existed) BatchSettlementRequest, GetBalanceRequest, SettlementInquiryRequest, SingleSettlementRequest
```

When adding a new FormRequest, place it in the matching domain subfolder and use the namespace `App\Http\Requests\<Domain>`.

### Http/Resources — Domain Subfolders

`app/Http/Resources/` is organized into domain subfolders (55 files moved, commit `1873264`):

```
Http/Resources/
  Cart/      — CartResource, CartItemResource, PurchaseParameterGroupResource, PurchaseParameterValueResource
  Order/     — OrderResource, OrderItemResource, OrderSurveyResource, OrderUnitResource, ShippingMethodResource
  Product/   — ProductResource, ProductDetailResource, ProductCategoryResource, ProductVariantResource,
               ProductVariantAttributeResource, ProductImageResource, ProductVideoResource,
               AttributeResource, AttributeTypeResource, AttributeValueResource,
               OfferResource, InventoryPredictionResource, SubscribedVariantResource,
               SearchResource, PriceFilterResource, PriceFilterCategoryResource, PriceFilterTypeResource
  Supplier/  — FactoryResource, FactoryLocationResource, SupplierProfileResource, SupplierCategoryResource
  Profile/   — UserResource, UserProfileResource, UserTypeResource, BadgeResource, CustomerTypeResource,
               EngineerProfileResourse, ContractorProfileResource, EmployerProfileResource,
               ConsultantRelationResource, JobExperienceResource, AddressResource, EduixRecordResource
  Financial/ — WalletResource, WalletTransactionResource, UserBankAccountResource
  Ticket/    — TicketResource, TicketReplyResource, TicketFileResource, DepartmentResource
  Wishlist/  — WishlistResource, VariantInWishlistResource
  Shared/    — DashboardResource, ActivityLogResource, CreatedResource, UserMessageResource
```

**Cross-domain resource references** — when a resource in one domain instantiates a resource from another domain inline (e.g. `new ProductVariantResource(...)`), it must have an explicit `use` import for the foreign class. Current cross-domain imports already added:
- `Cart/CartItemResource` → `Product\ProductVariantResource`, `Supplier\FactoryResource`, `Supplier\FactoryLocationResource`, `Order\ShippingMethodResource`
- `Cart/CartResource` → `Profile\CustomerTypeResource`, `Profile\BadgeResource`
- `Order/OrderItemResource` → `Product\ProductVariantResource`, `Profile\UserResource`
- `Profile/UserResource` → `Supplier\SupplierProfileResource`

When adding a new Resource, place it in the matching domain subfolder, use the namespace `App\Http\Resources\<Domain>`, extend `BaseJsonResource` (not `JsonResource` directly), add `@mixin \App\Models\YourModel` in the class docblock so Intelephense resolves `$this->` property accesses without PHP0416 false-positives, and add explicit `use` imports for any sibling resource from a different domain.

```php
use App\Http\Resources\BaseJsonResource;  // ← always, not JsonResource

class MyNewResource extends BaseJsonResource { ... }
```

`BaseJsonResource` (`app/Http/Resources/BaseJsonResource.php`) declares an explicit `__construct(mixed $resource)` so that Intelephense resolves `new FooResource($model)` calls without raising PHP0441 false-positives.

### Http/Controllers — Subfolder Layout

All controllers now live under `app/Http/Controllers/Api/` (commit `d5ab517`):

```
Controllers/
  Controller.php          — base class (stays at root)
  PaymentController.php   — payment gateway callbacks (stays flat, public)
  ProductController.php, ProductCategoryController.php, FactoryController.php,
  SearchController.php, CustomerTypeController.php, PriceFilterController.php,
  PurchaseParameterController.php, ParameterDetectionController.php
                          — public-facing endpoints (stay flat)
  Api/
    AuthController.php    — authentication (stays at Api/ root)
    ListopherController.php, QuestionController.php, DebugController.php
    Admin/  — AdminOrderController, AdminUserController, AdminWalletController,
              AdminFinancialController, AdminActivityLogController,
              AdminSearchController, AdminTicketController, OrderWeighbridgeController
    Data/   — AnalyticsController, InventoryController
    Podium/ — PodiumSettlementController, PodiumBalanceController,
              PodiumBatchSettlementController, PodiumSettlementInquiryController,
              IdentityInquirySc2Controller, MobileNidMatchController
    Ticket/ — TicketController, TicketReplyController
    User/   — ProfileController (profile data: name, legal info, role selection),
              AccountSecurityController (password, mobile change, email change),
              DashboardController, CartController,
              CheckoutController, OrderController, OrderPaymentController,
              OrderActionController, OrderInvoiceController, OrderSurveyController,
              WalletController, UserBankAccountController, AddressController,
              WishlistController, StockSubscriptionController, MyItemsController,
              ConsultantRelationController, BeneficiaryController,
              EngineerController, ContractorController, EmployerController,
              SupplierController, SupplierOrderController, NotificationController,
              ProfileSearchController, JobExperienceController, EduixController,
              UserBankAccountController
    Payment/ — WalletPaymentController
```

**Important**: Any controller moved out of the flat `App\Http\Controllers` namespace **must** add `use App\Http\Controllers\Controller;` explicitly — it cannot rely on same-namespace resolution anymore.

### DataTransferObjects — Domain Subfolders

`app/DataTransferObjects/` is organized into domain subfolders (commit `798a2dc`):

```
DataTransferObjects/
  Order/               — PlaceOrderData
  Pricing/             — CartLinePricingResult, CartPricingResult,
                         PriceCalculationResult, PricingContextData  (already grouped)
  Product/             — ProductFilterData
  Ticket/              — TicketDTO, TicketReplyDTO
```

> **`BaseDTO.php` was deleted** (commit `26b2c66`) — it was an abstract base class with `validateAndMake()` that zero DTOs ever extended. All DTOs already used the readonly-constructor pattern independently.

When adding a new DTO, place it in the matching domain subfolder and use the namespace `App\DataTransferObjects\<Domain>`. **Always** use the readonly-constructor pattern.

### Actions

`app/Actions/` contains single-responsibility injectable business logic units:

```
Actions/
  Auth/        — RequestOtpAction, VerifyIdentityWithPodiumAction, SyncIdentityToProfileAction
  Cart/        — AddToCartAction, UpdateCartItemAction, RemoveCartItemAction, SyncCartFiltersAction
  Financial/   — RequestWithdrawalAction, DebitWalletAction, FundWalletAction,
                 PayHoldFeeAction, ProcessPreScalePaymentAction,
                 ProcessReservationPaymentAction, ProcessReservationCompletionAction,
                 ProcessWeighbridgeResultAction, ProcessPaymentCallbackAction,
                 ReleaseConsultantHoldAction,    ← Doc Q: releases WCT+WVAT holds
                 RecordSupplierMoadianInvoiceAction, ← Doc O: releases WVAT hold
                 AttachReceiptToDepositAction, DeleteReceiptAction
  Order/       — PlaceOrderAction, RefundOrderToWalletAction, CancelOrderAction,
                 FinalizeInvoiceAction, ProcessFullOrderPaymentAction
                 PayOrderResult  ← readonly DTO (scenario, documents[], messageKey)
  Product/
  Profile/     — SetUserTypeAction, SignAgreementAction, SyncUserRolesAction
```

`ProcessFullOrderPaymentAction` — orchestrates the three full-payment scenarios
(direct purchase, reservation, completion) and returns a `PayOrderResult` DTO.
`OrderPaymentController::payFullOrder()` only injects this one action now.

`ProcessPaymentCallbackAction` — verifies the gateway receipt and settles the
wallet (DB::transaction + lockForUpdate + WalletFunded dispatch). `PaymentController`
delegates entirely to this action; it holds no DB or financial logic itself.

`FinalizeInvoiceAction` — atomically updates `final_invoice_ref` and holds the
consultant fee (TYPE_FEE_HOLD WalletTransaction + balance_hold increment) inside a
single DB::transaction. `OrderActionController::finalizeInvoice()` delegates to it.

`ReleaseConsultantHoldAction` — Doc Q post-weighbridge: when the consultant submits
their Moadian invoice, releases WCT hold→available (consultant net) and WVAT
hold→available (consultant VAT). Idempotent via `consultant_moadian_ref` guard.
Route: `POST /profile/orders/{order}/consultant-moadian-invoice` (OrderActionController).

`RecordSupplierMoadianInvoiceAction` — Doc O post-weighbridge: when the supplier
submits their Moadian invoice, releases WVAT hold→available (supplier VAT portion of
PTSM). Idempotent via `supplier_moadian_ref` guard.
Route: `POST /profile/orders/{order}/supplier-moadian-invoice` (SupplierOrderController).

`RecordSystemMoadianInvoiceAction` — Doc P post-weighbridge: when an admin issues
the platform's (Saze) Moadian invoice to the buyer, releases `sazeVat` from
WVAT hold→available. `sazeVat` is re-derived at release time from the stored
POOM/PTSM/consultantGross snapshots and `vat_rate`. Idempotent via
`system_moadian_ref` guard; pre-condition: `weighing_completed_at ≠ null`.
Route: `POST /admin/orders/{order}/system-moadian-invoice` (AdminOrderController).

### Services (domain helpers)

```
Services/
  Cart/
    CartResolver                   — resolves the request's Cart (user or guest session)
                                     and syncs customer_type_id / badge_id;
                                     activeCode() is the single source of truth for the
                                     active price-filter category code (middleware → role → guest)
    CartResponseBuilder            — loads display relations, runs pricing pipeline,
                                     builds the standard { cart, totals } response
    CartParameterSyncService       — syncs purchase parameters to cart (used by
                                     PurchaseParameterController); delegates detection
                                     to PriceFilterCategoryDetectorService
    PriceFilterCategoryDetectorService — pure read service: maps a list of
                                     PurchaseParameterValue IDs to a PriceFilterCategory;
                                     used by both CartParameterSyncService and
                                     ParameterDetectionController
  Invoice/
    InvoiceGenerator     — orchestrator; getViewName() maps all 19+ doc codes to
                           Blade views; createInvoiceRecord() stores Invoice model;
                           generate(order, docType, 'save'|'download'|'stream')
    InvoiceDataBuilder   — Blade-view data prep (line items, totals, buyer info)
    InvoicePdfRenderer   — PDF rendering + Storage::disk('public') write
  Financial/
    WalletTransactionEngine    — multi-wallet operations for all 5 wallet codes:
                                 WO (buyer), WS (supplier), WSSG (Saze/system),
                                 WCT (consultant), WVAT (VAT escrow).
                                 resolveUserId() maps each code to a user_id;
                                 WALLET_SUPPLIER always requires explicit targetUserId
                                 (supplier lives on order items, not the order row).
    SettlementCalculatorService — POO/PTS commission math with 4 formula variants
                                 (+/-TC controls commission on Trans, +/-VAT controls
                                 VAT on Trans). Flags are read from supplier_profiles
                                 and snapshotted to the order at payment time.
  Pricing/                       — pipeline (SetBasePrice → ApplyPriceFilters → ApplyVariantDiscount → ApplyVat)
```

When a controller method grows past ~30 lines of business logic, extract it
into an Action (write side-effects) or a Service (read/composition).

**`CartResolver::activeCode()`** is the canonical way to get the active pricing
code anywhere in the codebase. It checks (in order): middleware-bound container
value → authenticated user's role via `PricingRoleResolver` → `'employer_without_consultant'`
guest default. `ProductController` was previously duplicating this logic in a
private `resolveActiveCode()` method — that was removed in favour of `CartResolver`.

### Enums — Domain Subfolders

`app/Enums/` is organized into domain subfolders (commit `874e7ed`):

```
Enums/
  Order/   — OrderStatus, PaymentStatus
  Wallet/  — WalletTransactionStatus, WalletTransactionType
  User/    — UserRoleSlug
  Survey/  — SurveyStatus
```

Backed PHP 8.1 enums for all status/type values. **Note:** the codebase currently uses Model string constants (`Order::STATUS_CANCELLED`) rather than these enum values — future refactoring should switch to enum references for type safety.

### Support

`app/Support/PhoneNumber` — centralized Iranian mobile normalizer. Use `PhoneNumber::to09($mobile)` everywhere. **Never** write a local `mobileTo09()` helper.

---

## Architecture

### Request Flow Pattern

The intended pattern is **Controller → FormRequest → DTO → Action → Model**, though not all modules follow it consistently:

```
Request → FormRequest (validation) → Controller
  → DTO::fromRequest() (data mapping)
  → Action::execute() (business logic, DB transactions)
  → Model (persistence)
  → Resource (response serialization)
```

**Modules that follow this well:** Cart (AddToCartAction, UpdateCartItemAction), Order placement (PlaceOrderData → PlaceOrderAction), Tickets (TicketDTO → TicketService), Auth (RequestOtpAction, VerifyIdentityWithPodiumAction), Profile (SetUserTypeAction), Financial (RequestWithdrawalAction).

**All payment flows follow the pattern:**
- `payFullOrder` → `ProcessFullOrderPaymentAction` (selects scenario, delegates to sub-actions)
- `payHoldFee` → `PayHoldFeeAction` (DB::transaction + lockForUpdate + idempotency guard)
- Gateway callback → `ProcessPaymentCallbackAction` (verify + settle in one transaction)

### DTO Style — Readonly Constructor (Only Pattern)

All DTOs use PHP 8.1 readonly properties. `BaseDTO` was deleted (commit `26b2c66`) — do not reintroduce it.

```php
class PlaceOrderData {
    public function __construct(
        public readonly ?int $beneficiaryUserId,
        public readonly int  $addressId,
        // ...
    ) {}

    public static function fromRequest(StoreOrderRequest $request): self
    {
        return new self(
            beneficiaryUserId: $request->integer('beneficiary_user_id') ?: null,
            addressId:         $request->integer('address_id'),
        );
    }
}
```

### Response Helpers

Global helper functions `success()` and `error()` are defined in `app/Http/Helper/function.php` (autoloaded via composer). They delegate to Response macros registered in `ResponseServiceProvider`:

```php
return success($data, 'message.key');           // → { status: true, message: "...", data: ... }
return error('message.key', $errors, 400);      // → { status: false, message: "...", errors: [...] }
return created($data, 'message.key');           // → 201 response
```

### Model Constants Convention

Models define column names as class constants and use them everywhere instead of raw strings:

```php
class Order extends Model {
    public const STATUS = 'status';
    public const PLACER_USER_ID = 'placer_user_id';
    // ...used as: $order->{Order::STATUS}
}
```

### Financial Configuration (`config/financial.php`)

All financial constants live in `config/financial.php` — **never hardcode amounts**. Always read via `config()`:

| Key | Env var | Default | Meaning |
|-----|---------|---------|---------|
| `financial.reservation_threshold` | `RESERVATION_THRESHOLD_RIALS` | `1_950_000_000` | Orders above this require reservation flow (195M Tomans) |
| `financial.hold_fee` | `HOLD_FEE_RIALS` | `50_000_000` | Fee to extend a reservation (5M Tomans) |
| `financial.system_user_id` | `FINANCIAL_SYSTEM_USER_ID` | `1` | User ID of the platform's central "Saze" (WSSG) wallet |
| `financial.vat_user_id` | `FINANCIAL_VAT_USER_ID` | *(falls back to system_user_id)* | User ID of the dedicated VAT escrow (WVAT) wallet; set in .env once a separate VAT user is created |
| `financial.pending_order_ttl_hours` | `PENDING_ORDER_TTL_HOURS` | `2` | Hours before an unpaid PENDING order auto-cancels (`CheckReservationExpiryJob`) |
| `financial.reservation_validity_hours` | `RESERVATION_VALIDITY_HOURS` | `2` | Hours a paid reservation stays valid before it must be completed/extended |
| `financial.hold_fee_extension_hours` | `HOLD_FEE_EXTENSION_HOURS` | `24` | Hours that paying the hold fee extends a reservation (`PayHoldFeeAction::extensionHours()`) |

> **Never hardcode time windows in Actions.** Read them via `config('financial.*')`
> — e.g. `now()->addHours((int) config('financial.reservation_validity_hours', 2))`,
> or `PayHoldFeeAction::extensionHours()`. The 2h / 24h literals were removed from
> `PlaceOrderAction`, `ProcessFullOrderPaymentAction`, and `PayHoldFeeAction`.

```php
// ✅ correct
config('financial.reservation_threshold')
PayHoldFeeAction::fee()   // wraps config('financial.hold_fee')

// ❌ wrong — never hardcode
1_950_000_000
self::FEE_RIALS   // deprecated constant in PayHoldFeeAction, kept for BC only
```

### Shipping — Shipments (multi-origin + multi-destination freight)

A cart/order can hold items from multiple factories going to multiple destinations. The
unit of freight is a **Shipment**: one origin (factory location) → one destination (address),
with its own cost and lifecycle status. An order has one or more shipments;
`orders.shipping_cost` (= `Trans`) is the sum of all shipment costs.

- **`shipments` table**: `(order_id, factory_id/factory_location_id origin, address_id +
  destination_province_id/destination_city_id, weight, shipping_cost, status)`. `Order
  hasMany shipments`; each `order_items.shipment_id` points to its shipment.
- **Per-line destination**: `cart_items.address_id` (nullable) holds the chosen destination
  for that line (set via Add/Update cart, validated to belong to the user). At placement a
  line's destination = `cart_item.address_id` ?? the order's primary `address_id`.
- **`shipping_rates` table**: `(factory_location_id origin, destination_province_id,
  destination_city_id nullable, flat_cost, per_kg_cost, is_active)`. City-specific row wins;
  province-level row (`destination_city_id = null`) is the fallback. **No matching row → that
  shipment costs 0** (graceful degradation until an admin enters rates).
- **`ShippingCostCalculator::quote($lines)`** — each line carries `factory_id`,
  `factory_location_id`, `address_id`, `destination_province_id`, `destination_city_id`,
  `weight`. It groups lines into shipments by `(factory_location_id, address_id)`, prices each
  `flat + per_kg × weight`, allocates the cost back onto lines by weight (last line absorbs the
  rounding remainder), and returns a `ShippingQuote` (`total`, `perLine`, `shipments[]` of
  `ShipmentGroup`). Single-destination orders are just the case where every line shares one
  address → one shipment per origin.
- **`PlaceOrderAction`** resolves each line's destination, calls `quote()`, creates one
  `Shipment` row per `ShipmentGroup`, links each `OrderItem` to its shipment, and stores
  `orders.shipping_cost = total` + per-line `order_items.shipping_cost` (+ origin
  `factory_id`/`factory_location_id`).
- **Relation naming gotcha**: `OrderItem`/`Shipment` use `originFactory()` /
  `originFactoryLocation()` — never name a relation `factory()`, it collides with the
  `HasFactory` trait's static `factory()` (this broke 25 tests once).
- Still out of scope: **splitting into one Order per factory** (independent settlement), and
  per-shipment delivery tracking is scaffolded (`shipments.status`) but not driven yet. The
  financial settlement actions still treat an order as a single product line
  (`items->first()`), so true multi-product-per-order settlement remains a future change.

### Financial — POO/PTS Formula Variants

`SettlementCalculatorService` supports four formula variants per order, controlled
by two boolean flags snapshotted at payment time:

| Flag | Meaning |
|------|---------|
| `trans_commission` (`+TC`) | commission percentage `%OW` applies to Trans (shipping cost) |
| `trans_vat` (`+VAT`) | VAT applies to the Trans portion |

The flags are stored per-supplier in `supplier_profiles.trans_commission` /
`supplier_profiles.trans_vat` (both default `true`; editable by super-admin) and
snapshotted onto `orders.trans_commission` / `orders.trans_vat` at payment time so
they are immutable for the life of the order.

`ProcessWeighbridgeResultAction` reads the snapshot from the locked order and passes
the flags to both `calculatePOO()` and `calculatePTS()`. POOM and PTSM (the
post-weighbridge recalculations) use the same flags but with the **billing weight**,
which is `min(actualWeight, weight_before_scale)` — never the raw `actualWeight`.

**Spec invariant — final invoice ≤ initial invoice.** The buyer is never billed for
more than the ordered weight, so over-delivery (`actualWeight > WBS`) is recorded
physically (`weight_after_scale` + stock) but clamped for billing. There is **no
"surplus" branch** that charges the buyer extra; the only non-equal outcome is a
deficit (under-delivery → buyer refunded POO − POOM).

**Consultant gross (PTC)** is the `%CO` slice of the commission pool:
`(POOM − PTSM) × %CO / (%CO + %SSG)`. Deriving it from POOM−PTSM inherits the same
±TC/±VAT handling as POO/PTS instead of an ad-hoc formula. The lifetime cap
(`consultant_lifetime_cap_multiplier`, default 2) is enforced in
`applyConsultantLifetimeCap()` but is currently inert until the per-consultant
badge-purchase amount is modelled (`consultantLifetimeCeiling()` returns null).

Badge-based commission splits (`getCommissionRates()`) — canonical codes are
`gold` / `silver` / `bronze` / `pioneer` (`golden` accepted as an alias):

| Badge | `%OW` | `%CO` | `%SSG` |
|-------|-------|-------|--------|
| gold | 40% | 37.5% | 22.5% |
| silver | 40% | 32.5% | 27.5% |
| bronze | 40% | 30.0% | 30.0% |
| pioneer / default | 40% | 25.0% | 35.0% |

### Financial — Weighbridge Post-Flow (Doc Q & Doc O)

After the weighbridge result is processed, two VAT-related holds remain open until
documents are submitted:

**Doc Q — Consultant Moadian Invoice** (submitted by consultant)
- At weighbridge: `consultantNet → WCT balance_hold`, `consultantVat → WVAT balance_hold`
- VAT split: `vatMultiplier = (vat%/100) / (1 + vat%/100)`; `consultantVat = gross × vatMultiplier`
- On submission: `ReleaseConsultantHoldAction` releases both holds to available
- Order fields set: `consultant_moadian_ref`, `consultant_hold_released_at`
- Authorization: only `order.beneficiary_user_id` (the consultant) can call this

**Doc O — Supplier Moadian Invoice** (submitted by supplier)
- At weighbridge: `supplierVat = PTSM × vatMultiplier → WVAT balance_hold`
- On submission: `RecordSupplierMoadianInvoiceAction` releases the VAT hold to available
- Order fields set: `supplier_moadian_ref`, `supplier_hold_released_at`
- Authorization: `OrderPolicy::manageAsSupplier`

**Doc P — System (Saze) Moadian Invoice** (submitted by admin)
- At weighbridge: `sazeVat = sazeGross × vatMultiplier → WVAT balance_hold`
  where `sazeGross = (POOM − PTSM) − consultantGross`
- On submission: `RecordSystemMoadianInvoiceAction` derives `sazeVat` from stored
  POOM/PTSM/consultantGross snapshots and releases the WVAT hold to available
- Order fields set: `system_moadian_ref`, `system_hold_released_at`
- Authorization: admin only (`POST /admin/orders/{order}/system-moadian-invoice`)

**Weighbridge deficit — insufficient supplier balance**
If at weighbridge time the supplier's wallet balance < `PTS − PTSM` (reclaimAmount):
- Buyer refund is credited in `HOLD` (not AVAILABLE) to `WO`
- `order.supplier_debt_pending = true`, `order.supplier_debt_amount = reclaimAmount`
- Supplier receives an SMS to top up their wallet (`sms.supplier_debt_notification`)
- `CheckSupplierBalancesJob` (cron) polls orders with `supplier_debt_pending = true`:
  when balance ≥ debt, it locks order + wallets, reclaims the debt, finds the specific
  hold WalletTransaction (by `order_id/type=deposit/status=hold`), releases it to
  AVAILABLE, notifies the buyer via SMS (`sms.buyer_refund_released`), and clears
  the debt flags.

**Order model constants for this flow:**
```php
Order::TRANS_COMMISSION            // bool snapshot of supplier formula flag
Order::TRANS_VAT                   // bool snapshot of supplier formula flag
Order::CONSULTANT_MOADIAN_REF      // string|null — set when Doc Q submitted
Order::CONSULTANT_HOLD_RELEASED_AT // datetime|null
Order::SUPPLIER_MOADIAN_REF        // string|null — set when Doc O submitted
Order::SUPPLIER_HOLD_RELEASED_AT   // datetime|null
Order::SUPPLIER_DEBT_PENDING       // bool — true while deficit awaits supplier top-up
Order::SUPPLIER_DEBT_AMOUNT        // decimal — Rials owed by supplier
Order::SYSTEM_MOADIAN_REF          // string|null — set when Doc P submitted (admin)
Order::SYSTEM_HOLD_RELEASED_AT     // datetime|null
```

### Document Generation — Which Action Emits Which Doc

Every `InvoiceGenerator::generate()` call is triggered from inside a `DB::transaction`
so that PDF failure rolls back the financial entry too. All generation uses `'save'`
mode (file stored on disk, `Invoice` DB record created).

| Doc | Code | Triggered by | Action |
|-----|------|--------------|--------|
| A | `DOC_RECEIPT_OWNER` | Direct purchase | `ProcessPreScalePaymentAction` |
| B | `DOC_REPORT_SUPPLIER` | Direct purchase | `ProcessPreScalePaymentAction` |
| C | `DOC_LOADING_ORDER` | Direct purchase | `ProcessPreScalePaymentAction` |
| D | `DOC_CONSULTANT_REPORT` | Direct purchase + has beneficiary | `ProcessPreScalePaymentAction` |
| E | `DOC_RESERVE_RECEIPT` | Reservation start | `ProcessReservationPaymentAction` |
| F | `DOC_RESERVE_REPORT` | Reservation start | `ProcessReservationPaymentAction` |
| G | `DOC_FINAL_RECEIPT_OWNER` | Reservation completion | `ProcessReservationCompletionAction` |
| H | `DOC_FINAL_REPORT_SUPPLIER` | Reservation completion | `ProcessReservationCompletionAction` |
| i | `DOC_REFUND_RECEIPT` | Cancellation (paid/reserved) | `CancelOrderAction` |
| j | `DOC_CANCEL_REPORT` | Cancellation (paid/reserved) | `CancelOrderAction` |
| I | `DOC_EXIT_PERMIT` | Weighbridge (equal/deficit) | `ProcessWeighbridgeResultAction` |
| J | `DOC_SUPPLEMENTARY_RECEIPT` | *(not emitted — surplus path removed per spec)* | — |
| K | `DOC_SUPPLEMENTARY_REPORT` | *(not emitted — surplus path removed per spec)* | — |
| LO | `DOC_MODIFIED_INVOICE_OWNER` | Weighbridge deficit | `ProcessWeighbridgeResultAction` |
| LS | `DOC_MODIFIED_INVOICE_SUPPLIER` | Weighbridge deficit | `ProcessWeighbridgeResultAction` |
| M | `DOC_REFUND_REPORT_WEIGH` | Weighbridge deficit | `ProcessWeighbridgeResultAction` |
| O | `DOC_TAX_INVOICE_SUPPLIER` | Supplier submits Moadian invoice | `RecordSupplierMoadianInvoiceAction` |
| P | `DOC_TAX_INVOICE_BUYER` | Admin submits system Moadian invoice | `RecordSystemMoadianInvoiceAction` |
| Q | `DOC_TAX_INVOICE_CONSULTANT` | Consultant submits Moadian invoice | `ReleaseConsultantHoldAction` |

**`OrderInvoiceController::download()`** serves any of the above by slug (e.g.
`GET /profile/orders/{order}/invoices/receipt` → `DOC_RECEIPT_OWNER`). The slug→code
map is an explicit `$typeMap` array; unknown slugs return 422. Never add a
`default` fallback that silently returns a different document.

### Financial Flow (Critical Path)

The system has a wallet-based payment model. Five wallet codes are in use:

| Code | Constant | Wallet owner |
|------|----------|--------------|
| `WO` | `WALLET_OWNER` | buyer (`placer_user_id`) |
| `WS` | `WALLET_SUPPLIER` | supplier (from `order.items.first().supplier_user_id`) |
| `WSSG` | `WALLET_SAZE` | platform system (`financial.system_user_id`) |
| `WCT` | `WALLET_CONSULTANT` | consultant (`beneficiary_user_id`) |
| `WVAT` | `WALLET_VAT` | VAT escrow (`financial.vat_user_id`, falls back to system_user_id) |

Payment entry points:

1. **WalletPaymentController** — generic wallet payment for orders
2. **OrderPaymentController::payFullOrder** — handles 3 scenarios: direct purchase, reservation (>195M Rials), and completion of reserved orders; protected by `idempotency` middleware
3. **OrderPaymentController::payHoldFee** — hold fee extension; protected by `idempotency` middleware
4. **OrderActionController::submitConsultantMoadianInvoice** — Doc Q; releases WCT+WVAT holds
5. **SupplierOrderController::submitSupplierMoadianInvoice** — Doc O; releases WVAT hold
6. **AdminOrderController::submitSystemMoadianInvoice** — Doc P; releases sazeVat WVAT hold

Financial safety pattern (should be followed everywhere):
```php
DB::transaction(function () {
    $wallet = Wallet::where('id', $id)->lockForUpdate()->first();
    if ($wallet->balance < $amount) throw new InsufficientBalanceException();
    $wallet->decrement('balance', $amount);
    // record WalletTransaction
});
```

`WalletTransactionEngine::process()` always accepts an optional `$targetUserId`; pass
it explicitly whenever you know the user (e.g. supplier). `resolveUserId()` is the
fallback — it maps wallet codes to user IDs from the order or config. **Always pass
`$targetUserId` for `WALLET_SUPPLIER`** — the supplier ID is not stored on the order
row and `resolveUserId()` will throw if omitted. `DebitWalletAction` and
`FundWalletAction` are the clean Action-based entry points for simple single-wallet ops.

### Pricing Pipeline

Pricing uses a Pipeline pattern in `app/Services/Pricing/Pipes/`:
```
SetBasePrice → ApplyPriceFilters → ApplyVariantDiscount → ApplyVat
```
Orchestrated by `CartPricingCalculator` and `PriceCalculationService`. The active pricing role is resolved by `PricingRoleResolver` based on the user's role (employer, engineer, contractor, supplier) and bound into the container via `AttachActivePriceCategory` middleware.

### Route Structure

`routes/api.php` is a slim 23-line glue file that includes three domain subfiles:

```
routes/
├── api.php           — entry point (only require/include statements)
├── api/
│   ├── public.php    — payment callbacks, search, products listing,
│   │                   factories, customer-types, price-filters,
│   │                   purchase-parameters, /authenticate group
│   ├── user.php      — every auth:sanctum route: cart, checkout,
│   │                   /profile/* (orders, financial, addresses,
│   │                   wishlists, education, consultants, etc.),
│   │                   beneficiaries, listophers, /user
│   └── admin.php     — /admin/* panel + /v1/podium/* services
│                       (both behind auth:sanctum + role:admin)
└── v1/
    ├── api.php       — ticket sub-routes (included via prefix('ticket'))
    └── ticket.php
```

When adding a route, put it in the subfile that matches its middleware layer
(public vs. authenticated vs. admin). Internal `Route::prefix()` /
`Route::middleware()` groups stay inside that file. **Never** define routes
directly in `routes/api.php` — it must stay a thin glue file.

### Key Middleware

- `attach.price.category` — resolves user's pricing role and binds it to container
- `role.expert` / `role.employer` — ensures user has specific role
- `idempotency` — prevents duplicate financial operations
- `track` — logs dashboard page visits for analytics
- `can-see-ticket` — ticket ownership check

### Event-Driven Side Effects — Domain Subfolders

`app/Events/` and `app/Listeners/` are organized into domain subfolders (commit `7f0ca9f`):

```
Events/
  Cart/      — CartItemChanged
  Order/     — OrderCancelled, OrderPaid, OrderPlaced
  Financial/ — TransactionProcessed, WalletFunded
  User/      — CustomerSegmentChanged

Listeners/
  Cart/      — LogCartEvent
  Order/     — HandleSuccessfulPayment, SendOrderCancellationNotification,
               SendOrderPaymentNotification, SendOrderPlacedNotification
  Financial/ — SendTransactionNotification, SendWalletFundingNotification
  User/      — SendWinBackOffer
```

Event → Listener wiring is registered in `app/Providers/EventServiceProvider.php`.

**All listeners implement `ShouldQueue`** (commit `26b2c66`). The last synchronous listener (`SendWinBackOffer`) was queued in Sprint 1. Never add a new listener without `ShouldQueue` + `InteractsWithQueue`.

Dispatch flow:
- `OrderPlaced` → inventory deduction, notifications
- `OrderPaid` → status transitions (HandleSuccessfulPayment)
- `OrderCancelled` → stock restoration, wallet refund
- `CartItemChanged` → analytics tracking (LogCartEvent)
- `WalletFunded` / `TransactionProcessed` → financial notifications

### User Roles (Laratrust)

Users can have roles: `admin`, `engineer`, `contractor`, `employer`, `supplier`. Each role has a corresponding profile model (EngineerProfile, ContractorProfile, etc.) linked to the User. Role selection happens in `ProfileController::setUserType`.

### External Service Integrations

- **Podium** — identity verification, bank settlement, NID-mobile matching (via `PodiumClient`)
- **TSMS** — SMS OTP delivery (via `SmsService`, dispatched as `SendSmsJob`)
- **Espadana** — loading order commands for suppliers

### Jobs — Domain Subfolders

`app/Jobs/` is organized into domain subfolders (commit `d03182e`):

```
Jobs/
  Analytics/    — CalculateCustomerRfm, LogProductView, PredictInventoryNeeds
  Financial/    — CheckSupplierBalancesJob
  Notification/ — SendSmsJob
  Order/        — CheckReservationExpiryJob
```

Scheduled jobs (crons, wired in `Console/Kernel.php`):
- `Order/CheckReservationExpiryJob` — cancels expired reservations
- `Financial/CheckSupplierBalancesJob` — polls `supplier_debt_pending = true` orders;
  when supplier balance ≥ debt, reclaims it and releases buyer's HOLD refund to AVAILABLE
- `Analytics/CalculateCustomerRfm` — customer segmentation analytics

### Authorization (Policies)

`app/Policies/` contains five policies, all registered in `AuthServiceProvider`:

| Policy | Resource | Pattern |
|---|---|---|
| `OrderPolicy` | `Order` | `view`, `pay`, `cancel`, `manageAsSupplier`, `finalizeInvoice` |
| `CartPolicy` | `Cart` | `view`, `update`, `delete` — owner-only |
| `AddressPolicy` | `Address` | `view`, `update`, `delete` — owner-only |
| `WishlistPolicy` | `Wishlist` | `view`, `update`, `delete` — owner-only |
| `UserBankAccountPolicy` | `UserBankAccount` | `view`, `update`, `delete` — strict owner-only (sensitive) |

All policies share the same shape:
- `before(User, ability)` returns `true` for admins (admin bypass)
- Each ability returns `Response::allow()` / `Response::deny('Persian message')`
- Ownership is checked via the model's `USER_ID` constant

In controllers, call `$this->authorize('view', $resource);` — the Handler
catches `AuthorizationException` and returns 403 with the policy's deny message.

### Testing

`tests/Feature/` covers two main areas. All tests use the `DatabaseTransactions`
trait so the live local database is **not** mutated (per user requirement).

```
tests/
  Feature/
    Checkout/    — PlaceOrderTest (9 tests: auth, validation, cart-empty, security)
    Financial/   — PayHoldFeeActionTest, ProcessPreScalePaymentActionTest,
                   ProcessReservationPaymentActionTest, WalletDepositTest
    Policies/    — OrderPolicyTest, UserBankAccountPolicyTest,
                   CartPolicyTest, AddressPolicyTest, WishlistPolicyTest
    Pricing/     — CreatesPricingFixtures (shared trait),
                   SetBasePriceTest, ApplyPriceFiltersTest,
                   ApplyVariantDiscountTest, ApplyVatTest
  Unit/          — (empty placeholder dir — kept so phpunit.xml works)
```

**Total: 110 tests, 189 assertions (all green).** (after B1–B5 document-generation gap fixes)

Patterns to follow when adding tests:
- **Use factories**, never `User::create([...])` with raw arrays — `UserFactory`
  sets `type_id` via `firstOrCreate`; manual creation will hit a NOT-NULL error.
- **Use `OrderItem::factory()->create([...])`** for line items — the factory
  fills `product_name`, `quantity_details`, `unit_price`, `total_price`.
- **Set up the Saze system user** in `setUp()` for Action tests:
  ```php
  $sys = User::factory()->create();
  config(['financial.system_user_id' => $sys->id]);
  Wallet::create([Wallet::USER_ID => $sys->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
  ```
  The system wallet user defaults to `id=1`; in a fresh test DB that user
  doesn't exist, so create one and point config at it.
- **Set up a separate VAT user** for tests that exercise `WALLET_VAT` operations
  (e.g. `ProcessWeighbridgeResultAction`, `ReleaseConsultantHoldAction`,
  `RecordSupplierMoadianInvoiceAction`) when `financial.vat_user_id` is distinct:
  ```php
  $vat = User::factory()->create();
  config(['financial.vat_user_id' => $vat->id]);
  Wallet::create([Wallet::USER_ID => $vat->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
  ```
  If you omit this and `vat_user_id` falls back to `system_user_id`, the system
  wallet absorbs both WSSG and WVAT movements — fine for tests that don't assert
  wallet separation, but wrong for tests that verify VAT auditing.
- **Roles**: Laratrust's role attach method is `$user->addRole($role)` (NOT
  `attachRole`). Create roles with `Role::firstOrCreate(['name' => 'admin'], ...)`.
- Prefer behavioural assertions (`assertLessThan(balanceBefore, balanceAfter)`)
  over exact-amount assertions for financial flows — the POO/PTS formulas
  depend on badge rates and aren't trivially reproducible in test setup.
- **Policy tests** — use `Sanctum::actingAs($user)` + HTTP verb so the full
  middleware/policy chain runs:
  ```php
  Sanctum::actingAs($stranger);
  $this->deleteJson("/api/cart-items/{$item->id}")->assertForbidden();
  ```
- **Pricing pipe tests** — instantiate the pipe directly and call `handle()`:
  ```php
  $this->pipe->handle($ctx, fn ($c) => $c);
  $this->assertSame(1_500_000.0, $ctx->baseGeneralPrice);
  ```

### Known Test Gotchas

- **Actions that inject `InvoiceGenerator`** — `ProcessPreScalePaymentAction`,
  `ProcessReservationPaymentAction`, `CancelOrderAction`, `ProcessWeighbridgeResultAction`,
  and the three Moadian-invoice actions all call `InvoiceGenerator::generate()`. In
  feature tests that resolve these via `app()`, mock the generator **before** the
  `app()` call so the container injects the mock instead of the real implementation:
  ```php
  $this->mock(InvoiceGenerator::class)
      ->shouldReceive('generate')
      ->andReturn('test/stub.pdf');

  $this->action = app(ProcessPreScalePaymentAction::class);
  ```
  Without this, tests fail with `View [pdf.xxx] not found` because PDF Blade views
  only exist in the production environment.

- **`SetBasePriceTest`** — the pipe caches results; call `Cache::flush()` between
  assertions on the same (variant, factory, location) triple.
- **`ApplyPriceFiltersTest`** — `PriceFilter ↔ PriceFilterCategory` is
  `BelongsToMany` through `price_filter_category_items` pivot. Never set
  `price_filter_category_id` directly; use `$category->filters()->attach($filter->id)`.
- **`ProductPrice` unique constraint** — `(product_variant_id, factory_id, factory_location_id)`
  is uniquely indexed. Tests that need to simulate a price update must `update()` the
  existing row, not create a second one.

### Database Indexes

Performance-critical indexes added in `2026_05_23_120000_add_performance_indexes`:

| Table | Index | Reason |
|---|---|---|
| `orders` | `orders_status_idx` | scope queries (`current`/`delivered`/`cancelled`) |
| `orders` | `orders_payment_status_idx` | admin filtering |
| `orders` | `orders_reservation_expires_at_idx` | `CheckReservationExpiryJob` cron |
| `orders` | `orders_placer_status_idx` (composite) | "my orders" lists |
| `users` | `users_national_code_idx` | admin back-office search |

When adding queries that filter heavily on a column, check this list and
add an index in a new migration if needed. Always make migrations additive.

## Known Architectural Gotchas

### Exception Handler — `renderable()` runs before `unauthenticated()`

`app/Exceptions/Handler.php` registers a `renderable()` callback that calls `handleApiException()`. This callback fires **before** Laravel's built-in `unauthenticated()` hook, so `AuthenticationException` **must** be handled inside `handleApiException()` — not delegated to `unauthenticated()`.

```php
// CORRECT — inside handleApiException():
if ($e instanceof AuthenticationException) {
    return $this->jsonResponse('احراز هویت نشده‌اید...', 401);
}
```

Removing this branch causes every unauthenticated API request to return 500 instead of 401 (regression introduced and fixed in commit `26b2c66`).

### `FinalizeOrderInvoiceRequest` — Policy-gated Authorization

`authorize()` delegates to `OrderPolicy::finalizeInvoice`. Any change must keep the policy check — never return `true` unconditionally:

```php
public function authorize(): bool
{
    $order = $this->route('order');
    if (! $order instanceof Order) { return false; }
    return $this->user()?->can('finalizeInvoice', $order) ?? false;
}
```

### Pricing — `PriceFilter ↔ PriceFilterCategory` is Many-to-Many

The join is through the `price_filter_category_items` pivot table, NOT a direct FK column on `price_filters`. Always attach via the relationship:

```php
// ✅ correct
$category->filters()->attach($filter->id);

// ❌ wrong — column does not exist
PriceFilter::create(['price_filter_category_id' => $cat->id, ...]);
```

### `CheckSupplierBalancesJob` — scope to `supplier_debt_pending` only

This job must query **only** orders where `supplier_debt_pending = true`. Querying
all hold transactions would accidentally release consultant-commission holds (WCT)
that are waiting for Doc Q. The scoping pattern:

```php
Order::where(Order::SUPPLIER_DEBT_PENDING, true)
    ->with(['items.supplier.wallet'])
    ->get();
```

Inside the transaction, it finds the specific buyer hold via:
```php
$buyerWallet->transactions()
    ->where('order_id', $lockedOrder->id)
    ->where('type', 'deposit')
    ->where('status', FinancialCodes::STATUS_HOLD)
    ->latest()->first();
```

### `WalletTransactionEngine` — always pass `$targetUserId` for `WALLET_SUPPLIER`

The supplier user ID lives on `order.items.first().supplier_user_id`, NOT on the
order row itself. `resolveUserId()` throws a `RuntimeException` for `WALLET_SUPPLIER`
if `$targetUserId` is omitted — this is intentional to catch bugs early.

### `WalletTransactionEngine` — `meta` must be a plain array

`WalletTransaction` casts `meta` to `array`. Pass a **plain array** to `process()`
(`'meta' => ['code' => ..., 'op' => ...]`). **Never** `json_encode()` it — the cast
would double-encode it, store a JSON string, and break `meta`-based accessors
(`receipt_details`) when the row is serialized.

### Refunds must reverse EVERY leg via the engine (never mint money)

`RefundOrderToWalletAction` reverses a paid, **pre-weighbridge** order in full:

```
WS(−PTS) → WSSG(+PTS)     reverse the supplier leg first
WSSG(−POO) → WO(+POO)     then refund the buyer
```

Rules:
- Use the snapshotted **`payment_of_owner` (POO)** / **`payment_to_supplier` (PTS)** —
  **never `final_amount`**, and **never** a bare `Wallet::increment()` that credits the
  buyer without clawing the money back from WS/WSSG (the old money-minting bug).
- Always go through `WalletTransactionEngine` so every leg is an auditable
  `WalletTransaction`.
- The action **refuses** orders past the weighbridge (`weighing_completed_at !== null`):
  they carry supplier/consultant/VAT holds and must go through the return/settlement
  flow, not a simple refund.

`CheckSupplierBalancesJob` follows the same rule — the supplier-debt reclaim
(`WS → WSSG`) now runs through the engine, not raw `increment`/`decrement`.

### Cancellable states — a buyer may only self-cancel a PENDING order

`OrderActionController::cancel` allows **only `Order::STATUS_PENDING`** (no money moved).
`RESERVED`/`RESERVED_EXTENDED` auto-cancel on expiry via the system cron (never by the
user before expiry). `PROCESSING` and later are paid/settled and must go through the
dedicated return/dispute flow (مرجوعی/داوری) — **never** self-cancel, which would
trigger an incomplete refund.

### Badge codes are `gold` / `silver` / `bronze` / `pioneer`

The seeder (`database/seeders/ProductSeeder.php`) is the source of truth: the gold tier
code is **`gold`** (not `golden`). `SettlementCalculatorService::getCommissionRates()`
matches `'gold', 'golden'` (alias) so a legacy value never silently falls through to the
default rate and underpays a gold-tier consultant.

### Wallet status vocabulary — cover `hold` everywhere

The `wallet_transactions.status` column mixes two vocabularies:
`pending`/`confirmed`/`failed`/`rejected` (deposits/withdrawals) and the
order-financial escrow values `FinancialCodes::STATUS_HOLD = 'hold'` /
`STATUS_AVAILABLE = 'confirmed'`. Any new status must have a label + color in
`WalletTransaction::getStatusLabel/Color` **and** a case in the
`WalletTransactionStatus` enum — `hold` is now covered in both.

### Cross-Controller Calls are Forbidden

Never resolve one controller from inside another:

```php
// ❌ wrong — creates tight coupling, bypasses DI, breaks testability
$category = app(ParameterDetectionController::class)->detectPriceFilterCategoryFromValues($ids);

// ✅ correct — inject the shared Service directly
$category = $this->detector->detectFromParameterIds($ids);
```

If two controllers share logic, extract it into a Service and inject the Service
into both. `CartParameterSyncService` and `PriceFilterCategoryDetectorService`
were created precisely to eliminate the cross-controller call that existed between
`PurchaseParameterController` and `ParameterDetectionController`.

---

## Key Conventions

- All API responses use the `success()`/`error()` helpers — never return raw `response()->json()` from authenticated endpoints
- Model column names are accessed via class constants (`Order::STATUS`, not `'status'`)
- Translations use Laravel's `__()` with keys like `'orders.cannot_cancel'`, `'wallet.insufficient_balance'`
- **FormRequest `messages()` must use `__()` calls** — never return hardcoded Persian strings. Each validation message must have a matching key in the relevant `lang/fa/*.php` file (e.g. `orders.store_address_required`). The same rule applies to controller response messages passed to `success()`/`error()`
- **Never hardcode user-facing strings anywhere in PHP** — all strings that could appear in an API response or be stored as a user-visible description must go through `__('domain.key')`. The only exception is internal log messages (passed to `Log::error()` etc.) which may remain in English
- Persian dates use the `PersianDate` trait (provides `created_at_p`, `created_at_diff` appended attributes via Verta)
- Phone numbers are normalized to `09xxxxxxxxx` format using `PhoneNumber::to09($mobile)` from `app/Support/PhoneNumber.php` — **never** write a local helper
- Verification codes are stored in `users.secret` with expiry in `users.secret_expires_at`
- Financial amounts are in **Rials** (not Tomans)
- The system admin wallet user ID is `config('financial.system_user_id')` (default 1) — read from `config/financial.php`, **never hardcode**
- All financial thresholds/fees are in `config/financial.php` — always use `config('financial.*')` or the Action's static helper (e.g. `PayHoldFeeAction::fee()`)
- **Never call `env()` outside config files** — `env()` returns `null` in production once `php artisan config:cache` is run. All `env()` calls belong exclusively in `config/*.php` files; application code must use `config('key')`.
- The front-end SPA base URL is `config('app.front_url')` (env `FRONT_APP_URL`) — used in transactional emails
- API-docs authorised emails are `config('app.api_docs_emails')` (env `APP_API_DOCS_EMAILS`, comma-separated)
