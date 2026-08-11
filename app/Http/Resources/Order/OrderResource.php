<?php

namespace App\Http\Resources\Order;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use App\Models\Order;
use App\Support\OrderDocumentCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin Order
 */
class OrderResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $user = Auth::user();
        $viewerId = Auth::id();

        // نقشِ بیننده **در همین سفارش**، نه نقشِ سراسری‌اش.
        //
        // قبلاً معیار `hasRole('supplier')` بود، که یعنی یک تأمین‌کننده وقتی برای
        // دفترِ خودش میلگرد می‌خرید، روی سفارشِ خریدِ خودش نمای سانسورشده می‌گرفت و
        // مالیات/کرایهٔ خودش را نمی‌دید. حالا که بلوکِ مالیِ تأمین‌کننده هم به همین
        // پرچم گره می‌خورد، این تفاوت دیگر صرفاً آزاردهنده نیست: با معیارِ سراسری،
        // مبلغِ تسویهٔ تأمین‌کننده روی سفارشی که او فقط خریدارش است هم ظاهر می‌شد.
        $viewerRoles = $this->partyRolesFor($viewerId);
        $isSupplier = in_array(OrderDocumentCatalog::AUDIENCE_SUPPLIER, $viewerRoles, true);
        $isAdmin = $user ? $user->hasRole('admin') : false;

        // الیت یارِ سفارش — مستقیماً از elite_yar_user_id خوانده می‌شود.
        // (قبلاً از placer.type یا beneficiary_user_id استنتاج می‌شد — حالا ستون جداست)
        $eliteYARUser = $this->{Order::ELITE_YAR_USER_ID} ? $this->eliteYAR : null;

        $progress = $this->dashboard_progress ?? [];

        $statusKey = $this->{Order::STATUS};
        $statusLabel = __('orders.status.'.$statusKey);
        if ($statusLabel === 'orders.status.'.$statusKey) {
            $statusLabel = $progress['current_status_label'] ?? __('orders.status.unknown');
        }

        // ⚠️ فضای‌نامِ خودش، نه `orders.status`: هر سه واژگانِ وضعیت (سفارش /
        // پرداخت / سهم) از رشته‌های 'pending' و 'paid' استفاده می‌کنند، پس تا وقتی
        // در یک آرایه بودند برچسبِ سهم روی وضعیتِ پرداخت هم می‌نشست و صفحه‌ی
        // جزئیات سفارش خودمتناقض می‌شد.
        // فالبک هم دیگر کلیدِ خامِ انگلیسی را به کاربر نشان نمی‌دهد.
        $paymentStatusKey = $this->{Order::PAYMENT_STATUS};
        $paymentStatusLabel = __('orders.payment_status.'.$paymentStatusKey);
        if ($paymentStatusLabel === 'orders.payment_status.'.$paymentStatusKey) {
            $paymentStatusLabel = __('orders.payment_status.unknown');
        }

        $canPay = $paymentStatusKey !== Order::PAYMENT_PAID
            && $statusKey !== Order::STATUS_CANCELLED;

        $paymentUrl = $canPay ? route('api.user.orders.pay_full', ['order' => $this->id]) : null;

        // سندِ «فاکتور» برای هر طرف سندِ دیگری است. تا پیش از این همه — از جمله
        // تأمین‌کننده — لینکِ سندِ خریدار (`initial`) را می‌گرفتند، که برایشان ۴۰۳
        // است: دکمه‌ای که همیشه شکست می‌خورد. حالا اسلاگِ نقشِ خودِ بیننده داده
        // می‌شود، و اگر بیننده هیچ سندِ متناظری ندارد دکمه غیرفعال می‌شود به‌جای
        // اینکه لینکِ مرده بگیرد. ادمین به هر سندی دسترسی دارد، پس سندِ خریدار.
        $invoiceSlug = $isAdmin ? 'initial' : null;

        foreach ($viewerRoles as $role) {
            if ($invoiceSlug !== null) {
                break;
            }

            $invoiceSlug = OrderDocumentCatalog::primarySlugFor($role);
        }

        $invoiceUrl = $invoiceSlug !== null
            ? route('api.user.orders.invoice', ['order' => $this->id, 'type' => $invoiceSlug])
            : null;

        $invoiceLabel = match ($invoiceSlug) {
            'supplier_report' => __('orders.actions.download_supplier_report'),
            'elite_yar_report' => __('orders.actions.download_elite_yar_report'),
            default => __('orders.actions.download_initial_invoice'),
        };

        return [
            Order::ID => $this->id,
            Order::ORDER_NUMBER => $this->{Order::ORDER_NUMBER},

            // --- Status Info ---
            'status_info' => [
                'key' => $statusKey,
                'label' => $statusLabel,
                'tone' => StatusTone::fromStatus($statusKey)->value,
                // 'color' نگه‌داشته شده برای سازگاری عقبگرد با نسخه‌های قدیمی فرانت
                'color' => match ($statusKey) {
                    Order::STATUS_DELIVERED => 'success',
                    Order::STATUS_CANCELLED, Order::STATUS_RETURNED_TO_ORIGIN => 'danger',
                    Order::STATUS_PENDING,
                    Order::STATUS_RESERVED,
                    Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                    Order::STATUS_PARTIALLY_DELIVERED => 'warning',
                    default => 'primary',
                },
                'progress_data' => $progress,
                'elite_yar_progress_data' => $eliteYARUser
                    ? $this->elite_yar_progress
                    : null,
                'supplier_progress_data' => $isSupplier
                    ? $this->supplier_progress
                    : null,
            ],

            // --- Items Summary ---
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
                    // relation is productVariant() — snake_case access never resolved (always null)
                    'name' => $item->productVariant?->name ?? $item->product_name ?? 'محصول',
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

            // --- Beneficiary Info (گیرنده سفارش — فقط وقتی الیت یار برای کارفرما خریداری می‌کند) ---
            // national_id از سوپلایر مخفی است — او فقط برای تحویل کالا نیاز به نام/موبایل دارد،
            // نه هویت مالیاتی/حقوقی ذی‌نفع (همان الگوی gating بلوک buyer در بالا).
            'beneficiary' => $this->when($this->{Order::BENEFICIARY_USER_ID}, function () use ($viewerId, $isSupplier) {
                return [
                    'id' => $this->{Order::BENEFICIARY_USER_ID},
                    'full_name' => $this->beneficiary->full_name ?? 'نامشخص',
                    'national_id' => $isSupplier ? null : ($this->beneficiary->profile->national_code ?? null),
                    'mobile' => $this->beneficiary->mobile ?? null,
                    'is_self' => $this->{Order::BENEFICIARY_USER_ID} === $viewerId,
                ];
            }, null),

            // --- EliteYAR Info (الیت یارِ سفارش — مستقیم از elite_yar_user_id) ---
            'elite_yar' => $eliteYARUser ? [
                'id' => $eliteYARUser->id,
                'full_name' => $eliteYARUser->full_name,
                'is_self' => $eliteYARUser->id === $viewerId,
            ] : null,

            // --- Financial Info ---
            'financial' => [
                Order::FINAL_AMOUNT => (string) $this->{Order::FINAL_AMOUNT},
                Order::PAYMENT_STATUS => $paymentStatusKey,
                'payment_status_text' => $paymentStatusLabel,
                'is_paid' => $paymentStatusKey === Order::PAYMENT_PAID,
                'paid_at_p' => $this->{Order::PAID_AT}
                    ? verta($this->{Order::PAID_AT})->format('Y/m/d H:i')
                    : null,

                $this->mergeWhen(! $isSupplier, [
                    Order::SUBTOTAL => (string) $this->{Order::SUBTOTAL},
                    Order::DISCOUNT_AMOUNT => (string) $this->{Order::DISCOUNT_AMOUNT},
                    Order::COUPON_DISCOUNT_AMOUNT => (string) ($this->{Order::COUPON_DISCOUNT_AMOUNT} ?? '0'),
                    'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
                    Order::VAT_AMOUNT => (string) $this->{Order::VAT_AMOUNT},
                    Order::SHIPPING_COST => (string) $this->{Order::SHIPPING_COST},
                ]),

                // سهمِ خودِ تأمین‌کننده.
                //
                // `final_amount` رقمِ **خریدار** است: کالا + کمیسیون + مالیات + کرایه.
                // تا پیش از این تنها عددی بود که تأمین‌کننده در صفِ کاری‌اش می‌دید،
                // یعنی پنل مبلغی را به او نشان می‌داد که هرگز دریافت نمی‌کند —
                // و PTS، همان چیزی که واقعاً به کیف پولش می‌رود، در هیچ اندپوینتِ
                // کاربرمحوری برنمی‌گشت.
                //
                // `payment_to_supplier` تک‌ستونی است که همیشه «مبلغِ جاری» را دارد:
                // PTS در پرداخت، و پس از باسکول با PTSM بازنویسی می‌شود
                // (ProcessWeighbridgeResultAction). پیش از پرداخت null است.
                $this->mergeWhen($isSupplier, [
                    'supplier_settlement' => [
                        Order::PAYMENT_TO_SUPPLIER => $this->{Order::PAYMENT_TO_SUPPLIER} !== null
                            ? (string) $this->{Order::PAYMENT_TO_SUPPLIER}
                            : null,
                        // پس از باسکول، رقمِ بالا دیگر PTS نیست بلکه PTSM است.
                        'is_post_weighbridge' => $this->{Order::WEIGHING_COMPLETED_AT} !== null,
                        'debt_pending' => (bool) $this->{Order::SUPPLIER_DEBT_PENDING},
                        Order::SUPPLIER_DEBT_AMOUNT => (string) ($this->{Order::SUPPLIER_DEBT_AMOUNT} ?? '0'),
                        // Doc O — تا وقتی ثبت نشده، هولدِ مالیاتِ تأمین‌کننده باز است.
                        'moadian_submitted' => $this->{Order::SUPPLIER_MOADIAN_REF} !== null,
                    ],
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

            // --- Shipments ---
            'shipments' => ShipmentResource::collection($this->whenLoaded('shipments')),

            // --- Actions ---
            'actions' => [
                'download_invoice' => [
                    'label' => $invoiceLabel,
                    'url' => $invoiceUrl,
                    'method' => 'GET',
                    'active' => $invoiceUrl !== null,
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
