<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Cart\BuildCartPreInvoiceAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\SyncCartFiltersAction;
use App\Actions\Cart\UpdateCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\StoreCartCouponRequest;
use App\Http\Requests\Cart\UpdateCartFiltersRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Requests\Cart\UpdateCustomerTypeRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartResolver;
use App\Services\Cart\CartResponseBuilder;
use App\Services\Shipping\CartShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Thin orchestrator over the Cart Actions:
 *   resolve cart → run action → build response.
 *
 * Cart resolution + context sync lives in CartResolver.
 * Pricing + response formatting lives in CartResponseBuilder.
 *
 * هر متدی که سبد را تغییر می‌دهد `resolveForMutation()` می‌گیرد، نه `resolve()`:
 * تا لینکِ پرداخت برای کارفرما باز است سبد قفل است و ۴۰۹ می‌گیرد (اول لغو،
 * بعد تغییر). گارد همان‌جا و یک‌بار نوشته شده — این‌جا فقط انتخابِ متد است.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartResolver $resolver,
        private readonly CartResponseBuilder $responder,
    ) {}

    /** GET — current cart with computed totals */
    public function getCart(Request $request): JsonResponse
    {
        return $this->responder->build($this->resolver->resolve($request));
    }

    /** POST — add an item */
    public function addToCart(AddToCartRequest $request, AddToCartAction $action): JsonResponse
    {
        $cart = $this->resolver->resolveForMutation($request);
        $action->execute($cart, $request->validated());

        return $this->responder->build($cart);
    }

    /** PATCH — update quantity / attributes of an existing item */
    public function updateCartItem(
        UpdateCartItemRequest $request,
        CartItem $item,
        UpdateCartItemAction $action,
    ): JsonResponse {
        $cart = $this->resolver->resolveForMutation($request);

        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item, $request->validated());

        return $this->responder->build($cart);
    }

    /** DELETE — remove an item */
    public function removeCartItem(
        Request $request,
        CartItem $item,
        RemoveCartItemAction $action,
    ): JsonResponse {
        $cart = $this->resolver->resolveForMutation($request);

        if ($item->cart_id !== $cart->id) {
            return error('global.notFound', [], 404);
        }

        $action->execute($item);

        return $this->responder->build($cart);
    }

    /** PATCH — change selected price-filter categories (e.g. cash/installment) */
    public function updateCartFilters(
        UpdateCartFiltersRequest $request,
        SyncCartFiltersAction $action,
    ): JsonResponse {
        $cart = $this->resolver->resolveForMutation($request);
        $action->execute($cart, $request->validated('filter_categories', []));

        return $this->responder->build($cart);
    }

    /** PATCH — change customer type (real person / legal entity / ...) */
    public function updateCustomerType(UpdateCustomerTypeRequest $request): JsonResponse
    {
        $cart = $this->resolver->resolveForMutation($request);
        $cart->update(['customer_type_id' => $request->validated('customer_type_id')]);

        return $this->responder->build($cart);
    }

    /**
     * POST — ذخیره‌ی کدِ کوپن روی سبد (بند ۷.۱).
     *
     * فقط **کد** ذخیره می‌شود، نه مبلغ. تبدیل به مبلغ در لحظه‌ی رزرو یا
     * پرداخت انجام می‌شود (بند ۳.۱۳) — و پس از باسکول با وزن واقعی بازمحاسبه
     * می‌گردد. ذخیره‌ی مبلغ در این مرحله یعنی عددی که تا پرداخت کهنه می‌شود.
     *
     * ارسال `null` کوپن را برمی‌دارد.
     */
    public function storeCoupon(StoreCartCouponRequest $request): JsonResponse
    {
        $cart = $this->resolver->resolveForMutation($request);

        $code = $request->validated('coupon_code');
        $cart->update([Cart::COUPON_CODE => $code !== null && $code !== '' ? $code : null]);

        return $this->responder->build($cart);
    }

    /**
     * GET — پیش‌فاکتور (Doc 0) از سبدِ جاری، **پیش از ثبت سفارش**.
     *
     * تا پیش از این تنها راهِ گرفتنِ پیش‌فاکتور، ثبتِ سفارشِ واقعی و بعد دانلود از
     * `/profile/orders/{order}/invoices/initial` بود — یعنی برای یک برگه‌ی قیمت،
     * موجودی کم می‌شد و یک سفارشِ دوساعته ساخته می‌شد که باید لغو می‌شد.
     *
     * هیچ سفارشی، هیچ رکورد `Invoice` و هیچ شماره‌ی سندی مصرف نمی‌شود؛ خروجی یک PDF
     * با شناسه‌ی آشکارا موقت است. با دادنِ `address_id` کرایه‌ی حمل هم در سند می‌آید.
     */
    public function preInvoice(Request $request, BuildCartPreInvoiceAction $action)
    {
        $validated = $request->validate([
            'address_id' => ['sometimes', 'nullable', 'integer', 'exists:addresses,id'],
        ]);

        $addressId = isset($validated['address_id']) ? (int) $validated['address_id'] : null;
        $user = Auth::user();

        // همان گاردِ shippingQuote — آدرسِ کسِ دیگری نباید مقصدِ محاسبه شود.
        if ($addressId && ! $user->addresses()->whereKey($addressId)->exists()) {
            return error(__('global.forbidden'), [], 403);
        }

        $cart = $this->resolver->resolve($request);

        $pdf = $action->execute($cart, $user, $addressId);

        // عمداً `$pdf->download()` نیست: آن متد `Destination::DOWNLOAD` را صدا
        // می‌زند، یعنی mPDF هدرها را خودش می‌فرستد و بایت‌ها را مستقیم echo می‌کند و
        // پاسخِ لاراول عملاً خالی و `text/html` می‌ماند. با `output()` بایت‌ها
        // برگردانده می‌شوند و پاسخ یک Response واقعی است — هم برای کلاینت درست‌تر،
        // هم قابل‌تست.
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="pre-invoice-'.$cart->id.'.pdf"',
        ]);
    }

    /**
     * POST — estimate freight for the current cart to a chosen address BEFORE checkout,
     * so the frontend can show the shipping cost up front. Uses the same logistics math
     * as order placement, so the estimate equals the amount charged at checkout.
     */
    public function shippingQuote(Request $request, CartShippingQuoteService $quoteService): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
        ]);

        $addressId = (int) $validated['address_id'];

        // The address must belong to the authenticated user.
        if (! Auth::user()->addresses()->whereKey($addressId)->exists()) {
            return error(__('global.forbidden'), [], 403);
        }

        $cart = $this->resolver->resolve($request);
        $quote = $quoteService->quoteForCart($cart, $addressId);

        return success([
            'total' => (string) $quote->total,
            'per_line' => collect($quote->perLine)->map(fn ($v) => (string) $v),
            'shipments' => collect($quote->shipments)->map(fn ($g) => [
                'factory_id' => $g->factoryId,
                'factory_location_id' => $g->factoryLocationId,
                'address_id' => $g->addressId,
                'destination_province_id' => $g->destinationProvinceId,
                'destination_city_id' => $g->destinationCityId,
                'weight' => (string) $g->weight,
                'count' => (string) $g->count,
                'cost_basis' => $g->costBasis->value,
                'cost' => (string) $g->cost,
            ])->values(),
        ]);
    }
}
