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

        return [
            'active_orders' => $this['active_orders']->map(function ($order) use ($currentUser) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status_text' => __('orders.status.'.$order->status),
                    'progress' => $order->dashboard_progress,
                    // تایم‌لاین مشاور فنی — فقط وقتی کاربر جاری همان مشاور این سفارش است
                    'consultant_progress' => $order->beneficiary_user_id === Auth::id()
                        ? $order->consultant_progress
                        : null,
                    // تایم‌لاین سوپلایر — فقط برای کاربران با نقش سوپلایر
                    'supplier_progress' => $currentUser?->hasRole('supplier')
                        ? $order->supplier_progress
                        : null,
                    // اطلاعات خریدار و مشاور برای نمایش عنوان تایم‌لاین در فرانت‌اند
                    'buyer' => [
                        'id' => $order->placer_user_id,
                        'full_name' => $order->placer?->full_name,
                    ],
                    'beneficiary' => $order->beneficiary_user_id ? [
                        'id' => $order->beneficiary_user_id,
                        'full_name' => $order->beneficiary?->full_name,
                    ] : null,
                    'created_at_p' => $order->created_at_p,
                ];
            })->values(),
            'quick_access' => $this['quick_access'],
            'alerts' => $this['alerts'],
        ];
    }
}
