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

        // بارگذاری روابط مورد نیاز (شامل اقلام سفارش و پروفایل کاربر)
        $this->loadMissing(['items', 'placer.profile', 'address']);

        // دریافت دیتای پراگرس‌بار
        $progress = $this->dashboard_progress ?? [];

        // ---------------------------------------------------------
        // ۱. منطق لیبل وضعیت (دقیق و سفارشی)
        // ---------------------------------------------------------
        // ابتدا سعی می‌کنیم از فایل ترجمه orders.php بخوانیم
        $statusKey = $this->{Order::STATUS};
        $customLabel = __('orders.status.' . $statusKey);

        // اگر ترجمه پیدا نشد، از دیتای پیش‌فرض استفاده می‌کنیم
        if ($customLabel === 'orders.status.' . $statusKey) {
            $customLabel = $progress['current_status_label'] ?? 'نامشخص';
        }

        // ---------------------------------------------------------
        // ۲. ساخت لینک‌ها
        // ---------------------------------------------------------
        $canPay = $this->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID
            && $this->{Order::STATUS} !== Order::STATUS_CANCELLED;

        $paymentUrl = $canPay ? route('api.user.orders.pay_full', ['order' => $this->id]) : null;
        $invoiceUrl = route('api.user.orders.invoice', ['order' => $this->id, 'type' => 'initial']);

        // ---------------------------------------------------------
        // ۳. ساخت آرایه خروجی
        // ---------------------------------------------------------
        return [
            Order::ID           => $this->id,
            Order::ORDER_NUMBER => $this->{Order::ORDER_NUMBER},

            // --- اطلاعات وضعیت ---
            'status_info' => [
                'key'   => $this->{Order::STATUS},
                'label' => $customLabel,
                'color' => match ($this->{Order::STATUS}) {
                    Order::STATUS_DELIVERED => 'success',
                    Order::STATUS_CANCELLED, Order::STATUS_FAILED => 'danger',
                    Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED => 'warning',
                    default => 'primary',
                },
                'progress_data' => $progress,
            ],

            // --- خلاصه اقلام سفارش (نام محصول) ---
            'items_summary' => $this->items->map(function ($item) {
                $details = $item->quantity_details;

                $qty = $details['quantity'] ?? $details['value'] ?? 0;

                return [
                    'name'     => $item->product_name ?? 'محصول نامشخص',
                    'quantity' => (string) $qty,
                    'unit'     => $details['unit'] ?? 'کیلوگرم',
                ];
            }),

            // --- اطلاعات خریدار ---
            'buyer' => $this->when(!$isSupplier, function () {
                return [
                    'id'          => $this->{Order::PLACER_USER_ID},
                    'full_name'   => $this->placer->full_name ?? 'کاربر ناشناس',
                    'mobile'      => $this->placer->mobile ?? null,
                ];
            }),

            'beneficiary' => $this->when($this->{Order::BENEFICIARY_USER_ID}, function () {
                return [
                    'id'          => $this->{Order::BENEFICIARY_USER_ID},
                    'full_name'   => $this->beneficiary->full_name ?? 'نامشخص',
                    'national_id' => $this->beneficiary->profile->national_code ?? null,
                    'mobile'      => $this->beneficiary->mobile ?? null,
                ];
            }),

            // --- اطلاعات مالی ---
            'financial' => [
                Order::FINAL_AMOUNT    => (string) $this->{Order::FINAL_AMOUNT},
                Order::PAYMENT_STATUS  => $this->{Order::PAYMENT_STATUS},
                'is_paid'              => $this->{Order::PAYMENT_STATUS} === Order::PAYMENT_PAID,
                'paid_at_p'            => $this->{Order::PAID_AT} ? verta($this->{Order::PAID_AT})->format('Y/m/d H:i') : null,

                // فیلدهای اضافی برای خریدار
                $this->mergeWhen(!$isSupplier, [
                    Order::SUBTOTAL => (string) $this->{Order::SUBTOTAL},
                    Order::DISCOUNT_AMOUNT => (string) $this->{Order::DISCOUNT_AMOUNT},
                    Order::VAT_AMOUNT => (string) $this->{Order::VAT_AMOUNT},
                    Order::SHIPPING_COST => (string) $this->{Order::SHIPPING_COST},
                ]),
            ],

            // --- آدرس تحویل ---
            'delivery_address' => $this->when($this->address, function () {
                return [
                    'province'     => $this->address->province->name ?? '',
                    'city'         => $this->address->city->name ?? '',
                    'full_address' => $this->address->address,
                    'postal_code'  => $this->address->postal_code,
                    'receiver'     => $this->address->receiver_name,
                    'phone'        => $this->address->receiver_mobile,
                ];
            }),

            // --- دکمه‌های عملیاتی ---
            'actions' => [
                'download_invoice' => [
                    'label'  => 'دانلود فاکتور اولیه',
                    'url'    => $invoiceUrl,
                    'method' => 'GET',
                    'active' => true,
                ],
                'payment' => [
                    'label'  => 'پرداخت نهایی',
                    'url'    => $paymentUrl,
                    'method' => 'POST',
                    'active' => !is_null($paymentUrl),
                ]
            ],

            // --- تاریخ‌ها ---
            'dates' => [
                'created_at_p'    => $this->created_at_p,
                'created_at_diff' => $this->created_at_diff,
            ],
        ];
    }
}
