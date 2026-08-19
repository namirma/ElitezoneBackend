<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Shipping;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Order\ReturnDamageResource;
use App\Models\Order;
use App\Models\ReturnDamage;
use App\Models\Shipment;
use App\Services\Shipping\ReturnDamageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * جریان سه‌مرحله‌ای خسارت عودت — فاز ۱۱، بند ۷.۳/۷.۴/۷.۶ سند.
 *
 * سه اکتور و سه اندپوینت، عمداً جدا: تأمین‌کننده ثبت می‌کند، بک‌آفیس تأیید
 * می‌کند، خریدار تأیید یا رد می‌کند. ادغامشان در یک اندپوینتِ «تأیید» یعنی
 * نقشِ تأییدکننده از بدنه‌ی درخواست خوانده شود، که قابل جعل است.
 */
class ReturnDamageController extends Controller
{
    /** POST /api/profile/shipments/{shipment}/damages — تأمین‌کننده. */
    public function store(Request $request, Shipment $shipment, ReturnDamageService $service): JsonResponse
    {
        // ‏`Order::find` و نه `$shipment->order` — روی شاخه‌ی L13 دومی
        // non-nullable تایپ می‌شود و گاردِ null «همیشه false» گزارش می‌شود.
        /** @var Order|null $order */
        $order = Order::whereKey($shipment->{Shipment::ORDER_ID})->first();

        if (! $order) {
            throw new BusinessException(__('shipments.not_part_of_order'), 404);
        }

        $this->authorize('manageAsSupplier', $order);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
        ], [
            'amount.required' => __('shipments.damage_amount_required'),
            'amount.min' => __('shipments.damage_amount_min'),
            'description.required' => __('shipments.damage_description_required'),
        ]);

        $damage = $service->record($shipment, (float) $data['amount'], $data['description']);

        return created(new ReturnDamageResource($damage), __('shipments.damage_recorded'));
    }

    /** POST /api/admin/damages/{damage}/approve — بک‌آفیس. */
    public function approveByBackoffice(ReturnDamage $damage, ReturnDamageService $service): JsonResponse
    {
        $service->approveByBackoffice($damage, (int) Auth::id());

        activity()
            ->performedOn($damage)
            ->causedBy(Auth::user())
            ->withProperties(['amount' => $damage->{ReturnDamage::AMOUNT}])
            ->log('admin.return_damage.approved');

        return success(new ReturnDamageResource($damage->fresh()), __('shipments.damage_backoffice_approved'));
    }

    /** POST /api/profile/damages/{damage}/approve — خریدار. */
    public function approveByBuyer(ReturnDamage $damage, ReturnDamageService $service): JsonResponse
    {
        $this->assertBuyer($damage);

        $service->approveByBuyer($damage);

        return success(new ReturnDamageResource($damage->fresh()), __('shipments.damage_buyer_approved'));
    }

    /**
     * POST /api/profile/damages/{damage}/reject — خریدار.
     *
     * بند ۳.۱۱: «عدم تأیید خریدار ⇒ تیکت پشتیبانی». رد کردن یک مسیرِ معتبر
     * است و هیچ‌چیزی را مالی نمی‌کند؛ ادامه‌اش از راه پشتیبانی است.
     */
    public function rejectByBuyer(Request $request, ReturnDamage $damage, ReturnDamageService $service): JsonResponse
    {
        $this->assertBuyer($damage);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $service->reject($damage, $data['reason']);

        return success(new ReturnDamageResource($damage->fresh()), __('shipments.damage_rejected'));
    }

    /**
     * POST /api/profile/damages/{damage}/settle — خریدار می‌پردازد.
     *
     * پس از این، محموله به `pending` برمی‌گردد و آماده‌ی حمل مجدد است.
     */
    public function settle(ReturnDamage $damage, ReturnDamageService $service): JsonResponse
    {
        $this->assertBuyer($damage);

        $service->settle($damage);

        return success(new ReturnDamageResource($damage->fresh()), __('shipments.damage_settled'));
    }

    /**
     * فقط پرداخت‌کننده — نه ثبت‌کننده.
     *
     * در خرید برای دیگری بدهی روی کارفرما می‌نشیند و پول از کیف پول او می‌رود،
     * پس تأیید و پرداختش هم کارِ اوست. الیت‌یار نباید بتواند برای کارفرما
     * بدهی بپذیرد.
     */
    private function assertBuyer(ReturnDamage $damage): void
    {
        /** @var Order|null $order */
        $order = Order::whereKey($damage->{ReturnDamage::ORDER_ID})->first();

        if (! $order || (int) $order->payerUserId() !== (int) Auth::id()) {
            throw new BusinessException(__('global.forbidden'), 403);
        }
    }
}
