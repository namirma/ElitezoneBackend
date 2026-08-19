<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\ReturnDamage;
use Illuminate\Http\Request;

/**
 * @mixin \App\Models\ReturnDamage
 */
class ReturnDamageResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ReturnDamage::ID => $this->id,
            ReturnDamage::SHIPMENT_ID => $this->shipment_id,
            ReturnDamage::ORDER_ID => $this->order_id,
            ReturnDamage::AMOUNT => (string) $this->amount,
            ReturnDamage::DESCRIPTION => $this->description,

            // وضعیت مشتق‌شده — فرانت روی این شاخه بزند، نه روی خالی‌بودنِ
            // تک‌تکِ مهرهای زمانی. ترتیب مراحل ممکن است بعداً عوض شود.
            'state' => $this->state(),

            'timeline' => [
                'recorded_at' => $this->created_at?->toIso8601String(),
                'backoffice_approved_at' => $this->backoffice_approved_at?->toIso8601String(),
                'buyer_approved_at' => $this->buyer_approved_at?->toIso8601String(),
                'settled_at' => $this->settled_at?->toIso8601String(),
                'rejected_at' => $this->rejected_at?->toIso8601String(),
            ],

            ReturnDamage::REJECTION_REASON => $this->rejection_reason,
        ];
    }
}
