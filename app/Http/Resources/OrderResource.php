<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = Auth::user();
        $isSupplier = $user ? $user->hasRole('supplier') : false;

        return [
            Order::ID => $this->id,
            Order::ORDER_NUMBER => $this->order_number,
            Order::STATUS => $this->status,
            Order::FINAL_AMOUNT => (string) $this->final_amount,
            Order::PAYMENT_STATUS => $this->payment_status,
            Order::NOTES => $this->notes,
            Order::PAID_AT => $this->paid_at?->format('Y-m-d H:i:s'),
            Order::WEIGHBRIDGE_REGISTERED_AT => $this->weighbridge_registered_at?->format('Y-m-d H:i:s'),
            Order::FINAL_INVOICE_REF => $this->final_invoice_ref,
            Order::TAX_INVOICE_REF => $this->tax_invoice_ref,
            Order::SURVEY_STATUS => $this->survey_status,
            Order::CANCEL_REASON => $this->cancel_reason,
            Order::CREATED_AT => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
            'paid_at_p' => $this->paid_at ? verta($this->paid_at)->format('Y/m/d H:i') : null,
            'weighbridge_registered_at_p' => $this->weighbridge_registered_at ? verta($this->weighbridge_registered_at)->format('Y/m/d H:i') : null,

            $this->mergeWhen(!$isSupplier, [
                Order::PLACER_USER_ID => $this->placer_user_id,
                Order::BENEFICIARY_USER_ID => $this->beneficiary_user_id,
                Order::ADDRESS_ID => $this->address_id,
                Order::SUBTOTAL => (string) $this->subtotal,
                Order::DISCOUNT_AMOUNT => (string) $this->discount_amount,
                Order::SHIPPING_COST => (string) $this->shipping_cost,
                Order::VAT_AMOUNT => (string) $this->vat_amount,
                Order::PAYMENT_METHOD => $this->payment_method,
                Order::INITIAL_INVOICE_REF => $this->initial_invoice_ref,
                Order::EXPERT_FEE_AMOUNT => $this->whenNotNull((string) $this->expert_fee_amount),
                Order::EXPERT_FEE_PAYMENT_STATUS => $this->expert_fee_payment_status,
                Order::ENGINEER_FEE_AMOUNT => $this->whenNotNull((string) $this->engineer_fee_amount),
                Order::ENGINEER_FEE_PAYMENT_STATUS => $this->engineer_fee_payment_status,
                Order::CONTRACTOR_FEE_AMOUNT => $this->whenNotNull((string) $this->contractor_fee_amount),
                Order::CONTRACTOR_FEE_PAYMENT_STATUS => $this->contractor_fee_payment_status,
                Order::EXPERT_FEE_INVOICE_REF => $this->expert_fee_invoice_ref,
            ]),

            $this->mergeWhen($isSupplier, [
                Order::PREPARED_AT => $this->prepared_at?->format('Y-m-d H:i:s'),
                Order::SHIPPED_AT => $this->shipped_at?->format('Y-m-d H:i:s'),
                Order::PAYMENT_RECEIPT_REF => $this->payment_receipt_ref,
                Order::ESPADANA_ORDER_REF => $this->espadana_order_ref,
                Order::WAYBILL_REF => $this->waybill_ref,
                'prepared_at_p' => $this->prepared_at ? verta($this->prepared_at)->format('Y/m/d H:i') : null,
                'shipped_at_p' => $this->shipped_at ? verta($this->shipped_at)->format('Y/m/d H:i') : null,
            ]),

            'placer' => new UserResource($this->whenLoaded('placer')),
            'beneficiary' => new UserResource($this->whenLoaded('beneficiary')),
            'address' => new AddressResource($this->whenLoaded('address')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            
        ];
    }
}
