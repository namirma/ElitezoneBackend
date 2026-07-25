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
                 SubmitMoadianInvoiceRequest  ← shared by Doc Q (elite yar) and Doc O (supplier) endpoints
  Product/     — ProductIndexRequest, ProductVariantFilterRequest, ProductVariantPriceRequest, SearchRequest, ToggleStockSubscriptionRequest
  Profile/     — UpdateRealProfileRequest, UpdateLegalProfileRequest, StoreUserTypeRequest, StoreEngineerProfileRequest,
                 StoreContractorProfileRequest, StoreEmployerProfileRequest, StoreSupplierProfileRequest,
                 StoreJobExperienceRequest, StoreListopherRequest, InviteEliteYARRequest, RejectInvitationRequest,
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
               EliteYARRelationResource, JobExperienceResource, AddressResource, EduixRecordResource
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
              EliteYARRelationController, BeneficiaryController,
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
                 ReleaseEliteYARHoldAction,    ← Doc Q: releases WEY+WVAT holds
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
elite yar fee (TYPE_FEE_HOLD WalletTransaction + balance_hold increment) inside a
single DB::transaction. `OrderActionController::finalizeInvoice()` delegates to it.

`ReleaseEliteYARHoldAction` — Doc Q post-weighbridge: when the elite yar submits
their Moadian invoice, releases WEY hold→available (elite yar net) and WVAT
hold→available (elite yar VAT). Idempotent via `elite_yar_moadian_ref` guard.
Route: `POST /profile/orders/{order}/elite-yar-moadian-invoice` (OrderActionController).

`RecordSupplierMoadianInvoiceAction` — Doc O post-weighbridge: when the supplier
submits their Moadian invoice, releases WVAT hold→available (supplier VAT portion of
PTSM). Idempotent via `supplier_moadian_ref` guard.
Route: `POST /profile/orders/{order}/supplier-moadian-invoice` (SupplierOrderController).

`RecordSystemMoadianInvoiceAction` — Doc P post-weighbridge: when an admin issues
the platform's (Saze) Moadian invoice to the buyer, releases `sazeVat` from
WVAT hold→available. `sazeVat` is re-derived at release time from the stored
POOM/PTSM/eliteYARGross snapshots and `vat_rate`. Idempotent via
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
    InvoicePdfRenderer   — PDF rendering + Storage::disk('financial') write (private archive)
  Financial/
    WalletTransactionEngine    — multi-wallet operations for all 5 wallet codes:
                                 WO (buyer), WS (supplier), WSSG (Saze/system),
                                 WEY (elite yar), WVAT (VAT escrow).
                                 resolveUserId() maps each code to a user_id;
                                 WALLET_SUPPLIER always requires explicit targetUserId
                                 (supplier lives on order items, not the order row).
    SettlementCalculatorService — POO/PTS commission math with 4 formula variants
                                 (+/-TC controls commission on Trans, +/-VAT controls
                                 VAT on Trans). Flags are read from supplier_profiles
                                 and snapshotted to the order at payment time.
    FinancialHealthService      — 6 aggregate queries for the admin financial-health
                                 panel (system/VAT wallet balances, platform totals,
                                 pending deposit/withdrawal sums, supplier debts).
                                 Injected into AdminFinancialController::financialHealth().
  Pricing/                       — pipeline (SetBasePrice → ApplyVariantDiscount → ApplyPriceFilters → ApplyVat)
                                   ApplyPriceFilters caches ALL active categories+filters
                                   under key 'pricing:categories_with_filters' (TTL 3600s);
                                   PriceFilterCategory + PriceFilter booted() flush the key
                                   on every save/delete.
```

When a controller method grows past ~30 lines of business logic, extract it
into an Action (write side-effects) or a Service (read/composition).

**One user = one cart — always select it via `Cart::queryForUser()` / `Cart::resolveForUser()`.**
`carts.user_id` is uniquely indexed (`2026_07_25_110000_add_unique_user_id_to_carts_table`;
guest carts keep `user_id = NULL` and are keyed by `session_id`, and MySQL allows many
NULLs in a unique index). Before that index the readers disagreed — `CartResolver` used
`firstOrCreate` (lowest id) while `PlaceOrderAction` used `latest('updated_at')` — so a
user could see cart A and place an order from cart B. Every reader now goes through the
model helpers: `CartResolver`, `CartParameterSyncService`, `CheckoutController::placeOrder`,
`PlaceOrderAction` (which adds `lockForUpdate()`), and `User::cart()` (explicitly
`orderBy(id)`). **Never** reintroduce a bare `Cart::firstOrCreate([user_id])` or an
ad-hoc ordering. Pinned by `Cart/SingleCartPerUserTest`.

**`CartResolver::activeCode()`** is the canonical way to get the active pricing
code anywhere in the codebase. It checks (in order): middleware-bound container
value → authenticated user's role via `PricingRoleResolver` → `'employer_without_elite_yar'`
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

**Payment gateway contract.** `PaymentGatewayInterface` declares `pay()`, `getName()`,
**and `verify(float $amount, string $authority): ?PaymentReceipt`**. `verify()` is what the
callback (`ProcessPaymentCallbackAction`) relies on — never call a gateway method that the
interface doesn't declare. `PaymentReceipt` (`app/Services/Payment/PaymentReceipt.php`) is a
readonly value object with `isSuccessful()` / `getReferenceId()` and `PaymentReceipt::success($refId)`
/ `PaymentReceipt::failed()` factories. A real Passargad/Shaparak gateway is not yet integrated
(spec: pending license), so `ShaparakGateway::verify()` returns `PaymentReceipt::failed()` (fails
safely — never crashes, never phantom-credits) until the real driver is implemented.
`ProcessPaymentCallbackAction::settle()` re-locks the transaction and re-checks its `pending`
status **inside** the DB::transaction to prevent double-credit on retried bank callbacks.

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
| `financial.reservation_threshold` | `RESERVATION_THRESHOLD_RIALS` | `2_000_000_000` | Orders above this require reservation flow (200M Tomans — matches the Passargad gateway per-payment limit in the spec) |
| `financial.hold_fee` | `HOLD_FEE_RIALS` | `50_000_000` | Fee to extend a reservation (5M Tomans). `PayHoldFeeAction` debits WO **and** credits WSSG (Saze) so the ledger stays balanced — double-entry, never a bare debit |
| `financial.system_user_id` | `FINANCIAL_SYSTEM_USER_ID` | `1` | User ID of the platform's central "Saze" (WSSG) wallet |
| `financial.vat_user_id` | `FINANCIAL_VAT_USER_ID` | *(falls back to system_user_id)* | User ID of the dedicated VAT escrow (WVAT) wallet; set in .env once a separate VAT user is created |
| `financial.pending_order_ttl_hours` | `PENDING_ORDER_TTL_HOURS` | `2` | Hours before an unpaid PENDING order auto-cancels (`orders:cancel-expired` — `CancelExpiredOrders`) |
| `financial.reservation_validity_hours` | `RESERVATION_VALIDITY_HOURS` | `2` | Hours a paid reservation stays valid before it must be completed/extended |
| `financial.hold_fee_extension_hours` | `HOLD_FEE_EXTENSION_HOURS` | `24` | Hours that paying the hold fee extends a reservation (`PayHoldFeeAction::extensionHours()`) |
| `financial.deposit_min` | `DEPOSIT_MIN_RIALS` | `10_000` | Min per wallet deposit (read by `WalletDepositRequest`) |
| `financial.deposit_max_shaparak` | `DEPOSIT_MAX_SHAPARAK_RIALS` | `200_000_000` | Max per Shaparak-gateway deposit (bank-set) |
| `financial.deposit_max_podium` | `DEPOSIT_MAX_PODIUM_RIALS` | `2_000_000_000` | Max per Podium direct deposit |
| `financial.weighbridge_tolerance` | `WEIGHBRIDGE_TOLERANCE_RIALS` | `1_000` | Below this abs POO/PTS delta, post-weighbridge difference is rounding noise (`ProcessWeighbridgeResultAction`) |

> **Never hardcode time windows in Actions.** Read them via `config('financial.*')`
> — e.g. `now()->addHours((int) config('financial.reservation_validity_hours', 2))`,
> or `PayHoldFeeAction::extensionHours()`. The 2h / 24h literals were removed from
> `PlaceOrderAction`, `ProcessFullOrderPaymentAction`, and `PayHoldFeeAction`.

```php
// ✅ correct
config('financial.reservation_threshold')
PayHoldFeeAction::fee()   // wraps config('financial.hold_fee')

// ❌ wrong — never hardcode
2_000_000_000
self::FEE_RIALS   // deprecated constant in PayHoldFeeAction, kept for BC only
```

### Shipping — Shipments (multi-origin + multi-destination freight)

A cart/order can hold items from multiple factories going to multiple destinations. The
unit of freight is a **Shipment**: one origin (factory location) → one destination (address),
with its own cost and lifecycle status. An order has one or more shipments;
`orders.shipping_cost` (= `Trans`) is the sum of all shipment costs.

- **`shipments` table**: `(order_id, factory_id/factory_location_id origin, address_id +
  destination_province_id/destination_city_id, weight, billed_count, cost_basis,
  shipping_cost, status)`. `Order hasMany shipments`; each `order_items.shipment_id` points to
  its shipment. `billed_count` + `cost_basis` record HOW the leg was billed (auditable).
- **Per-line destination**: `cart_items.address_id` (nullable) holds the chosen destination
  for that line (set via Add/Update cart, validated to belong to the user). At placement a
  line's destination = `cart_item.address_id` ?? the order's primary `address_id`.
- **Unit-aware freight (`shipping_rates` table)**: `(factory_location_id origin,
  destination_province_id, destination_city_id nullable, flat_cost, per_kg_cost, cost_basis,
  per_count_cost, is_active)`. Each route picks its own **billing basis** via `cost_basis`
  (cast to `App\Enums\Shipping\ShippingCostBasis`):
  - `weight` (default) → cost = `flat_cost + per_kg_cost × total_weight_kg` — goods sold by
    mass, e.g. **میلگرد** (rebar).
  - `count` → cost = `flat_cost + per_count_cost × total_sales_count` — goods billed per
    piece/pack regardless of weight, e.g. **آجر / وال‌مش** (brick / wall-mesh), per the
    factory/supplier directive.
  City-specific row wins; province-level row (`destination_city_id = null`) is the fallback.
  **No matching row → that shipment costs 0** (graceful degradation until rates are entered).
  Backward compatible: legacy weight-only rows default to `cost_basis = 'weight'`,
  `per_count_cost = 0` and price exactly as before.
- **`ShippingCostCalculator::quote($lines)`** — each line carries `factory_id`,
  `factory_location_id`, `address_id`, `destination_province_id`, `destination_city_id`,
  **`weight` AND `count`** (both metrics, so either basis can price it). It groups lines into
  shipments by `(factory_location_id, address_id)`, picks the rate's basis, prices the leg
  (`flat + per_kg × weight` OR `flat + per_count × count`), allocates the cost back onto lines
  **proportional to that same basis metric** (last line absorbs the rounding remainder), and
  returns a `ShippingQuote` (`total`, `perLine`, `shipments[]` of `ShipmentGroup` — which now
  carries `weight`, `count`, `costBasis`). Single-destination orders are just the case where
  every line shares one address → one shipment per origin.
- **`PlaceOrderAction`** resolves each line's destination, passes both `weight` (logistics =
  Σ qty × order_unit.amount) and `count` (sales quantity), calls `quote()`, creates one
  `Shipment` row per `ShipmentGroup` (persisting `billed_count` + `cost_basis`), links each
  `OrderItem` to its shipment, and stores `orders.shipping_cost = total` + per-line
  `order_items.shipping_cost` (+ origin `factory_id`/`factory_location_id`).
- **Relation naming gotcha**: `OrderItem`/`Shipment` use `originFactory()` /
  `originFactoryLocation()` — never name a relation `factory()`, it collides with the
  `HasFactory` trait's static `factory()` (this broke 25 tests once).
- Still out of scope: **an admin/supplier CRUD panel to enter `shipping_rates`** (basis,
  per_kg/per_count, flat) — rates are currently inserted directly (seed/tinker); when the
  panel is built, validate `cost_basis` with `Rule::enum(ShippingCostBasis::class)`.
  Also out of scope: **splitting into one Order per factory** (independent settlement), and
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

**EliteYAR gross (PTC)** is the `%CO` slice of the commission pool:
`(POOM − PTSM) × %CO / (%CO + %SSG)`. Deriving it from POOM−PTSM inherits the same
±TC/±VAT handling as POO/PTS instead of an ad-hoc formula. The lifetime cap
(`elite_yar_lifetime_cap_multiplier`, default 2) is enforced in
`WeighbridgeVatRouter::applyEliteYARLifetimeCap()` and is now **wired**:
`eliteYARLifetimeCeiling()` returns `cap_multiplier × users.badge_purchase_amount`
(the invoice the elite yar paid for their badge). A NULL `badge_purchase_amount`
(e.g. pioneer/normal elite yars with no paid badge) means **uncapped**. The cap clamps
each payout to the remaining headroom = `ceiling − Σ payment_to_elite_yar on prior
settled orders`.

**Where `badge_purchase_amount` will come from (interim: dormant).** Badges live on the
company's legacy site «سازه نود» (Saze Nod), not in EliteZone. The plan is a future
supervisor-provided API that matches Saze-Nod users to their EliteZone account and imports
`badge_id` + the amount they paid for the badge. That API does not exist yet, so the column
stays NULL and the cap is **dormant (uncapped)** — intended interim behaviour. When it lands,
add a sync job/command that fills `users.badge_id` + `users.badge_purchase_amount`; the cap
activates automatically (no further cap logic needed).

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

**Doc Q — EliteYAR Moadian Invoice** (submitted by elite yar)
- At weighbridge: `eliteYARNet → WEY balance_hold`, `eliteYARVat → WVAT balance_hold`
- VAT split: `vatMultiplier = (vat%/100) / (1 + vat%/100)`; `eliteYARVat = gross × vatMultiplier`
- On submission: `ReleaseEliteYARHoldAction` releases both holds to available
- Order fields set: `elite_yar_moadian_ref`, `elite_yar_hold_released_at`
- Authorization: only `order.elite_yar_user_id` (the elite yar / commission earner) can call this

**Doc O — Supplier Moadian Invoice** (submitted by supplier)
- At weighbridge: `supplierVat = PTSM × vatMultiplier → WVAT balance_hold`
- On submission: `RecordSupplierMoadianInvoiceAction` releases the VAT hold to available
- Order fields set: `supplier_moadian_ref`, `supplier_hold_released_at`
- Authorization: `OrderPolicy::manageAsSupplier`

**Doc P — System (Saze) Moadian Invoice** (submitted by admin)
- At weighbridge: `sazeVat = sazeGross × vatMultiplier → WVAT balance_hold`
  where `sazeGross = (POOM − PTSM) − eliteYARGross`
- On submission: `RecordSystemMoadianInvoiceAction` derives `sazeVat` from stored
  POOM/PTSM/eliteYARGross snapshots and releases the WVAT hold to available
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
Order::ELITE_YAR_MOADIAN_REF      // string|null — set when Doc Q submitted
Order::ELITE_YAR_HOLD_RELEASED_AT // datetime|null
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
| D | `DOC_ELITE_YAR_REPORT` | Direct purchase + has beneficiary | `ProcessPreScalePaymentAction` |
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
| Q | `DOC_TAX_INVOICE_ELITE_YAR` | EliteYAR submits Moadian invoice | `ReleaseEliteYARHoldAction` |

**`OrderInvoiceController::download()`** serves any of the above by slug (e.g.
`GET /profile/orders/{order}/invoices/receipt` → `DOC_RECEIPT_OWNER`). The slug→code
map is an explicit `$typeMap` array; unknown slugs return 422. Never add a
`default` fallback that silently returns a different document.

`InvoiceGenerator::getViewName()` has the same rule and **throws
`InvalidArgumentException` on an unknown doc code** — it used to fall through to
`default => 'pdf.invoice_default'`, which meant Doc S (buyer survey) silently rendered
an unrelated invoice and Doc T (`pdf.survey_elite_yar`) pointed at a Blade that did not
exist. Both survey views now exist (`pdf.survey_buyer`, `pdf.survey_elite_yar`).
`InvoiceGeneratorRoutingTest::every_document_code_maps_to_an_existing_blade_view`
reflects over every `FinancialCodes::DOC_*` constant and asserts the mapped view
resolves — adding a new doc code without its Blade fails the suite.

**Per-document authorization (do not weaken).** After the generic `authorize('view',
$order)`, `download()` also enforces `mayAccessDocument($order, $type)` against a
`DOCUMENT_AUDIENCES` map: buyer-docs (initial/receipt/final/refund/tax/…) → placer only;
supplier-docs (supplier_report/final_supplier/tax_supplier/…) → the order's item supplier;
elite yar-docs (elite_yar_report/tax_elite_yar/…) → beneficiary/elite yar; admins
bypass. This closed a real leak where any order party that passed `view` could pull
another party's tax invoice. The user-facing route currently whitelists only
`['initial','final','tax']`; keep the audience map complete anyway in case the route widens.

### Financial Flow (Critical Path)

The system has a wallet-based payment model. Five wallet codes are in use:

| Code | Constant | Wallet owner |
|------|----------|--------------|
| `WO` | `WALLET_OWNER` | buyer (`placer_user_id`) |
| `WS` | `WALLET_SUPPLIER` | supplier (from `order.items.first().supplier_user_id`) |
| `WSSG` | `WALLET_SAZE` | platform system (`financial.system_user_id`) |
| `WEY` | `WALLET_ELITE_YAR` | elite yar (`elite_yar_user_id`) |
| `WVAT` | `WALLET_VAT` | VAT escrow (`financial.vat_user_id`, falls back to system_user_id) |

Payment entry points:

1. **WalletPaymentController** — generic wallet payment for orders
2. **OrderPaymentController::payFullOrder** — handles 3 scenarios: direct purchase, reservation (above `financial.reservation_threshold`, default 200M Tomans), and completion of reserved orders; protected by `idempotency` middleware. **Compare the threshold against `Order::reservationBasisAmount()`, never `final_amount`** — the threshold is an order-*size* cap (it mirrors the Pasargad per-payment limit), and `final_amount` is net of the coupon discount, so a coupon could push a large order under the line and silently strip the buyer's reservation option. Both `HasOrderProgress` timelines use the same method so the progress UI can never disagree with the payment router.
3. **OrderPaymentController::payHoldFee** — hold fee extension; protected by `idempotency` middleware
4. **OrderActionController::submitEliteYARMoadianInvoice** — Doc Q; releases WEY+WVAT holds
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

Pricing uses a Pipeline pattern in `app/Services/Pricing/Pipes/`. The **actual
runtime order** (`PriceCalculationService::calculatePrice`) is:
```
SetBasePrice → ApplyVariantDiscount → ApplyPriceFilters → ApplyVat
```
i.e. the **variant discount is applied before the role price filters** (the code is
the source of truth here; the spec is a business-process doc and does not dictate the
pipe order). The order is immaterial when every adjustment is a `percentage`
(multiplication commutes), but it changes the result when a `fixed_amount` discount and
a percentage filter are mixed. **If you ever reorder these pipes, re-pin the pricing
tests and verify against a mixed fixed/percentage case** — and keep
`CartPricingCalculator` / `CalculateOrderEstimateAction` consuming the same DTO so cart,
estimate and placement stay in agreement.

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
│   │                   wishlists, education, elite yars, etc.),
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
  Order/     — SendOrderCancellationNotification,
               SendOrderPaymentNotification, SendOrderPlacedNotification
  Financial/ — SendTransactionNotification, SendWalletFundingNotification
  User/      — SendWinBackOffer
```

Event → Listener wiring is registered in `app/Providers/EventServiceProvider.php`.

**All listeners implement `ShouldQueue`** (commit `26b2c66`). The last synchronous listener (`SendWinBackOffer`) was queued in Sprint 1. Never add a new listener without `ShouldQueue` + `InteractsWithQueue`.

Dispatch flow:
- `OrderPlaced` → inventory deduction, notifications
- `OrderPaid` → payment notifications (SendOrderPaymentNotification)
- `OrderCancelled` → stock restoration, wallet refund
- `CartItemChanged` → analytics tracking (LogCartEvent)
- `WalletFunded` / `TransactionProcessed` → financial notifications

### User Roles (Laratrust)

Users can have roles: `admin`, `engineer`, `contractor`, `employer`, `supplier`. Each role has a corresponding profile model (EngineerProfile, ContractorProfile, etc.) linked to the User. Role selection happens in `ProfileController::setUserType`.

**Supplier role is admin-gated (never self-assignable).** Selecting supplier in
`setUserType` only creates a `SupplierProfile` with `status = pending`
(`supplier_profiles.status`: pending/approved/rejected; NOT in `$fillable` — admin
actions change it via `forceFill()`). The Laratrust `supplier` role — which drives
pricing resolution and the settlement formula flags — is attached **only** by
`AdminSupplierController::approve` (`POST /admin/suppliers/{user}/approve`;
`/reject` sets rejected + detaches the role). `SyncUserRolesAction` re-attaches the
role without re-approval only when the profile is already `approved`. Never add an
`addRole('supplier')` call on a user-reachable path.

**Expert role removal is non-destructive for records.** `SyncUserRolesAction` no
longer deletes `eduixRecords`/`jobExperiences` when the expert role is dropped —
deleting them was irreversible (approved records + attached files, with the files
orphaned on disk). They stay dormant and reappear if the user re-selects expert.

**UserType slug gotcha:** the technical-elite yar `user_types` row is seeded with slug
**`expert`** (`UserTypeSeeder`), not `consultant`. `EngineerController`/`ContractorController`
auto-attach the engineer/contractor role only when `user.type_id` matches the `expert` type —
querying `slug = 'consultant'` (the old bug) returned null and never attached the role.

### External Service Integrations

- **Podium** — identity verification, bank settlement, NID-mobile matching (via `PodiumClient`);
  deposit statement polling every 5 min (via `DepositStatementService` + `ProcessPodiumDepositAction`)
- **TSMS** — SMS OTP delivery (via `SmsService`, dispatched as `SendSmsJob`)
- **Espadana** — loading order commands for suppliers

### Jobs — Domain Subfolders

`app/Jobs/` is organized into domain subfolders (commit `d03182e`):

```
Jobs/
  Analytics/    — CalculateCustomerRfm, LogProductView, PredictInventoryNeeds,
                  RollupProductViewsJob
  Financial/    — CheckSupplierBalancesJob, PollPodiumDepositsJob
  Notification/ — SendSmsJob, SendInviteSmsJob
```

> `Order/CheckReservationExpiryJob` was DELETED — it was dead code with zero call
> sites; expired-order cancellation actually runs via the `orders:cancel-expired`
> artisan command (`Console/Commands/CancelExpiredOrders`, scheduled every minute).

Scheduled jobs (crons, wired in `Console/Kernel.php`) — **all** use
`withoutOverlapping()->onOneServer()`:
- `orders:cancel-expired` command — cancels expired pending/reserved orders
- `Financial/CheckSupplierBalancesJob` — polls `supplier_debt_pending = true` orders;
  when supplier balance ≥ debt, reclaims it and releases buyer's HOLD refund to AVAILABLE
- `Analytics/CalculateCustomerRfm` — customer segmentation analytics (03:00)
- `Analytics/PredictInventoryNeeds` — inventory restock prediction (04:00)
- `Analytics/RollupProductViewsJob` — daily product-view aggregation + raw retention (02:30)

### Data Science / Analytics

All analytics config lives in `config/intelligence.php` — **never hardcode** RFM
thresholds, conversion rates, retention windows, or seasonal multipliers.

**RFM (`Jobs/Analytics/CalculateCustomerRfm`)** — streams customers with `cursor()`
(never `get()` — memory). Scores R/F/M from `config('intelligence.rfm.*')` in two modes:
`fixed` (default; threshold tables) or `quintile` (`RFM_SCORING_MODE=quintile` —
boundaries derived from the live distribution). Segment is an **explicit 5×5 R×F
matrix** (`determineSegment()`) covering all 25 combinations with no overlap/gap,
including `lost`; recency past `rfm.churn_days` forces `lost`. Segment changes fire
`CustomerSegmentChanged` (wrapped in try/catch). Use `CustomerStat::SEGMENT_*`
constants everywhere — never hardcode segment strings (`SendWinBackOffer` compares
against the constants).

**Inventory (`Services/InventoryIntelligenceService`)** — `chunkById` over variants
(never `get()`). **Never `truncate()`** the predictions table (it destroys backtesting
history); instead each run inserts a fresh snapshot stamped `calculated_at` and old
snapshots beyond `inventory.prediction_retention_days` are pruned.
`InventoryController::predictions()` returns only the latest run (max `calculated_at`).
Demand = `(velocity × prediction_days + searchVolume × perProductConversion) ×
seasonalMultiplier`. Conversion rate is per-product (sold/searched, clamped); the
seasonal multiplier is keyed by **Jalali month** (`intelligence.inventory.seasonal_multipliers`).
`InventoryPredictionResource` gates `sales_velocity`/`search_hits` to admins only
(competitive intelligence).

**Product views (`Jobs/Analytics/LogProductView`)** — bot-filters by User-Agent,
dedups within `product_views.dedup_seconds`, and **anonymizes the IP** (last
octet/hextet zeroed) before storing. `RollupProductViewsJob` aggregates raw rows into
`product_view_daily` (one query, group-by-date, idempotent upsert) then prunes raw rows
beyond `product_views.retention_days`. Analytics should read the rollup, not raw.
**Gotcha:** the view-logging dispatch is `\App\Jobs\Analytics\LogProductView` — the
class is namespaced under `Analytics/`, not flat `App\Jobs`.

**Customer-value metrics** — `AnalyticsService::getCustomerValueMetrics()` derives
CLV (avg monetary), churn rate (segment `lost` or recency > `churn_days`), and
active/churned counts from `CustomerStat`; surfaced in the admin dashboard as
`customer_value`.

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

**Test isolation setup (critical):** Tests run on a dedicated `laravel_testing` database,
never on the live `laravel` DB. Two safety layers enforce this:
1. `phpunit.xml` overrides `DB_DATABASE=laravel_testing` for all test runs.
2. `tests/CreatesApplication.php` has a hard guard that throws `RuntimeException` before
   any migration runs if the active DB is in the `FORBIDDEN_TEST_DATABASES` list (`laravel`).

To set up a new environment: `CREATE DATABASE laravel_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
then `DB_DATABASE=laravel_testing php artisan migrate --force`.

```
tests/
  Feature/
    Auth/        — AuthControllerTest (7 tests: OTP check-user, verifyCode, replay prevention,
                   lockout, expired code — no Podium dependency)
    Checkout/    — PlaceOrderTest (9 tests: auth, validation, cart-empty, security)
    Financial/   — PayHoldFeeActionTest, ProcessPreScalePaymentActionTest,
                   ProcessReservationPaymentActionTest, WalletDepositTest,
                   SettlementCalculatorServiceTest (bcmath POO/PTS/VAT — no DB),
                   HoldReleaseAuditTest (append-only hold-release ledger)
    Policies/    — OrderPolicyTest, UserBankAccountPolicyTest,
                   CartPolicyTest, AddressPolicyTest, WishlistPolicyTest
    Pricing/     — CreatesPricingFixtures (shared trait),
                   SetBasePriceTest, ApplyPriceFiltersTest,
                   ApplyVariantDiscountTest, ApplyVatTest
    Profile/     — ProfileVerificationBadgeTest (5 tests: is_verified contract via GET /api/profile)
  Unit/          — (empty placeholder dir — kept so phpunit.xml works)
```

**Total: 232 tests, ~525 assertions — all green. ✅**

Newer test files: `Auth/AuthControllerTest`, `Financial/EliteYARLifetimeCapTest`,
`Financial/ReceiptPrivateDiskTest`, `Financial/ProcessTransactionRaceTest`,
`Ticketing/TicketLifecycleTest`, `Order/InvoiceAccessTest`, `Cart/ShippingQuoteTest`,
`Profile/EmailTokenVerificationTest`, `Order/HasOrderProgressTest`,
`Financial/WithdrawalSettlementTest`, `Profile/ProfileFixesTest`.

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
  (e.g. `ProcessWeighbridgeResultAction`, `ReleaseEliteYARHoldAction`,
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

Performance-critical indexes added across multiple migrations:

| Table | Index | Migration | Reason |
|---|---|---|---|
| `orders` | `orders_status_idx` | `2026_05_23_*` | scope queries (`current`/`delivered`/`cancelled`) |
| `orders` | `orders_payment_status_idx` | `2026_05_23_*` | admin filtering |
| `orders` | `orders_reservation_expires_at_idx` | `2026_05_23_*` | `orders:cancel-expired` cron |
| `orders` | `orders_placer_status_idx` (composite) | `2026_05_23_*` | "my orders" lists |
| `users` | `users_national_code_idx` | `2026_05_23_*` | admin back-office search |
| `wallet_transactions` | `wt_order_type_status_idx` (composite) | `2026_06_22_000000_*` | financial report filters |
| `order_items` | `order_items_supplier_user_id_idx` | `2026_06_22_000000_*` | supplier order listing |
| `tickets` | `tickets_status_sla_due_at_idx` (composite) | `2026_06_22_010000_*` | SLA breach queries + KPI reports |
| `elite_yar_registered_clients` | `elite_yar_client_unique` (composite, unique) | `2026_06_22_020000_*` | one-to-one per elite yar/client pair |

When adding queries that filter heavily on a column, check this list and
add an index in a new migration if needed. Always make migrations additive.

### Admin user-management schema (`2026_06_07_*`)

- `users.is_active` (boolean, default `true`) — toggled by `AdminUserController::toggleStatus`
  and filtered by `index()`. Exposed on the `User` model as `User::IS_ACTIVE` (fillable + `boolean` cast).
- `user_notes` table (`user_id`, `admin_id` nullable, `note`) + `UserNote` model — back-office
  per-user notes written via `AdminUserController::addNote` (`User::notes()` relation).
- **Run `php artisan migrate`** after pulling — both endpoints (and the `is_active` filter) require these.

### Employer profile — `status` column (`2026_06_20_*`)

`employer_profiles.status` (string, nullable) tracks profile-completion state. Migration
`2026_06_20_100000_add_status_to_employer_profiles_table` adds the column and backfills
existing rows. `EmployerProfile::STATUS` constant + `EmployerController::store()` writes it.
`EmployerProfileResource` exposes it. **Run `php artisan migrate`** after pulling this migration.

### OrderUnit cache — use `OrderUnit::allCached()` everywhere

`OrderUnit::all()` is called in 8 places for pricing, cart, shipping, and order placement.
All 8 now share a single canonical method:

```php
OrderUnit::allCached()  // → Collection keyed by ID, TTL 86400s, key 'catalog:order_units'
```

**Never** introduce a new `Cache::remember(...)` call for OrderUnit — use `allCached()` instead.
`OrderUnit::booted()` flushes all 5 historical keys + the canonical key on every save/delete.
Callers that need a plain (un-keyed) collection use `OrderUnit::allCached()->values()`;
callers that need `->get($id)` (O(1)) already get a keyed collection.

### `pricing:categories_with_filters` — single cache key for all PriceFilter data

`ApplyPriceFilters::getFilters()` no longer runs per-request DB queries. It loads
ALL active `PriceFilterCategory` rows with their `filters.filterType` relations into
`'pricing:categories_with_filters'` (TTL 3600s) and filters in PHP.

`PriceFilterCategory::booted()` and `PriceFilter::booted()` both call
`Cache::forget('pricing:categories_with_filters')` on saved/deleted.
**Never** cache filter data under a different key — add lookups to `allCategoriesWithFilters()`.

### Named route for wallet deposit

`POST /profile/financial/wallet/deposit` is now named `wallet.deposit`.
Use `route('wallet.deposit', [], false)` wherever you need the path in a response body
(the third argument `false` returns a path without domain).

### Beneficiary flow — elite yar-client relationship is saved on confirm

`BeneficiaryController::confirmVerification()` calls:
```php
Auth::user()->registeredClients()->syncWithoutDetaching([$beneficiaryUser->id]);
```
after a successful OTP check. This records the elite yar→client relationship in
`elite_yar_registered_clients` (unique per pair). The link is used by:
- `GET /api/profile/elite-yars/registered-clients` — lists all clients this elite yar
  has verified, via `EliteYARRelationController::listRegisteredClients()` (role.expert only)
- `User::registeredClients()` — BelongsToMany via `elite_yar_registered_clients`

Mobile is masked as `0910****567` (same `maskMobile()` logic as `UserResource`).
**Do not** add a second registration hook in `startVerification` — the relationship is
only established after successful OTP confirmation, not before.

### `HandleSuccessfulPayment` listener — DELETED (refactored)

`app/Listeners/Order/HandleSuccessfulPayment.php` has been deleted. It previously called
`ProcessPreScalePaymentAction::execute($order)` when `OrderPaid` fired, coupling financial
processing to the event bus (hard to test, hard to ensure atomicity). Financial processing
now happens directly inside the payment Actions. `OrderPaid` is now notifications-only →
`SendOrderPaymentNotification`. `EventServiceProvider` has been updated accordingly.
**Do not re-add** any financial Action call inside an event listener.

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

### `CheckSupplierBalancesJob` — scope to `supplier_debt_pending` only; use `chunkById`

This job must query **only** orders where `supplier_debt_pending = true`. Querying
all hold transactions would accidentally release elite yar-commission holds (WEY)
that are waiting for Doc Q. The job uses `chunkById(100, ...)` (not `->get()`) so
it never loads all debt orders into memory at once — important when many debts accumulate:

```php
Order::where(Order::SUPPLIER_DEBT_PENDING, true)
    ->with(['items.supplier.wallet'])
    ->chunkById(100, function ($pendingOrders) use ($smsService, $walletEngine) {
        foreach ($pendingOrders as $order) {
            try {
                $this->processOrder($order, $smsService, $walletEngine);
            } catch (\Exception $e) {
                Log::channel('financial')->error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
            }
        }
    });
```

> **Why `chunkById` and not `cursor()`?** `cursor()` streams lazily but cannot eager-load
> (`->with([...])`). `chunkById` fetches 100 rows at a time, supports eager loading per
> chunk, and does not load the full result set into memory — best of both worlds here.

Inside the transaction, it finds the specific buyer hold via:
```php
$buyerWallet->transactions()
    ->where('order_id', $lockedOrder->id)
    ->where('type', 'deposit')
    ->where('status', FinancialCodes::STATUS_HOLD)
    ->latest()->first();
```

### Order financial snapshot fields — not in `$fillable`; use `forceFill()->save()`

23 financial snapshot columns on `Order` are intentionally **absent from `$fillable`**.
Mass-assignment via `Order::create([...])` or `$order->fill([...])` will **silently drop** them:

```
OFFICIAL_PRICE, COMMISSION_RATE, OWNER_DISCOUNT_RATE, SAZE_SHARE_RATE,
ELITE_YAR_SHARE_RATE, VAT_RATE, PAYMENT_OF_OWNER, PAYMENT_TO_SUPPLIER,
PAYMENT_TO_ELITE_YAR, PAYMENT_TO_RESERVE, VALIDATION_OF_RESERVE,
WEIGHT_AFTER_SCALE, WEIGHING_COMPLETED_AT, TRANS_COMMISSION, TRANS_VAT,
ELITE_YAR_MOADIAN_REF, ELITE_YAR_HOLD_RELEASED_AT, SUPPLIER_MOADIAN_REF,
SUPPLIER_HOLD_RELEASED_AT, SUPPLIER_DEBT_PENDING, SUPPLIER_DEBT_AMOUNT,
SYSTEM_MOADIAN_REF, SYSTEM_HOLD_RELEASED_AT
```

These fields are written **only** by Financial Actions and Services that use
`forceFill()->save()` — this documents intent ("this code is explicitly permitted
to bypass fillable"). Never add them back to `$fillable`.

In **tests** that need to seed these fields, split into two statements — `save()`
returns `bool`, not the model, so chaining from it doesn't work:

```php
// ✅ correct — two separate statements
$order = Order::create([/* only fillable fields */]);
$order->forceFill([
    Order::PAYMENT_OF_OWNER    => $poo,
    Order::PAYMENT_TO_SUPPLIER => $pts,
    // ... other snapshot fields
])->save();

// ❌ wrong — forceFill chained from create() result is fine, but:
$this->order = Order::create([...])->forceFill([...])->save() ?? null;
//  ↑ save() returns bool, not the model; $this->order would be null/bool
```

Test helpers that accept `array $overrides` (like `makeOrder()` in `HasOrderProgressTest`)
must call `$order->forceFill($overrides)->save()` after `Order::create()` so that any
non-fillable override (e.g. `ELITE_YAR_MOADIAN_REF`) is actually persisted.

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
  they carry supplier/elite yar/VAT holds and must go through the return/settlement
  flow, not a simple refund.

`CheckSupplierBalancesJob` follows the same rule — the supplier-debt reclaim
(`WS → WSSG`) now runs through the engine, not raw `increment`/`decrement`.

### Cancellable states — a buyer may only self-cancel a PENDING order

`OrderActionController::cancel` allows **only `Order::STATUS_PENDING`** (no money moved).
`RESERVED`/`RESERVED_EXTENDED` auto-cancel on expiry via the system cron (never by the
user before expiry). `PROCESSING` and later are paid/settled and must go through the
dedicated return/dispute flow (مرجوعی/داوری) — **never** self-cancel, which would
trigger an incomplete refund.

`OrderPolicy::cancel()` enforces the **same** `STATUS_PENDING` restriction — never widen
the policy to allow broader states than the controller accepts, or the defense-in-depth
is broken (another caller of the policy would allow cancellation the controller forbids).

### Order commission routing — `elite_yar_user_id` drives WEY (NOT `beneficiary_user_id`)

`elite_yar_user_id` is the commission earner and drives elite yar-commission routing
(`WALLET_ELITE_YAR`); `beneficiary_user_id` is the goods recipient (the employer an elite
yar buys for). `StoreOrderRequest` validates each per role (closure rules, never a bare
`exists:users,id`, which would let anyone route commission to an arbitrary user):

- **Employer places**: `beneficiary_user_id` is **prohibited**; `elite_yar_user_id` is
  optional and must be an **approved + verified** technical elite yar of the employer
  (`Auth::user()->technicalEliteYARs()->where('users.id', $value)->wherePivot('status', 'approved')->exists()`).
- **Elite yar places**: `elite_yar_user_id` is **prohibited** (the Action sets it = the
  placer/self); `beneficiary_user_id` is required and must be an **approved** employer of
  the elite yar (`associatedEmployers()`).

All commission-context code reads `elite_yar_user_id` (auth guard for Doc Q, badge lookup,
`WeighbridgeVatRouter`, `ReleaseEliteYARHoldAction`, `FinalizeInvoiceAction` fee hold,
`WalletTransactionEngine::resolveUserId` WEY fallback) — **never** `beneficiary_user_id`.

### Badge codes are `gold` / `silver` / `bronze` / `pioneer`

The seeder (`database/seeders/ProductSeeder.php`) is the source of truth: the gold tier
code is **`gold`** (not `golden`). `SettlementCalculatorService::getCommissionRates()`
matches `'gold', 'golden'` (alias) so a legacy value never silently falls through to the
default rate and underpays a gold-tier elite yar.

### Money math is bcmath — never chain raw floats

All settlement arithmetic (POO/PTS, commissions, VAT splits) goes through
`App\Support\Money` (bcmath, `SCALE = 10`) — **never** chain native `float` ops for
money. Raw floats accumulate IEEE-754 error on repeating decimals (e.g. the VAT
multiplier `1/11`); at billion-Rial magnitudes a 6-place intermediate already lost
~100 Rials, which is why `SCALE` is 10.

- `SettlementCalculatorService::calculatePOO/PTS/calculateCommissions` are bcmath; they
  compute at high precision and return a `float` only at the boundary (storage rounds to
  the column's `decimal:2`). Behaviour is pinned by `SettlementCalculatorServiceTest`
  (exact expected values for all 4 POO + 4 PTS variants + commissions) — keep it green.
- **VAT split is centralised:** `SettlementCalculatorService::extractVat($gross, $vat%)`
  returns `['net' => , 'vat' => ]` using `vat = gross × VAT / (100 + VAT)` (stable form,
  no sub-unit multiplier). **Every** action that splits VAT must call `extractVat()` —
  `ProcessWeighbridgeResultAction` (which *holds* the VAT) and the three Moadian-invoice
  actions (`ReleaseEliteYARHoldAction`, `RecordSupplierMoadianInvoiceAction`,
  `RecordSystemMoadianInvoiceAction`, which *release* it) **must** use the identical split
  so the released amount equals what was held — otherwise a sub-Rial residue is stranded
  in `balance_hold`. Never reintroduce the inline `($vat/100)/(1+$vat/100)` float formula.

### Hold release is append-only — `WalletTransactionEngine::releaseHold()`

Moving `balance_hold → balance` must go through `WalletTransactionEngine::releaseHold(
$userId, $amount, $order, $description, $sourceHold)`. It locks the wallet, moves the
funds, and writes a distinct **`WalletTransaction::TYPE_HOLD_RELEASE`** row (status
`AVAILABLE`, `meta.source_hold_id` linking the original hold) — so the hold *placement*
and *release* are two immutable, timestamped ledger events that net to zero and can be
reconstructed for audit. **Never** release a hold with a bare
`decrement('balance_hold')` / `increment('balance')` (that left no release event).
`CheckSupplierBalancesJob` uses `releaseHold()` and additionally flips the original hold
row to `AVAILABLE` as a settled-marker so it is no longer found as outstanding.
`HoldReleaseAuditTest` pins this contract.

### Wallet status vocabulary — cover `hold` everywhere

The `wallet_transactions.status` column mixes two vocabularies:
`pending`/`confirmed`/`failed`/`rejected` (deposits/withdrawals) and the
order-financial escrow values `FinancialCodes::STATUS_HOLD = 'hold'` /
`STATUS_AVAILABLE = 'confirmed'`. Any new status must have a label + color in
`WalletTransaction::getStatusLabel/Color` **and** a case in the
`WalletTransactionStatus` enum — `hold` is now covered in both.

### Podium Deposit — IBAN and Sender Name Extraction Priority

`ProcessPodiumDepositAction::extractSenderIban()` reads from three sources **in order**:

1. `PayaResult.SourceIban` — documented Podium field for PAYA transfers
2. `SatnaResult.SourceIban` — documented Podium field for SATNA transfers
3. `ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductId` — undocumented, only present for Pol/Bridge transfers

`extractSenderName()` reads in order:
1. `SatnaResult.SourceName` + `SatnaResult.SourceLastName` — documented for SATNA
2. `ItemSystemMetaData.Main_Sys_Meta_Data.TrxSrcProductOwnerName` — undocumented, Pol only
3. `Description` regex fallback — Pasargad bank includes sender name in Description text for PAYA ("توسط NAME") and sometimes Pol ("به درخواست NAME")

**Auto-charge requires شناسه واریز:** `TransferMoneyBillNumber` must be a valid 11-digit numeric string. Pol/Bridge transfers put a bank reference code there (e.g. `C6494530F774`), so they are always unmatched and require admin approval. PAYA transfers also fail to auto-charge if the sender doesn't enter the شناسه واریز in their banking app.

**Do not reorder or merge these extraction paths** — the priority was established empirically from live Podium statement data (commit that introduced it was a bug-fix for missing PAYA IBANs).

### Security & OTP Hardening (recent)

- **Password-reset tokens are rotated + short-lived.** `verifyCode` (forget-password
  path) consumes the 6-digit OTP and replaces it with a random 64-char reset token
  valid for `config('auth.password_reset_ttl_minutes')` (default 15). `changePassword`
  rejects expired tokens (`auth.reset_token_expired`) and clears both `secret` and
  `secret_expires_at` on use. Never return the raw OTP as the reset token or issue a
  token without an expiry.
- **Auth endpoints normalise the mobile before validation.** `forgetPassword`,
  `changePassword`, and `sendVerificationCode` call `normalizeUsername()`
  (`PhoneNumber::to09`) *before* the `exists:users,mobile` rule — validating the raw
  input rejected legitimate `+98…`/`98…`/`0098…` forms.
- **OTP is single-use + lockout-protected.** `verifyCode`, `setPassword`, and
  `confirmChangeMobile` invalidate `users.secret`/`secret_expires_at` the moment a code
  is consumed (no replay inside the expiry window) and compare with `hash_equals`. A
  cumulative lockout (`config('auth.otp_max_attempts')` / `otp_lockout_minutes`, cache-keyed
  by mobile) backs the per-minute route throttle against distributed brute-force. **Never**
  loosen these back to a bare `==` compare or remove the secret-clear.
- **`POST /profile/password` requires the OTP `code`** (final step of the change). Without
  it a stolen token could take over the account. `StorePasswordRequest` validates `code`;
  `AccountSecurityController::setPassword` re-verifies it before writing the new hash.
- **Password change is 3 steps.** `POST /profile/password/otp` (send code) →
  `POST /profile/password/verify` (`verifyPasswordOtp` — checks the code with `hash_equals`
  but **does NOT consume** the secret, so the UI can advance to the new-password screen) →
  `POST /profile/password` (re-verifies + consumes the secret + sets the hash). The verify
  step is read-only and intentionally non-consuming; never make it clear the secret.
- **Sensitive credentials are masked in Resources.** `UserBankAccountResource` masks
  `shaba`/`card_number` and `UserResource` masks `mobile` for everyone except the owner or an
  admin (`$request->user()?->hasRole('admin')`). Bank card↔IBAN auto-completion runs on the
  backend via `CardToIbanService` (card→IBAN only; IBAN→card has no inquiry service in Iran).
- **Password/mobile change revokes other tokens.** `AccountSecurityController::revokeOtherTokens()`
  deletes every Sanctum token except the current session's after a credential change.
- **Domain events implement `ShouldDispatchAfterCommit`.** All 7 events under `app/Events/*`
  defer delivery until the surrounding transaction commits, so queued listeners never see a
  not-yet-committed row. Keep the interface when adding events.
- **Admin order status is a state machine.** `AdminOrderController::changeStatus` only allows
  whitelisted forward transitions (no `pending → delivered` jumps); cancellation still routes
  through `CancelOrderAction`. The `pending → processing` transition additionally requires
  `payment_status = paid` — PENDING is pre-payment and PROCESSING starts the post-payment
  pipeline, so moving an unpaid order forward would let it reach the supplier/weighbridge
  with zero ledger entries.
- **`users.is_active` is enforced globally.** `EnsureUserIsActive` sits in the `api`
  middleware group (Kernel) and 403s any request whose sanctum user has `is_active === false`
  (NULL counts as active). `AdminUserController::toggleStatus` revokes all Sanctum tokens on
  deactivation and refuses self-deactivation; every `AuthController` login/OTP path also
  rejects disabled users (`auth.account_disabled`). Never remove the middleware — without it
  the flag is decorative.
- **Podium manual credit is race-guarded.** `AdminPodiumDepositController::credit` re-fetches
  the `PodiumDepositRecord` with `lockForUpdate` INSIDE the `DB::transaction` and re-checks
  `processed_at` before crediting (the pre-check outside the lock is only fail-fast). Admin
  routes have no idempotency middleware, so this in-transaction guard is the only thing
  preventing a double wallet credit — same pattern as `ProcessTransactionAction`. Pinned by
  `Tests\Feature\Admin\AdminHardeningTest`.
- **Admin list endpoints return Resources, never raw paginators.** `AdminFinancialController`
  and `AdminSearchController::searchTransactions` wrap in `OrderResource` /
  `WalletTransactionResource`; the transaction resource exposes the owner block only
  `whenLoaded('wallet')` (admin lists eager-load it; user endpoints don't).
- **Idempotency middleware** keys guests by IP (`guest:<ip>`, not a shared `guest`) and caches
  only safe response headers (never `Set-Cookie`/auth headers).
- **`DebugController` routes are non-production only** (wrapped in `! app()->environment('production')`);
  the analytics/inventory `data/*` routes sit behind `auth:sanctum + role.admin`.
- **Admin deposit-confirm is double-credit safe.** `ProcessTransactionAction` re-fetches the
  transaction with `lockForUpdate` and re-checks `pending` **inside** the `DB::transaction`
  before crediting — admin routes have no idempotency middleware, so a retried/concurrent
  confirm would otherwise credit the wallet twice. Never drop this in-transaction re-check.
- **Email-token verification matches the exact token.** `AccountSecurityController::checkToken`
  uses `where(EMAIL_VERIFICATION_TOKEN, $token)` — a previous `where([column], $token)` passed
  an array that made Eloquent ignore the token (always-true `WHERE 0 = '…'`), verifying the
  first unverified profile for any token. Never reintroduce the array form.
- **Mobile changes persist the normalized 09 form.** `AccountSecurityController` caches/SMSes
  and stores `PhoneNumber::to09($newMobile)` (the same value Podium verified), not the raw
  input — `users.mobile` must stay canonical `09xxxxxxxxx` for login/uniqueness.
- **Never expose raw exception messages from external services in API responses.** When an
  integration (Podium, bank inquiry, etc.) throws, log it server-side and return a generic
  error to the client. Pattern: `Log::warning('Bank inquiry failed', ['user_id' => Auth::id(),
  'message' => $e->getMessage()]); return error(__('bank.service_unavailable'), [], 503);`
  Returning `['debug' => $e->getMessage()]` leaks internal service details (URLs, error
  codes, stack state) and is a security violation.

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

## Recently Added Subsystems

### Order surveys — two independent documents (S / T)

`order_surveys.type` (`OrderSurvey::TYPE_BUYER = 'buyer'` / `TYPE_ELITE_YAR = 'elite_yar'`,
unique `(order_id, type)`) implements spec docs **S (buyer survey)** and **T (elite yar
survey)** as independent records — submitting one never blocks the other.
`OrderSurveyController::resolveSurveyType()` maps the caller: the order's
`elite_yar_user_id` (even when they are also the placer, i.e. buy-for-others) files **T**;
placer/beneficiary files **S**; anyone else is 403. `orders.survey_status =
SURVEY_COMPLETED` keeps its legacy meaning — "buyer survey done" — and is set **only**
by the buyer-type submission. Pinned by `Order/OrderSurveyTypesTest`.

### Online agreements — versioned, hash-bound, append-only acceptance

Implements the official «دستورالعمل پیاده‌سازی توافق‌نامه آنلاین الیت یار» (docx v1.0).
Five canonical slugs (constants on `Agreement`): `elitezone_terms`, `privacy_policy`,
`elite_yar_terms` (full 18-article legal text + the 8 mandatory checkboxes CHK-01..08 —
naming is **الیت یار**, the product term for مشاور همراه خرید), `employer_terms`,
`supplier_terms`. Seeded by `AgreementSeeder` (registered in `DatabaseSeeder`) —
**idempotent per (slug, version)**; a published version's text must NEVER be edited:
changing legal text means inserting a NEW version row (the old one stays for past
acceptances).

- `agreements` carries `title`, `checkboxes` (json `[{code,text}]`), `content_sha256`,
  `effective_at`. `Agreement::latestActiveForSlug()` is the canonical "current version"
  lookup.
- `user_agreements` is **append-only** (never update/delete an acceptance row) and
  snapshots `version`, `content_sha256`, `checked_items` (codes + text as-of acceptance),
  `acceptance_method`, and a full `snapshot` json (verified mobile, IP, UA, role).
- `SignAgreementAction` runs on role selection (`SetUserTypeAction`) and records **three**
  documents per selection: the two general slugs + the role-specific one. Acceptance =
  either all checkbox codes in `accepted_checkboxes[]` or `terms_accepted=true`
  (aggregate). A mandatory, unaccepted document throws `BusinessException` (400) and
  aborts the role change. Re-selecting a role never re-writes an existing acceptance.
- Endpoints: `GET /api/agreements/{slug}` (public — current version text + checkboxes for
  the acceptance page), `GET /api/agreements/{slug}/pdf` (public — PDF of the latest
  active version, mPDF portrait/RTL via `resources/views/pdf/agreement.blade.php`),
  `GET /api/profile/agreements` (auth — acceptance receipts), `GET
  /api/profile/agreements/pending` (auth — documents whose latest mandatory version the
  user has NOT accepted; the frontend must poll this after login and force active
  re-acceptance), `POST /api/profile/agreements/{slug}/accept` (auth — active
  re-acceptance of the latest version; rejects slugs not relevant to the user's roles),
  and `GET /api/profile/agreements/{slug}/pdf` (auth — PDF of **the version this user
  accepted**, with an embedded acceptance receipt block — never the latest public
  version, per the implementation directive §9).
- Publishing a new version = INSERT a new `agreements` row (same slug, higher version);
  `hasAcceptedLatestAgreement()` and `pending` then flag every affected user until they
  actively re-accept. `Agreement::latestActiveForSlug()` is the single source of truth
  for "current version" (ID-desc tie-break — `latest(created_at)` alone was
  non-deterministic for same-second rows).
- Note: `LaravelMpdf::download()` echoes and returns null — build the HTTP response from
  `$pdf->output()` manually (see `AgreementController::renderPdf`).
- Pinned by `Profile/AgreementAcceptanceTest` (11 tests incl. re-acceptance + PDFs).

### Ticketing — helpdesk model

`tickets` was widened from a basic table to a helpdesk model:
- **Statuses (full state machine):** `open` (new), `in_progress`, `waiting_customer`,
  `waiting_supplier`, `answered`, `resolved`, `closed` (+ legacy `pending`). The `status`
  column is now `VARCHAR` (was an enum that lacked `open` — a latent MySQL-strict bug).
  Use `Ticket::STATUSES` / `Ticket::STATUS_*`.
- **New columns:** `priority` (`Ticket::PRIORITIES`: low/normal/high/urgent), `order_id`,
  `province_id`, `city_id`, `assigned_at`, `first_responded_at`, `resolved_at`, `closed_at`,
  `sla_due_at`, `reopened_count`.
- **`ticket_notes`** table/model — internal staff notes (`Ticket::notes()`), distinct from
  user-general `user_notes`.
- **`ticket_files.ticket_id`** added — opening-message attachments now persist against the
  ticket (`Ticket::files()`); previously the upload was validated then silently dropped and
  `TicketResource` referenced a non-existent relation.
- **`TicketService`** owns: SLA stamping on create (per-priority `config/ticketing.php`),
  order-ownership guard, status-transition timestamping + reopen counting, `assignTo`,
  `moveToDepartment`, `addNote`, and `kpis()` (volume by dept/status, SLA breaches, avg
  first-response/resolution minutes, reopen rate).
- **Admin endpoints:** `GET /admin/tickets/kpis`, `POST /admin/tickets/{ticket}/note`.
- Still out of scope: escalation automation and a working-hours calendar (SLA is currently
  a simple wall-clock window).

### Storage — private disks for sensitive files

**Financial disk (`financial`)** — financial receipts (wallet deposit, supplier-payment,
buyer order) and invoice PDFs are stored on a private **`financial`** disk
(`storage/app/financial`, NOT in the `storage:link` set, never URL-reachable). They were
previously on the `public` disk and fetchable at `/storage/...` without auth. Served only
through authenticated download endpoints (e.g. `WalletController::downloadReceipt`, which
keeps its ownership check). When adding a new financial document, store it with
`->store($dir, 'financial')`, never `'public'`. Invoice PDFs (`InvoicePdfRenderer::renderAndSave`)
are also on the `financial` disk — they are an archive only; the download endpoint
regenerates the PDF on the fly, and `invoices.file_path` is never served as a URL.

**Local disk (`local`) — job experience documents** — `JobExperienceController::store()`
saves uploaded credential files with `->store('job_experiences', 'local')` (private
`storage/app` path, no public URL). The resource returns a named download URL
(`route('job-experience.file', $id)`) instead of a raw path. `JobExperienceController::download()`
verifies ownership (`job_experience.user_id === Auth::id()`) before streaming the file.
`destroy()` deletes the stored file before removing the DB row; it guards legacy records
where `file_path` starts with `http` (old public-disk full URLs) and skips the `Storage`
call for those. Never store job experience files on the `public` disk.

### Payment gateway — Pasargad scaffold

`PasargadGateway` (registered in `PaymentGatewayFactory` as `'pasargad'`) is config-driven
(`config/payment.php` → `gateways.pasargad`) and **fail-safe**: until credentials are present
its `verify()` returns `PaymentReceipt::failed()` (never phantom-credits) and `pay()` reports
unavailable. Real bank HTTP calls go into the two marked INTEGRATION POINTs once the licence
lands. The interface contract (verify/settle via `ProcessPaymentCallbackAction`) is unchanged.

### Pre-checkout shipping quote

`POST /cart/shipping-quote` (auth) → `{ total, per_line, shipments[] }` for a chosen
`address_id` (validated to belong to the user). `GET /shipping-methods` (public) lists
methods. `CartShippingQuoteService::lineLogistics()` is the **single source of truth** for
per-line weight/count, shared with `PlaceOrderAction`, so the quote equals the freight
charged at placement.

### Wallet withdrawal — automatic Podium settlement (PAYA/SATNA)

A user-requested withdrawal pays out to the user's **approved** bank account (shaba) via a
Podium PAYA/SATNA settlement. Flow:

1. `WalletController::requestWithdrawal` → `RequestWithdrawalAction`: cache-lock + DB tx,
   debits the wallet immediately and creates a `WalletTransaction(type=withdraw,
   **status=PENDING**, meta.settlement.state='awaiting_approval')`. (`DebitWalletAction` now
   takes an optional `$status` — default CONFIRMED for other callers; withdrawal passes PENDING.)
2. Admin approves via the existing `POST /admin/wallet/transactions/{transaction}/status`
   (`ProcessTransactionAction`). When the transaction is a withdraw + confirm, it delegates to
   `SettleWithdrawalAction` (the Podium call must NOT run inside the deposit-confirm DB tx).
   Rejecting a withdraw still refunds the wallet via the existing path.
3. `SettleWithdrawalAction` is a **3-phase state machine** so a DB row lock is never held
   across the HTTP call: (A) lock + claim `settlement.state='sending'`, (B) call Podium with
   `uniqueId='wd-{id}'` (Podium dedups → retry-safe), (C) lock + on success CONFIRMED + store
   `reference_number`, on failure back to PENDING (`state='failed'`) and rethrow so an admin
   can retry. The wallet was debited at request time, so a failure never loses or mints money.

- `PodiumWithdrawalService` (service 2467367 / sandbox 158267, "withdrawal with tool
  selection") is **fail-safe** like the Pasargad scaffold: `isConfigured()` is false until
  `config('podium.settlement.enabled')` is true AND `sc_product_id`+`sc_api_key` are set, and
  `SettleWithdrawalAction` guards on it — a half-configured env can never move money.
- Tool is chosen by amount: `>= config('podium.settlement.satna_threshold')` → SATNA, else PAYA.
- Money leaves the **Podium digital wallet** (`PODLAND_WALLET`, scoped by `PODIUM_BUSINESS_TOKEN`),
  fed from the company's Pasargad deposit account in `config('podium.deposit')` — NOT a live
  per-payout debit of the bank account. PAYA costs a 12,000-Rial fee from our Pod wallet.
- `config('podium.settlement.auto_on_request')` (default false) flips to fully-automatic
  payout on user request (no admin gate). Tests: `Financial/WithdrawalSettlementTest`
  (Http::fake + Queue::fake).

### Profile — job experiences & engineer Saze-90 flag

- **Job experiences are multi-record and editable.** `JobExperienceController` has
  `store` (no "one active request" guard — a user may register many), `update`
  (`PUT|POST /profile/job-experiences/{id}`; POST multipart when replacing the file —
  PHP can't parse uploads on a raw PUT), `destroy`, and `download`. Update/delete are
  owner-only and **blocked on APPROVED** records; any edit re-opens the record to PENDING.
- **Jalali date range.** `start_date`/`end_date` validate to `1300-01-01 .. today` (lexical
  compare on zero-padded `YYYY-MM-DD`; today = `verta()->format('Y-m-d')`). `end_date` is
  `nullable` — an ongoing project sends `null`/omits it. Never use `date_format:Y-m-d`
  (rejects valid Jalali dates) — see the global Jalali-date convention below.
- **Engineer profile `is_saze90_user`** (self-declared «کاربر سازه ۹۰») — boolean column on
  `engineer_profiles` (migration `2026_06_21_*`), wired through request/controller/resource.
  It is currently **inert** (stored only); the actual badge sync from `saze90.com` does not
  exist in this codebase (see below).
- **`EngineerController::store` partial-update fix.** Like `EmployerController`, it now loads
  the existing profile and falls back to `$existing?->field` instead of the non-existent
  `$this->field` controller properties (the silent-null data-loss bug).
  `ContractorController` and `SupplierController` have received the same fix — all four
  role-profile controllers now load `$existing` and fall back per-field.

### saze90.com badge sync — server-side proxy (`GET /api/profile/badge`)

EliteYAR badges/«نشان» come from the company's legacy site **`saze90.com`**. The working
integration was always **frontend-only** (`axios.get('https://www.saze90.com/get-certificate?
mobile=…')`), which broke under CORS. It is now proxied server-side:

- `Saze90BadgeService::getCertificate($mobile)` → `GET {base}/get-certificate?mobile=09…`
  (no auth). Live response shape (verified against the real server): `{"message": "gold"|
  "silver"|"bronze"|<code>|false, "status": 200}` — `message:false` means "no badge".
  Fail-safe: any error/`false`/empty → null. Circuit breaker + success-only cache.
  Config: `config('services.saze90.*')` (`SAZE90_BADGE_ENABLED` default true, no creds needed).
- `SyncUserBadgeAction` resolves the saze90 tier + **pioneer** (granted internally for the
  member's **first year**: `created_at > now()-1y`, NOT from saze90), and persists
  `users.badge_id` **only** for the commission tiers gold/silver/bronze (null already maps to
  pioneer/default rates in `SettlementCalculator`). It **never clears a tier on a null result**
  (a transient saze90 outage must not silently downgrade a elite yar's commission rate).
- `BadgeController::show` → `GET /api/profile/badge` returns `{badge, is_pioneer, badges}`.
  The frontend should call this instead of saze90 directly. Tests: `Profile/BadgeSyncTest`.

**Still dormant — the elite yar lifetime cap.** `get-certificate` returns only the tier, NOT
the badge **purchase amount** (`users.badge_purchase_amount`), so the cap stays dormant. The
SOAP service is live (`http://spidtech.com/api/`, WSDL at `https://www.saze90.com/api/?wsdl`,
op `getUser({Auth,UserID,CustomerType})` returning an opaque JSON string that *could* carry the
amount), but the historical hardcoded creds (`User=SpidtechCRM`) are **stale** (getUser returns
empty). `artisaninweb/laravel-soap` is kept in composer for when fresh creds / a purchase-amount
endpoint arrive — then add a SOAP path to fill `badge_purchase_amount` and the cap activates.

The old debug stubs (`check-certificate` SOAP route + a `getProfile` one-liner, both by Sajad,
both removed in `ff780e6`/`ea94207`) were never functional — do not restore them; the proxy
above supersedes them.

### Admin BackOffice content modules (`2026_07_19_*`)

Ten admin-panel modules requested by the frontend team (their spec doc `BACKEND_NEEDS_FINAL.md`
was never committed; built from the prioritized summary). All under `auth:sanctum + role.admin`
in `routes/api/admin.php`:

| Prefix | Controller | Notes |
|---|---|---|
| `/admin/banners` | `AdminBannerController` | CRUD + toggle; image on `public` disk, POST/multipart |
| `/admin/articles` | `AdminArticleController` | CRUD + toggle; unique slug; cover on `public` disk |
| `/admin/faq` | `AdminFaqController` | CRUD + toggle + `sort_order` |
| `/admin/settings` | `AdminSettingController` | GET map / PUT upsert; `Setting::allCached()` (key `settings:all`) |
| `/admin/notifications` | `AdminNotificationController` | list + `POST /send` (all or user_ids, chunked insert) + `/stats` |
| `/admin/reports/{orders,financial,users}` | `AdminReportController` | CSV only (UTF-8 BOM, `cursor()` streaming); `?from/to=Y-m-d` |
| `/admin/admins` | `AdminManagerController` | CRUD + toggle; password optional on update; **destroy = removeRole('admin') only, never deletes the user**; self-toggle/self-remove = 422 |
| `/admin/coupons` | `AdminCouponController` | CRUD + toggle (`coupons.is_active` added); used coupon (used_at ≠ null) edit/delete = 422 |
| `/admin/reviews` | `AdminReviewController` | moderation queue over new `product_reviews` table; approve / reject (reason required ≤1000); STATUS/REJECTION_REASON not fillable → `forceFill()` |
| reviews (user side) | `User\ProductReviewController` | `POST /products/{product:cuid}/reviews` (auth, throttle 10/min, one per user/product — resubmission resets to pending via unique `(user_id, product_id)`), `GET /profile/reviews` (own, incl. rejection_reason), public `GET /products/{product:cuid}/reviews` (approved only + `{stats: {count, average_rating}}`). Pinned by `Product/ProductReviewTest` |
| `/admin/pages` | `AdminPageController` | CRUD + toggle; unique slug |

Models `Banner`/`Article`/`Faq`/`Page`/`Setting`/`ProductReview` follow the constants
convention; resources live in `Http/Resources/Content/` (+ `Marketing/CouponResource`,
`Product/ProductReviewResource`). Slug validation: `regex:/^[\pL\pN\-_]+$/u` (Persian OK) —
never `Str::slug` server-side. `SupplierProfileResource` now exposes
`SupplierProfile::STATUS` (pending/approved/rejected) for the supplier-approval queue UI.
**Run `php artisan migrate`** after pulling (`banners/articles/faqs/pages/settings`,
`product_reviews`, `coupons.is_active`).

### Hardening batch (`2026_07_20_*`)

- **Coupons are wired into checkout.** `StoreOrderRequest` accepts `coupon_code`;
  `PlaceOrderAction` validates (lock) + snapshots `orders.coupon_id`/`coupon_discount_amount`
  + reduces `final_amount` + redeems inside the placement transaction.
  **The discount is a separate wallet leg, never a formula change**: at payment
  (`ProcessPreScalePaymentAction` / `ProcessReservationCompletionAction`) the buyer is charged
  formula-true POO then credited the discount WSSG→WO (`coupons.applied_description`).
  `RefundOrderToWalletAction` reverses that leg (WO→WSSG); `CancelOrderAction` releases the
  coupon only for unpaid cancellations. Preview: `POST /checkout/coupon-preview`.
  `CouponService::{validateForCheckout, calculateDiscount, redeem, releaseForOrder}` —
  pinned by `Financial/CouponServiceTest`.
  **Leg order is load-bearing, don't shuffle it:** `creditBuyer(discount)` runs *before*
  `debitBuyer(POO)` so the buyer only needs `POO − discount` in their wallet (charging
  gross first made every coupon unusable unless the buyer held the undiscounted amount),
  and `debitSystem(discount)` runs *after* `creditSystem(POO)` so WSSG can never dip
  negative. In `ProcessReservationCompletionAction` the explicit balance guard subtracts
  the discount too (`max(0, remaining − discount)`). Pinned by
  `Financial/CouponBalanceGuardTest`.
  **A coupon must not change the order's *size*:** the reservation decision reads
  `Order::reservationBasisAmount()` (pre-discount), never `final_amount` — see the
  Financial Flow section. **Code generation retries:** `coupons.code` is unique and
  `createPersonalCoupon` had no retry, so a collision threw `QueryException` and killed
  the queued `SendWinBackOffer`. It now tries up to 5 times with a progressively longer
  random suffix and raises `BusinessException` instead of a raw DB error.
  Pinned by `Financial/CouponSideEffectsTest`.
- **The EliteYAR lifetime cap is serialised by a `users` row lock.**
  `WeighbridgeVatRouter::eliteYARLifetimeCeiling()` reads `badge_purchase_amount` with
  `lockForUpdate()` **before** `applyEliteYARLifetimeCap()` sums prior payouts. Each
  weighbridge settlement only locks its own `Order` row, so without this two concurrent
  settlements for the same elite yar both read the same `alreadyPaid` and both cleared the
  ceiling. Never reorder the ceiling read after the sum, and never drop the lock — an
  uncapped elite yar (`badge_purchase_amount` NULL) is unaffected either way. Pinned by
  `Financial/EliteYARLifetimeCapTest::the_lifetime_ceiling_is_read_under_a_row_lock`.
- **Reviews are verified-purchase only.** `ProductReviewController::store` 403s unless the
  user is placer/beneficiary of a DELIVERED order containing the product.
- **Bulk notifications are queued** (`SendBulkNotificationJob`); `POST /admin/notifications/send`
  returns `{queued, target_count}` with `admin.notifications.queued`.
- **Large CSV reports are queued.** `CsvReportBuilder` is the single source of report
  queries/columns; above `config('reports.stream_max_rows')` (50k) `GenerateCsvReportJob`
  writes to the `financial` disk (`reports/`) + notifies the admin; download via
  `GET /admin/reports/downloads/{filename}` (strict filename regex).
- **Admin permission tiers.** `admin.permission:{perm}` middleware
  (`EnsureAdminHasPermission`) gates financial/content/users route groups.
  Permissions are **user-level** (`AdminPermissionSeeder` grants all to existing admins;
  `AdminManagerController` accepts `permissions[]` on store/update). The middleware is
  **fail-closed**: no permission ⇒ 403, full stop. The old BC rule ("zero stored
  permissions = full access") was removed — it inverted the intent of
  `AdminManagerController::update`, where `permissions: []` means *revoke everything*, so
  restricting an admin actually promoted them to super-admin on every gated group
  including `manage-financial` (Podium settlements + manual wallet credit). Backward
  compatibility is handled once, as data, by
  `2026_07_25_100000_backfill_admin_permissions` (grants all permissions to admins that
  had none). **Never** reintroduce a "no permissions means allow" branch. Tests that
  create an admin by hand must call `Tests\Concerns\GrantsAdminPermissions::grantAllAdminPermissions()`
  — otherwise they exercise a state no real admin is ever in. Pinned by
  `Admin/AdminPermissionGateTest`.
- **Settings keys are whitelisted** in `config/settings.php` (`allowed_keys`); unknown → 422.
- **Ticket SLA uses a business-hours calendar.** `App\Support\BusinessHours::addWorkingHours`
  (config `ticketing.business_hours`, Friday weekend); disable via
  `TICKET_BUSINESS_HOURS_ENABLED=false` for old wall-clock. `EscalateOverdueTicketsJob`
  (hourly cron) escalates overdue open tickets once: priority→urgent + TicketNote +
  `tickets.escalated_at`.
- **OTP/reset-token storage is hashed.** `App\Support\OtpSecret::{hash, matches}` (bcrypt) —
  `users.secret` never holds the raw code (column widened to 255). All writes/compares in
  `RequestOtpAction`/`AuthController`/`AccountSecurityController` go through OtpSecret;
  never reintroduce raw storage or `hash_equals` on the stored value. Deploy note: in-flight
  plaintext secrets stop matching — users just request a new code.
- **PHPUnit metadata is attribute-based** (`#[Test]`, `#[DataProvider]`) — doc-comment
  `@test`/`@dataProvider` are gone; don't add new ones (PHPUnit 12 removes support).

### Site content that the main frontend actually renders (`2026_07_26_*`)

Two tables added so that **nothing on the customer site is hardcoded**. Both are consumed
by real pages in `EliteZone_FrontEnd_Phase2` — if you change their contracts, that repo
breaks visibly.

**`faq_categories`** — `(title, slug unique, subtitle, sort_order, is_active, softDeletes)`
plus `faqs.faq_category_id` (nullable FK, `nullOnDelete`). The site's support drawer renders
each category's **title + subtitle + order**, so a free-string `faqs.category` could not
express it; that legacy column is kept for BC and still filterable, but new writes should
set `faq_category_id`. `AdminFaqCategoryController::destroy` **422s a category that still
has FAQs** (deleting it would silently orphan them out of the site menu).
Public: `GET /faq-categories` → nested `category → faqs` in ONE query
(`ContentController::faqCategories`), consumed by the support drawer, `/faq`, and the
product-page FAQ accordion (category slug **`purchase`** — that slug is a contract with
the frontend, don't rename it).

**`menu_items`** — self-referencing `parent_id` (cascadeOnDelete) + **`location`**
(`MenuItem::LOCATION_HEADER` / `LOCATION_FOOTER`). ONE table drives both the top-bar
megamenu and the footer link columns — they are the same concept and two tables would mean
two places to maintain it. `url = NULL` means **"a title, not a link"**; the frontend
renders it as non-clickable text, which is why the old `action: ''` dead links are gone.
Invariants enforced in the FormRequests (`withValidator`, not `rules`):
- depth ≤ `MenuItem::MAX_DEPTH` (3) — the megamenu design renders no deeper
- no cycles — an item may not become its own descendant (infinite render otherwise)
- `url` is either an internal path starting `/` or an absolute http(s) URL
- `destroy` 422s an item that has children (the FK cascades, so a hard delete would take a
  whole branch of the site menu down without the admin noticing)

**Caching is per-location**: `MenuItem::cacheKeyFor($location)`, and `booted()` flushes
**every** location key on save/delete/restore. Never cache the menu under the bare
`CACHE_KEY` — an admin edit would then not show up on the site.

**Route-parameter gotcha (this bit us):** the admin routes declare `{menuItem}` /
`{faqCategory}` (camelCase). `UpdateMenuItemRequest` read `$this->route('menu_item')`,
which returned `null`, so the cycle guard **silently never ran**. When a FormRequest needs
the bound model, match the parameter name in `routes/api/admin.php` exactly.

**`SiteContentSeeder`** (registered in `DatabaseSeeder`) is an idempotent `firstOrCreate`
port of the content that used to be hardcoded in the frontend (`supportData.js`,
`Questions2.jsx`, `NavMenu.jsx`, `Footer.jsx`'s `NAV_COLUMNS`) so that wiring the frontend
to the API produced **zero visual regression**. Its menu key is
`(location, parent_id, title)` — `location` is part of it because e.g. "راهنما" exists in
both the megamenu and the footer and they must not overwrite each other.

Settings gained four public keys for the footer trust badges (`enamad_image_url`,
`enamad_link`, `samandehi_image_url`, `samandehi_link`) — unset means the frontend renders
nothing at all, never a placeholder.

Pinned by `tests/Feature/Content/SiteContentTest.php` (11 tests).

### Hardening round 2 (`2026_07_20_13*`)

- **Sentry** (`sentry/sentry-laravel`) is wired via `Handler::register` reportable
  (L13: same hook works through `handleApiException` flow) — **inactive without
  `SENTRY_LARAVEL_DSN`**; never remove the `app()->bound('sentry')` guard.
- **Content soft deletes.** Banner/Article/Faq/Page use `SoftDeletes`; admin delete is
  recoverable: `?trashed=1` on index = recycle bin, `POST /{id}/restore` restores
  (route uses `{id}` + `onlyTrashed()->findOrFail` because implicit binding hides
  trashed rows). Content caches also flush on `restored`. Slug uniqueness counts
  trashed rows (intentional — restore would otherwise collide).
- **Audit trail** via spatie activity(): settings update (changed keys+values),
  coupon create/update/delete, admin manager create/update/toggle/role-removal
  (including which permissions were granted). Follow the AdminSupplierController
  activity() pattern for any new sensitive admin action.
- **Admin IP allowlist** — `admin.ip` middleware (`RestrictAdminByIp`) on the whole
  `/admin` + `/v1/podium` groups; list from `config('security.admin_ip_whitelist')`
  (env `ADMIN_IP_WHITELIST`, comma-separated IP/CIDR via Symfony IpUtils).
  **Empty list = disabled** (default) — enabling is an explicit env change.
- **OpenAPI export** — `api.json` (Scramble) is committed at repo root; regenerate
  with `php artisan scramble:export` after route/request changes.
- Pinned by `Admin/AdminHardeningRound2Test` (8 tests).

## Key Conventions

- All API responses use the `success()`/`error()` helpers — never return raw `response()->json()` from authenticated endpoints
- Model column names are accessed via class constants (`Order::STATUS`, not `'status'`)
- Translations use Laravel's `__()` with keys like `'orders.cannot_cancel'`, `'wallet.insufficient_balance'`
- **FormRequest `messages()` must use `__()` calls** — never return hardcoded Persian strings. Each validation message must have a matching key in the relevant `lang/fa/*.php` file (e.g. `orders.store_address_required`). The same rule applies to controller response messages passed to `success()`/`error()`
- **Never hardcode user-facing strings anywhere in PHP** — all strings that could appear in an API response or be stored as a user-visible description must go through `__('domain.key')`. The only exception is internal log messages (passed to `Log::error()` etc.) which may remain in English
- Persian dates use the `PersianDate` trait (provides `created_at_p`, `created_at_diff` appended attributes via Verta)
- **Validate Jalali date inputs with `regex:/^\d{4}-\d{2}-\d{2}$/`, never `date_format:Y-m-d`** — the Gregorian rule rejects valid Jalali dates (e.g. `1402-06-31`, Shahrivar has 31 days). For an `end >= start` check on two Jalali strings, compare lexically (a closure rule), not `after_or_equal`. `birth_date` and `StoreJobExperienceRequest` follow this.
- Phone numbers are normalized to `09xxxxxxxxx` format using `PhoneNumber::to09($mobile)` from `app/Support/PhoneNumber.php` — **never** write a local helper
- Verification codes are stored in `users.secret` with expiry in `users.secret_expires_at`; generated by `generateVerifyCode()` using `random_int` (CSPRNG) — never `mt_rand`
- Financial amounts are in **Rials** (not Tomans)
- The system admin wallet user ID is `config('financial.system_user_id')` (default 1) — read from `config/financial.php`, **never hardcode**
- All financial thresholds/fees are in `config/financial.php` — always use `config('financial.*')` or the Action's static helper (e.g. `PayHoldFeeAction::fee()`)
- **Never call `env()` outside config files** — `env()` returns `null` in production once `php artisan config:cache` is run. All `env()` calls belong exclusively in `config/*.php` files; application code must use `config('key')`.
- The front-end SPA base URL is `config('app.front_url')` (env `FRONT_APP_URL`) — used in transactional emails
- API-docs authorised emails are `config('app.api_docs_emails')` (env `APP_API_DOCS_EMAILS`, comma-separated)
- **Uploads that land on the `public` disk must never accept `svg`** — the file is served from the app's own origin, so an SVG carrying `<script>` is stored XSS and `script-src 'self'` does not stop it. Keep the `mimes:` list identical between the Store*/Update* request pair for the same resource (they drifted once on `Badge::ICON`; pinned by `Admin/AdminBadgeTest`).

### Role-selection gate — opt-in, off by default

`EnsureRoleIsSelected` (on `POST /checkout`) blocks users who have not picked a
role. It was **dead code** until this fix: the guard tested `type_id !== null`
while `AuthController::register` always stamps the placeholder `customer`
`UserType`, so it never fired. "Selected" now means an actual Laratrust role
(`engineer`/`contractor`/`employer`/`supplier`) **or** a `type_id` pointing at
one of `expert`/`employer`/`supplier` — exactly what `SetUserTypeAction` writes.
The role check runs first so a user who finished onboarding is never locked out
by a lagging `type_id`.

Because switching it on turns checkout into a 403 for role-less users, the gate
is gated on `config('auth.role_selection_gate_enabled')`, **default `false`**.
Enable it (`ROLE_SELECTION_GATE_ENABLED=true`) only once the client handles the
`ROLE_NOT_SELECTED` / `REGISTRATION_WINDOW_EXPIRED` response contract. Window
length is `config('auth.role_selection_window_hours')` (default 2). Never
hardcode the 2h literal, and keep `meta.hours_passed` cast to `int` — Carbon 3
on the L13 branch returns a float from `diffInHours` and the payload must be
identical on both branches. Pinned by `Auth/RoleSelectionGateTest`.
