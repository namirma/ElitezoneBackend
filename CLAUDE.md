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
                 UploadOrderReceiptRequest, StoreOrderSurveyRequest, FinalizeOrderInvoiceRequest, OrderPriceRequest
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
    InvoiceGenerator     — orchestrator (~110 lines)
    InvoiceDataBuilder   — Blade-view data prep (line items, totals, buyer info)
    InvoicePdfRenderer   — PDF rendering + Storage::disk('public') write
  Financial/
    WalletTransactionEngine    — multi-wallet operations (owner/supplier/saze)
    SettlementCalculatorService — POO/PTS commission math
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
| `financial.hold_fee` | `HOLD_FEE_RIALS` | `50_000_000` | Fee to extend a reservation by 24h (5M Tomans) |
| `financial.system_user_id` | `FINANCIAL_SYSTEM_USER_ID` | `1` | User ID of the platform's central "Saze" wallet |

```php
// ✅ correct
config('financial.reservation_threshold')
PayHoldFeeAction::fee()   // wraps config('financial.hold_fee')

// ❌ wrong — never hardcode
1_950_000_000
self::FEE_RIALS   // deprecated constant in PayHoldFeeAction, kept for BC only
```

### Financial Flow (Critical Path)

The system has a wallet-based payment model with three entry points:

1. **WalletPaymentController** — generic wallet payment for orders
2. **OrderPaymentController::payFullOrder** — handles 3 scenarios: direct purchase, reservation (>195M Rials), and completion of reserved orders; protected by `idempotency` middleware
3. **OrderPaymentController::payHoldFee** — hold fee extension; protected by `idempotency` middleware

Financial safety pattern (should be followed everywhere):
```php
DB::transaction(function () {
    $wallet = Wallet::where('id', $id)->lockForUpdate()->first();
    if ($wallet->balance < $amount) throw new InsufficientBalanceException();
    $wallet->decrement('balance', $amount);
    // record WalletTransaction
});
```

`WalletTransactionEngine` handles complex multi-wallet operations (owner, supplier, saze system wallet). `DebitWalletAction` and `FundWalletAction` are the clean Action-based entry points.

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
- `Financial/CheckSupplierBalancesJob` — monitors supplier wallet balances
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

**Total: 110 tests, 189 assertions (all green).**

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
