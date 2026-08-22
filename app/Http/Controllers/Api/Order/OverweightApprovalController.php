<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Order;

use App\Actions\Order\RequestOverweightApprovalAction;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * جریان تأیید دومرحله‌ای اضافه‌بار — بند ۳.۷، اندپوینت‌های §۷.۳ و §۷.۴.
 *
 * دو اکتور و دو اندپوینتِ جدا، به همان دلیلِ `ReturnDamageController`: اگر یک
 * اندپوینتِ «تأیید» می‌ساختیم، نقشِ تأییدکننده باید از بدنه‌ی درخواست خوانده
 * می‌شد و قابل جعل بود.
 */
class OverweightApprovalController extends Controller
{
    /** POST /api/profile/orders/{order}/overweight/approve — تأمین‌کننده. */
    public function approveBySupplier(Order $order, RequestOverweightApprovalAction $action): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $action->approveBySupplier($order);

        return success(new OrderResource($order->fresh()), __('orders.overweight_supplier_approved'));
    }

    /** POST /api/profile/orders/{order}/overweight/accept — خریدار. */
    public function approveByBuyer(Order $order, RequestOverweightApprovalAction $action): JsonResponse
    {
        $this->assertPayer($order);

        $result = $action->approveByBuyer($order);

        return success([
            'order' => new OrderResource($order->fresh()),
            'settlement' => $result,
        ], __('orders.overweight_buyer_approved'));
    }

    /**
     * POST /api/profile/orders/{order}/overweight/reject — هر یک از دو طرف.
     *
     * رد یک مسیرِ معتبر است، نه خطا: محموله باطل و سفارش به `processing`
     * برمی‌گردد تا تأمین‌کننده بارنامه را با وزن درست دوباره ثبت کند.
     */
    public function reject(Request $request, Order $order, RequestOverweightApprovalAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $actorId = (int) Auth::id();
        $isPayer = $actorId === (int) $order->payerUserId();
        $isSupplier = $order->items()
            ->where('supplier_user_id', $actorId)
            ->exists();

        if (! $isPayer && ! $isSupplier) {
            throw new BusinessException(__('global.forbidden'), 403);
        }

        $action->reject($order, $isPayer ? 'buyer' : 'supplier', $data['reason']);

        return success(new OrderResource($order->fresh()), __('orders.overweight_rejected'));
    }

    /**
     * POST /api/profile/orders/{order}/overweight/settle — خریدار.
     *
     * شاخه‌ی ۷ب: لِگ‌های مالی قبلاً خورده‌اند و فقط بار متوقف است. خریدار
     * بدهی اضافه‌بار را می‌پردازد و محموله آزاد می‌شود.
     */
    public function settleShortfall(Order $order, RequestOverweightApprovalAction $action): JsonResponse
    {
        $this->assertPayer($order);

        $action->settleShortfall($order);

        return success(new OrderResource($order->fresh()), __('orders.overweight_shortfall_settled'));
    }

    /**
     * فقط پرداخت‌کننده — نه ثبت‌کننده.
     *
     * در «خرید برای دیگری» مابه‌التفاوت از کیف پول کارفرما می‌رود، پس پذیرشش
     * هم کارِ اوست. الیت‌یار نباید بتواند برای کارفرما هزینه بپذیرد.
     */
    private function assertPayer(Order $order): void
    {
        if ((int) $order->payerUserId() !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }
    }
}
