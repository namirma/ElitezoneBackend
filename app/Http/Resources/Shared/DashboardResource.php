<?php

namespace App\Http\Resources\Shared;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $currentUser = Auth::user();
        $viewerId = Auth::id();

        return [
            'active_orders' => $this['active_orders']->map(function ($order) use ($currentUser, $viewerId) {
                // الیت یارِ سفارش: اگر خریدار خودش «الیت یار» (type=elite_yar) باشد، خودِ اوست؛
                // وگرنه الیت یارِ نام‌برده‌شده = beneficiary.
                $placerIsEliteYAR = optional($order->placer?->type)->slug === 'elite_yar';
                $eliteYARUser = $placerIsEliteYAR
                    ? $order->placer
                    : ($order->beneficiary_user_id ? $order->beneficiary : null);
                $eliteYARIsSelf = $eliteYARUser && $eliteYARUser->id === $viewerId;

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status_text' => __('orders.status.'.$order->status),
                    'progress' => $order->dashboard_progress,
                    // تایم‌لاین الیت یار — فقط وقتی کاربر جاری همان الیت یارِ این سفارش است
                    'elite_yar_progress' => $eliteYARIsSelf
                        ? $order->elite_yar_progress
                        : null,
                    // تایم‌لاین سوپلایر — فقط برای کاربران با نقش سوپلایر
                    'supplier_progress' => $currentUser?->hasRole('supplier')
                        ? $order->supplier_progress
                        : null,
                    // خریدار / گیرنده / الیت یار — برای ساختِ عنوانِ تایم‌لاین‌ها در فرانت
                    'buyer' => [
                        'id' => $order->placer_user_id,
                        'full_name' => $order->placer?->full_name,
                    ],
                    'beneficiary' => $order->beneficiary_user_id ? [
                        'id' => $order->beneficiary_user_id,
                        'full_name' => $order->beneficiary?->full_name,
                        'is_self' => $order->beneficiary_user_id === $viewerId,
                    ] : null,
                    'elite_yar' => $eliteYARUser ? [
                        'id' => $eliteYARUser->id,
                        'full_name' => $eliteYARUser->full_name,
                        'is_self' => $eliteYARIsSelf,
                    ] : null,
                    'created_at_p' => $order->created_at_p,
                ];
            })->values(),
            'quick_access' => $this['quick_access'],
            'alerts' => $this['alerts'],
        ];
    }
}
