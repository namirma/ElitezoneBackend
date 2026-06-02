<?php

namespace App\Http\Resources\Shared;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class DashboardResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active_orders' => $this['active_orders']->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status_text' => __('orders.status.'.$order->status),
                    'progress' => $order->dashboard_progress,
                    'created_at_p' => $order->created_at_p,
                ];
            })->values(),
            'quick_access' => $this['quick_access'],
            'alerts' => $this['alerts'],
        ];
    }
}
