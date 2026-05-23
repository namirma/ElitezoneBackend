<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active_order' => isset($this['active_order']) ? [
                'id' => $this['active_order']->id,
                'order_number' => $this['active_order']->order_number,
                'status_text' => __('orders.status.'.$this['active_order']->status),
                'progress' => $this['active_order']->dashboard_progress,
                'created_at_p' => $this['active_order']->created_at_p,
            ] : null,
            'quick_access' => $this['quick_access'],
            'alerts' => $this['alerts'],
        ];
    }
}
