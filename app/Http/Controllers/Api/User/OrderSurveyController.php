<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;

use App\Http\Requests\Order\StoreOrderSurveyRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderSurveyController extends Controller
{
    public function store(StoreOrderSurveyRequest $request, Order $order): JsonResponse
    {
        $user = Auth::user();
        $validated = $request->validated();

        if ($order->{Order::PLACER_USER_ID} !== $user->id && $order->{Order::BENEFICIARY_USER_ID} !== $user->id) {
            return error(__('auth.forbidden'), [], 403);
        }
        if ($order->{Order::STATUS} !== Order::STATUS_DELIVERED) {
            return error(__('orders.survey_not_allowed_for_status'), [], 400);
        }

        if ($order->{Order::SURVEY_STATUS} === Order::SURVEY_COMPLETED) {
            return error(__('orders.survey_already_submitted'), [], 400);
        }

        $survey = DB::transaction(function () use ($order, $user, $validated) {
            // 4. ذخیره نظرسنجی
            $survey = $order->surveys()->create([
                'user_id' => $user->id,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]);

            $order->update([
                Order::SURVEY_STATUS => Order::SURVEY_COMPLETED,
            ]);

            return $survey;
        });

        return success($survey, __('orders.survey_submitted_successfully'));
    }
}
