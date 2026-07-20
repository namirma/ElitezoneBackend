<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderSurveyRequest;
use App\Models\Order;
use App\Models\OrderSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * نظرسنجی پس از تحویل — دو سند مستقل طبق سند طراحی (پروسه خرید):
 *   S (buyer)     — از خریدار: placer یا beneficiary سفارش
 *   T (elite_yar) — از الیت یار: elite_yar_user_id (صاحب کمیسیون)
 *
 * هر نوع فقط یک‌بار per سفارش ثبت می‌شود (unique(order_id, type) در دیتابیس)؛
 * ثبت یکی، دیگری را بلاک نمی‌کند.
 */
class OrderSurveyController extends Controller
{
    public function store(StoreOrderSurveyRequest $request, Order $order): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validated();

        $type = $this->resolveSurveyType($order, (int) $user->id);
        if ($type === null) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($order->{Order::STATUS} !== Order::STATUS_DELIVERED) {
            return error(__('orders.survey_not_allowed_for_status'), [], 400);
        }

        if ($order->surveys()->where(OrderSurvey::TYPE, $type)->exists()) {
            return error(__('orders.survey_already_submitted'), [], 400);
        }

        $survey = DB::transaction(function () use ($order, $user, $validated, $type) {
            $survey = $order->surveys()->create([
                OrderSurvey::USER_ID => $user->id,
                OrderSurvey::TYPE => $type,
                OrderSurvey::RATING => $validated['rating'],
                OrderSurvey::COMMENT => $validated['comment'] ?? null,
            ]);

            // فلگ سطح-سفارش معنای قبلی خودش را نگه می‌دارد: «نظرسنجی خریدار انجام شد»
            // (مصرف‌کننده‌های موجود مثل پیشرفت سفارش/My Items به همین معنا تکیه دارند).
            if ($type === OrderSurvey::TYPE_BUYER) {
                $order->update([
                    Order::SURVEY_STATUS => Order::SURVEY_COMPLETED,
                ]);
            }

            return $survey;
        });

        return success($survey, __('orders.survey_submitted_successfully'));
    }

    /**
     * تعیین نوع نظرسنجی بر اساس نقش کاربر در سفارش:
     *   - الیت یارِ سفارش (حتی وقتی خودش placer است) → T
     *   - placer یا beneficiary → S
     *   - غیره → null (غیرمجاز)
     *
     * وقتی الیت یار خودش ثبت‌کننده است (خرید برای کارفرما)، نظرسنجی او T است و
     * S متعلق به beneficiary (کارفرمای گیرنده) می‌ماند — هرکدام سند خودشان.
     */
    private function resolveSurveyType(Order $order, int $userId): ?string
    {
        if ((int) $order->{Order::ELITE_YAR_USER_ID} === $userId && $order->{Order::ELITE_YAR_USER_ID} !== null) {
            return OrderSurvey::TYPE_ELITE_YAR;
        }

        if (
            (int) $order->{Order::PLACER_USER_ID} === $userId
            || (int) $order->{Order::BENEFICIARY_USER_ID} === $userId
        ) {
            return OrderSurvey::TYPE_BUYER;
        }

        return null;
    }
}
