<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\BaseJsonResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin \App\Models\Order
 */
class OrderResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $user = Auth::user();
        $viewerId = Auth::id();
        $isSupplier = $user ? $user->hasRole('supplier') : false;

        // مشاورِ سفارش: اگر خودِ خریدار «مشاور فنی» (type=expert) باشد، مشاورِ سفارش خودِ اوست
        // (چون مشاور نمی‌تواند برای خریدِ خودش مشاورِ دیگری معرفی کند)؛ در غیر این صورت
        // مشاورِ نام‌برده‌شده همان beneficiary است.
        $placerIsConsultant = optional($this->placer?->type)->slug === 'expert';
        $consultantUser = $placerIsConsultant
            ? $this->placer
            : ($this->{Order::BENEFICIARY_USER_ID} ? $this->beneficiary : null);

        $progress = $this->dashboard_progress ?? [];

        $statusKey = $this->{Order::STATUS};
        $statusLabel = __('orders.status.'.$statusKey);
        // Fallback for translation
        if ($statusLabel === 'orders.status.'.$statusKey) {
            $statusLabel = $progress['current_status_label'] ?? __('orders.status.unknown');
        }

        $paymentStatusKey = $this->{Order::PAYMENT_STATUS};
        $paymentStatusLabel = __('orders.status.'.$paymentStatusKey);
        if ($paymentStatusLabel === 'orders.status.'.$paymentStatusKey) {
            $paymentStatusLabel = $paymentStatusKey;
        }

        // Logic for action buttons
        $canPay = $paymentStatusKey !== Order::PAYMENT_PAID
            && $statusKey !== Order::STATUS_CANCELLED
            && $statusKey !== Order::STATUS_FAILED;

        $paymentUrl = $canPay ? route('api.user.orders.pay_full', ['order' => $this->id]) : null;
        $invoiceUrl = route('api.user.orders.invoice', ['order' => $this->id, 'type' => 'initial']);

        return [
            Order::ID => $this->id,
            Order::ORDER_NUMBER => $this->{Order::ORDER_NUMBER},

            // --- Status Info ---
            'status_info' => [
                'key' => $statusKey,
                'label' => $statusLabel,
                'color' => match ($statusKey) {
                    Order::STATUS_DELIVERED => 'success',
                    Order::STATUS_CANCELLED, Order::STATUS_FAILED => 'danger',
                    Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED => 'warning',
                    default => 'primary',
                },
                'progress_data' => $progress,
                'consultant_progress_data' => $consultantUser
                    ? $this->consultant_progress
                    : null,
                'supplier_progress_data' => $isSupplier
                    ? $this->supplier_progress
                    : null,
            ],

            // --- Items Summary (FIXED LOGIC) ---
            'items_summary' => $this->items->map(function ($item) {
                $val = (float) ($item->weight ?? 0);
                $unit = 'کیلوگرم';

                if ($val <= 0 && ! empty($item->quantity_details)) {
                    $details = is_string($item->quantity_details)
                        ? json_decode($item->quantity_details, true)
                        : $item->quantity_details;

                    if (is_array($details) && count($details) > 0) {
                        $val = (float) reset($details);
                        $unit = 'عدد';
                    }
                }

                if ($val <= 0) {
                    $val = (float) ($item->quantity ?? 0);
                }

                return [
                    'name' => $item->product_variant->name ?? $item->product_name ?? 'محصول',
                    'quantity' => (string) $val,
                    'unit' => $unit,
                ];
            }),

            // --- Buyer Info ---
            'buyer' => $this->when(! $isSupplier, function () {
                return [
                    'id' => $this->{Order::PLACER_USER_ID},
                    'full_name' => $this->placer->full_name ?? 'کاربر ناشناس',
                    'mobile' => $this->placer->mobile ?? null,
                ];
            }),

            // --- Beneficiary Info (گیرنده سفارش) ---
            'beneficiary' => $this->when($this->{Order::BENEFICIARY_USER_ID}, function () use ($viewerId) {
                return [
                    'id' => $this->{Order::BENEFICIARY_USER_ID},
                    'full_name' => $this->beneficiary->full_name ?? 'نامشخص',
                    'national_id' => $this->beneficiary->profile->national_code ?? null,
                    'mobile' => $this->beneficiary->mobile ?? null,
                    'is_self' => $this->{Order::BENEFICIARY_USER_ID} === $viewerId,
                ];
            }, null),

            // --- Consultant Info (مشاور فنی سفارش) — برای عنوانِ تایم‌لاین ---
            'consultant' => $consultantUser ? [
                'id' => $consultantUser->id,
                'full_name' => $consultantUser->full_name,
                'is_self' => $consultantUser->id === $viewerId,
            ] : null,

            // --- Financial Info ---
            'financial' => [
                Order::FINAL_AMOUNT => (string) $this->{Order::FINAL_AMOUNT},
                Order::PAYMENT_STATUS => $paymentStatusKey,
                'payment_status_text' => $paymentStatusLabel,
                'is_paid' => $paymentStatusKey === Order::PAYMENT_PAID,
                'paid_at_p' => $this->{Order::PAID_AT} ? verta($this->{Order::PAID_AT})->format('Y/m/d H:i') : null,

                $this->mergeWhen(! $isSupplier, [
                    Order::SUBTOTAL => (string) $this->{Order::SUBTOTAL},
                    Order::DISCOUNT_AMOUNT => (string) $this->{Order::DISCOUNT_AMOUNT},
                    Order::VAT_AMOUNT => (string) $this->{Order::VAT_AMOUNT},
                    Order::SHIPPING_COST => (string) $this->{Order::SHIPPING_COST},
                ]),
            ],

            // --- Address ---
            'delivery_address' => $this->when($this->address, function () {
                return [
                    'province' => $this->address->province->name ?? '',
                    'city' => $this->address->city->name ?? '',
                    'full_address' => $this->address->address,
                    'postal_code' => $this->address->postal_code,
                    'receiver' => $this->address->receiver_name,
                    'phone' => $this->address->receiver_mobile,
                ];
            }),

            // --- Shipments (one leg per origin factory → destination address) ---
            'shipments' => ShipmentResource::collection($this->whenLoaded('shipments')),

            // --- Actions ---
            'actions' => [
                'download_invoice' => [
                    'label' => __('orders.actions.download_initial_invoice'),
                    'url' => $invoiceUrl,
                    'method' => 'GET',
                    'active' => true,
                ],
                'payment' => [
                    'label' => __('orders.actions.final_invoice_settlement'),
                    'url' => $paymentUrl,
                    'method' => 'POST',
                    'active' => ! is_null($paymentUrl),
                ],
            ],

            // --- Dates ---
            'dates' => [
                'created_at_p' => $this->created_at_p,
                'created_at_diff' => $this->created_at_diff,
            ],
        ];
    }
}
