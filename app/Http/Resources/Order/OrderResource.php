<?php

namespace App\Http\Resources\Order;

use App\Enums\Shared\StatusTone;
use App\Http\Resources\BaseJsonResource;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderCancelRequest;
use App\Models\OrderExtensionOffer;
use App\Models\User;
use App\Services\Financial\DebtEngine;
use App\Services\Sales\SalesSettings;
use App\Support\Money;
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

        // یک بار، پیش از آرایه. گاردهای ارزانش برای تقریباً همه‌ی سفارش‌ها بدونِ هیچ
        // کوئری‌ای null برمی‌گردانند — شرح در `overweightSettlementFor`.
        $overweightSettlement = $this->overweightSettlementFor($request, $viewerId, $user);
        $isAdmin = $user ? $user->hasRole('admin') : false;
        $isEliteYar = in_array(OrderDocumentCatalog::AUDIENCE_ELITE_YAR, $viewerRoles, true);

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

                // ‏`quantity_details` روی مدل به array کست می‌شود، پس شاخهٔ رشته‌ایِ
                // قبلی و چکِ `is_array` هرگز اجرا نمی‌شدند — و `! empty()` روی آرایه
                // یعنی ناتهی، پس `count() > 0` هم همیشه درست بود. larastan روی L13
                // هر سه را «همیشه‌ثابت» می‌گیرد؛ اینجا حذف شده‌اند، نه سرکوب.
                if ($val <= 0 && ! empty($item->quantity_details)) {
                    $details = $item->quantity_details;
                    $val = (float) reset($details);
                    $unit = 'عدد';
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

            // --- پرداخت‌کننده ---
            //
            // ⚠️ نه `beneficiary_user_id` و نه `payer_user_id` در پاسخ نبودند، در
            // حالی که **تأیید تحویل payer-only است** (نه placer). یعنی فرانت
            // هیچ راهی نداشت بفهمد دکمه‌ی «تأیید تحویل» را نشان بدهد یا نه، و
            // مجبور بود از روی ذی‌نفع **حدس** بزند — که در خرید-برای-دیگری
            // اتفاقاً درست درمی‌آید ولی قرارداد نیست.
            //
            // ‏`payer_user_id` روی سفارشِ معمولی خالی است و پرداخت‌کننده همان
            // ثبت‌کننده است؛ `is_self` همین را یکجا جواب می‌دهد تا کلاینت
            // مجبور به بازسازیِ قاعده نشود.
            'payer' => [
                'id' => $this->{Order::PAYER_USER_ID} ?? $this->{Order::PLACER_USER_ID},
                'is_self' => ($this->{Order::PAYER_USER_ID} ?? $this->{Order::PLACER_USER_ID}) === $viewerId,
            ],

            // --- رضایتِ دوطرفهٔ اختلافِ وزن (بندِ ۲ تصمیمِ مدیریت ۲۰۲۶-۰۹-۰۹) ---
            //
            // ⚠️ چهار ستونِ `overweight_*` و چهار روتِ `overweight/{approve,accept,
            // reject,settle}` از فاز ۸ وجود داشتند، ولی **هیچ Resourceای هیچ‌کدام
            // را سریالایز نمی‌کرد**. یعنی دکمه بود و داده نبود: هیچ طرفی نمی‌دید
            // که منتظرِ امضای اوست، مبلغِ اختلاف چقدر است، طرفِ مقابل امضا کرده
            // یا نه، و مهلت کِی تمام می‌شود.
            //
            // تا پیش از `b720a2a` این فقط اضافه‌بارِ بالای ۲۰٪ را می‌گرفت — نادر.
            // با برداشتنِ آستانه، **هر** اختلافِ وزن وارد این جریان می‌شود، پس
            // شکاف از «نادر» به «همیشه» رفت: سفارش در `awaiting_weight_correction`
            // می‌ماند و کرونِ ۴۸ ساعته ردش می‌کند، بدونِ اینکه کسی رابطی داشته باشد.
            // (تشخیصِ نشستِ پنلِ تأمین‌کننده.)
            //
            // کلید فقط وقتی می‌آید که درخواستی باز باشد — نبودنش یعنی «درخواستی
            // نیست»، نه «صفر ریال».
            'overweight' => $this->when(
                (float) ($this->{Order::OVERWEIGHT_PENDING_AMOUNT} ?? 0) > 0.0,
                function () use ($viewerId, $isSupplier) {
                    $supplierApproved = $this->{Order::OVERWEIGHT_SUPPLIER_APPROVED_AT};
                    $buyerApproved = $this->{Order::OVERWEIGHT_BUYER_APPROVED_AT};
                    $isBuyer = $this->payerUserId() === $viewerId;

                    return [
                        // مثبت است حتی برای کم‌تحویلی — جهت از وزن‌ها معلوم است.
                        'pending_amount' => (string) $this->{Order::OVERWEIGHT_PENDING_AMOUNT},
                        'deadline' => $this->{Order::OVERWEIGHT_DEADLINE},
                        'supplier_approved_at' => $supplierApproved,
                        'buyer_approved_at' => $buyerApproved,

                        // هر دو مشتق عمداً سمتِ سرورند.
                        //
                        // ترتیب اجباری است — **اول تأمین‌کننده، بعد خریدار** — و اگر
                        // هر کلاینت خودش استنتاجش کند، دو پیاده‌سازی از یک قاعده
                        // می‌شود که بی‌صدا واگرا می‌شوند. همان دلیلی که
                        // `payer.is_self` هم این‌جا حساب می‌شود نه در کلاینت.
                        'awaiting_me' => ($isSupplier && $supplierApproved === null)
                            || ($isBuyer && $supplierApproved !== null && $buyerApproved === null),
                        'is_approved' => $this->isOverweightApproved(),

                        // جهتِ اختلاف، تا کلاینت مجبور نباشد دو وزن را مقایسه کند.
                        'direction' => (float) ($this->{Order::WEIGHT_AFTER_SCALE} ?? 0)
                            > (float) ($this->{Order::WEIGHT_BEFORE_SCALE} ?? 0) ? 'over' : 'under',
                    ];
                }
            ),

            // --- تسویه‌ی بدهیِ اضافه‌بار (شاخه‌ی ۷ب) — شکافِ ۱۶.۱ ---
            //
            // روتِ `overweight/settle` از قبل بود، ولی هیچ فیلدی به کلاینت نمی‌گفت کدام
            // سفارش بدهیِ بازِ اضافه‌بار دارد: بلوکِ `overweight` بالا فقط در شاخه‌ی ۷الف
            // می‌آید (`overweight_pending_amount > 0`) و در ۷ب آن ستون صفر شده است. پس
            // دکمه‌ی تسویه در سایت ساخته نشد، و خریداری که بارش پشتِ بدهی متوقف بود
            // از داخلِ سایت هیچ راهی برای آزادکردنش نداشت.
            //
            // قرینه‌ی بلوکِ `overweight` و با همان قاعده: نیامدنش یعنی «چیزی برای تسویه
            // نیست»، نه صفر ریال.
            'overweight_settlement' => $this->when($overweightSettlement !== null, $overweightSettlement),

            // ── [flow2] رزرو، تمدیدِ با توافق، لغوِ دوطرفه (پرسش ۳ و ۴ مدیریت) ──
            //
            // تا این نشست **هیچ Resourceای مهلتِ رزرو را سریالایز نمی‌کرد** — پنلِ
            // تأمین‌کننده `reserve_deadline` می‌خواند و همیشه null می‌گرفت. کلیدها
            // فقط وقتی می‌آیند که موضوعیت دارند؛ نیامدنِ `extension` یعنی «پیشنهادی
            // نیست» و نیامدنِ `cancel_request` یعنی «درخواستی نبوده».
            //
            // `awaiting_me`/`can_*` سمتِ سرور حساب می‌شوند، به همان دلیلِ بلوکِ
            // `overweight`: دو کلاینت که خودشان قاعده را بازسازی کنند، واگرا می‌شوند.
            'reservation' => $this->when(
                $this->{Order::STATUS} === Order::STATUS_RESERVED && $this->{Order::RESERVE_DEADLINE} !== null,
                fn () => [
                    'deadline' => $this->{Order::RESERVE_DEADLINE},
                    'original_deadline' => $this->{Order::RESERVE_DEADLINE_ORIGINAL},
                    'grace_hours' => (int) ($this->{Order::RESERVE_GRACE_HOURS} ?? 0),
                    // لحظه‌ای که کرون لغو می‌کند — مهلتِ پاسخ به پیشنهادِ تمدید هم همین است.
                    'ends_at' => $this->reserveEndsAt(),
                    'has_deadline_passed' => $this->{Order::RESERVE_DEADLINE}->isPast(),
                    'is_expired' => $this->isReserveWindowExpired(),
                    'can_propose_extension' => $isSupplier
                        && $this->pendingExtensionOffer === null
                        && $this->canProposeReserveExtension(app(SalesSettings::class)->reserveWarningThreshold()),
                ],
            ),

            'extension' => $this->when(
                $this->pendingExtensionOffer !== null,
                fn () => [
                    'id' => $this->pendingExtensionOffer->getKey(),
                    'hours' => $this->pendingExtensionOffer->{OrderExtensionOffer::HOURS},
                    'proposed_until' => $this->pendingExtensionOffer->{OrderExtensionOffer::PROPOSED_UNTIL},
                    'proposed_at' => $this->pendingExtensionOffer->created_at,
                    // خریدار باید تا پایانِ ارفاق پاسخ دهد، وگرنه کرون رزرو را لغو می‌کند.
                    'respond_until' => $this->reserveEndsAt(),
                    'awaiting_me' => $this->payerUserId() === $viewerId,
                ],
            ),

            'cancel_request' => $this->when(
                $this->latestCancelRequest !== null,
                function () use ($viewerId, $isSupplier) {
                    $r = $this->latestCancelRequest;
                    $isBuyer = $this->payerUserId() === $viewerId;
                    $mySide = $isBuyer ? OrderCancelRequest::SIDE_BUYER : ($isSupplier ? OrderCancelRequest::SIDE_SUPPLIER : null);

                    return [
                        'id' => $r->getKey(),
                        'status' => $r->{OrderCancelRequest::STATUS},
                        'requested_by' => $r->{OrderCancelRequest::REQUESTED_BY},
                        'is_mine' => $mySide !== null && $mySide === $r->{OrderCancelRequest::REQUESTED_BY},
                        'reason' => $r->{OrderCancelRequest::REASON},
                        'requested_at' => $r->created_at,
                        'respond_until' => $r->{OrderCancelRequest::RESPOND_UNTIL},
                        'awaiting_me' => $r->isPending() && $mySide !== null && $mySide === $r->counterpartySide(),
                        'counterparty_decision' => $r->{OrderCancelRequest::COUNTERPARTY_DECISION},
                        'counterparty_reason' => $r->{OrderCancelRequest::COUNTERPARTY_REASON},
                        'escalated_at' => $r->{OrderCancelRequest::ESCALATED_AT},
                        'admin_decision' => $r->{OrderCancelRequest::ADMIN_DECISION},
                        'admin_reason' => $r->{OrderCancelRequest::ADMIN_REASON},
                        'decided_at' => $r->{OrderCancelRequest::DECIDED_AT},
                    ];
                },
            ),

            // --- EliteYAR Info (الیت یارِ سفارش — مستقیم از elite_yar_user_id) ---
            'elite_yar' => $eliteYARUser ? [
                'id' => $eliteYARUser->id,
                'full_name' => $eliteYARUser->full_name,
                'is_self' => $eliteYARUser->id === $viewerId,
            ] : null,

            // --- Financial Info ---
            'financial' => [
                // شماره‌های سند — تب‌های «اسناد مالی» بک‌آفیس دقیقاً برای نشان‌دادنِ
                // همین‌اند و هیچ‌کدام سریالایز نمی‌شد، پس آن گزارش‌ها فقط فهرستی از
                // شماره‌ی سفارش بودند و تیم فرانت ستون‌های همیشه-`—` را برداشت.
                //
                // گیت به همان قاعده‌ی `partyRolesFor()` گره خورده است: ادمین همه را
                // می‌بیند، و هر طرف فقط سندهای خودش را. سندِ مالیاتیِ الیت‌یار به
                // دستِ خریدار نمی‌رسد — همان نشتی که در `partyRolesFor` بسته شد.
                'refs' => array_filter([
                    Order::INITIAL_INVOICE_REF => ($isAdmin || ! $isSupplier) ? $this->{Order::INITIAL_INVOICE_REF} : null,
                    Order::FINAL_INVOICE_REF => ($isAdmin || ! $isSupplier) ? $this->{Order::FINAL_INVOICE_REF} : null,
                    Order::SYSTEM_MOADIAN_REF => ($isAdmin || ! $isSupplier) ? $this->{Order::SYSTEM_MOADIAN_REF} : null,
                    Order::WAYBILL_REF => $this->{Order::WAYBILL_REF},
                    Order::ESPADANA_ORDER_REF => ($isAdmin || $isSupplier) ? $this->{Order::ESPADANA_ORDER_REF} : null,
                    Order::SUPPLIER_MOADIAN_REF => ($isAdmin || $isSupplier) ? $this->{Order::SUPPLIER_MOADIAN_REF} : null,
                    // getAttribute و نه دسترسی جادویی: larastan بدون فایل‌های
                    // ide-helper (که gitignore شده‌اند) این یک ستون را نمی‌شناسد.
                    Order::ELITE_YAR_MOADIAN_REF => ($isAdmin || $isEliteYar) ? $this->getAttribute(Order::ELITE_YAR_MOADIAN_REF) : null,
                ], static fn ($v) => $v !== null),

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
                        // مالیاتِ معلق در مسیرِ کسریِ کیف‌پولِ خالی. ستونش از فاز ۸
                        // وجود داشت ولی **در هیچ پاسخی به تأمین‌کننده نمی‌آمد**.
                        Order::SUPPLIER_VAT_PENDING_AMOUNT => (string) ($this->{Order::SUPPLIER_VAT_PENDING_AMOUNT} ?? '0'),
                        // ⚠️ عددی که واقعاً به کار تأمین‌کننده می‌آید.
                        //
                        // تصمیمِ مدیریت (پرسش ۲ دور دوم) این است که عودتِ خریدار تا
                        // شارژِ کیف‌پولِ تأمین‌کننده معلق بماند، و
                        // `CheckSupplierBalancesJob` تنها وقتی آزاد می‌کند که
                        // `balance ≥ debt + suspendedVat` باشد. یعنی کلِ مسیر منتظرِ
                        // یک شارژ است که **مبلغش هیچ‌جا به تأمین‌کننده گفته نمی‌شد**:
                        // دو جزءِ جمع در دو ستون بودند و یکی‌شان اصلاً سریالایز
                        // نمی‌شد. حالا خودِ جمع برمی‌گردد.
                        'required_top_up' => (string) Money::toFloat(
                            Money::add(
                                (string) ($this->{Order::SUPPLIER_DEBT_AMOUNT} ?? '0'),
                                (string) ($this->{Order::SUPPLIER_VAT_PENDING_AMOUNT} ?? '0')
                            ),
                            0
                        ),
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

    /**
     * آیا بیننده بدهی‌ای روی این سفارش دارد که `overweight/settle` بپذیرد؟
     *
     * ⚠️ **باید دقیقاً همان جوابِ `RequestOverweightApprovalAction::settleShortfall` را
     * بدهد**، وگرنه دکمه یا بی‌دلیل ۴۲۲ می‌گیرد یا جایی که باید باشد نیست. برای همین
     * فرمول (`DebtEngine::outstandingOnOrder`) مشترک است، نه دو کپی.
     *
     * سه گارد به ترتیبِ هزینه، **هر سه بدونِ کوئری**:
     *   ۱. بیننده پرداخت‌کننده است — همان کسی که `assertPayer` در کنترلر می‌پذیرد
     *   ۲. سفارش باسکول شده — `settleShortfall` بدونِ آن ۴۲۲ می‌دهد
     *   ۳. بیننده بدهیِ کل دارد. مانده‌ی صفر یعنی بدهیِ این سفارش **دقیقاً** صفر است
     *      (`min(orderDebt, 0)`)، نه تقریباً — پس کوئری لازم نیست. `debt_balance` روی
     *      کاربرِ احرازشده از قبل بارگذاری شده.
     *
     * فقط بدهکاری که از هر سه رد شود به `viewerDebtByOrder` می‌رسد، و آن هم یک کوئری
     * برای **کلِ درخواست** است، نه برای هر سفارش.
     *
     * @return array{outstanding_amount: string, is_load_held: bool}|null
     */
    private function overweightSettlementFor(Request $request, int|string|null $viewerId, mixed $viewer): ?array
    {
        if ($viewerId === null || $this->payerUserId() !== (int) $viewerId) {
            return null;
        }

        if ($this->{Order::WEIGHING_COMPLETED_AT} === null) {
            return null;
        }

        $balance = $viewer instanceof User ? (float) $viewer->{User::DEBT_BALANCE} : 0.0;

        if ($balance <= 0.0) {
            return null;
        }

        $orderDebt = $this->viewerDebtByOrder($request, (int) $viewerId)[(int) $this->getKey()] ?? 0.0;
        $outstanding = DebtEngine::outstandingOnOrder($orderDebt, $balance);

        if ($outstanding <= 0.0) {
            return null;
        }

        return [
            'outstanding_amount' => (string) $outstanding,
            // `settleShortfall` بارِ متوقف را از همین وضعیت آزاد می‌کند. خریدارِ درونِ
            // سقفِ اعتبار هم بدهی دارد، ولی بارش `SHIPPED` است و متوقف نیست.
            'is_load_held' => $this->{Order::STATUS} === Order::STATUS_AWAITING_WEIGHT_CORRECTION,
        ];
    }

    /**
     * جمعِ بدهیِ بیننده به‌تفکیکِ سفارش — **یک کوئری برای کلِ درخواست**.
     *
     * `OrderResource` در دوازده اندپوینتِ فهرستی سریالایز می‌شود. محاسبه‌ی بدهیِ هر
     * سفارش جداگانه یعنی یک کوئری به‌ازای هر ردیف (N+1). به‌جایش اولین بار همه‌ی
     * سفارش‌های بدهکارِ این کاربر یک‌جا جمع و روی خودِ Request نگه داشته می‌شود.
     *
     * ⚠️ روی `$request->attributes`، **نه `once()`**: `once()` تا پایانِ پروسه می‌ماند،
     * و تستی که پشتِ سرِ هم چند درخواست می‌زند (قبل و بعد از تسویه) بدهیِ کهنه‌ی
     * درخواستِ قبلی را می‌گرفت. هر Request حافظه‌ی تازه دارد.
     *
     * جمع شاملِ **همه‌ی** انواعِ بدهیِ این سفارش است، نه فقط اضافه‌بار — چون
     * `outstandingForOrder` همین را جمع می‌زند و این دو نباید اختلاف داشته باشند.
     *
     * @return array<int, float>
     */
    private function viewerDebtByOrder(Request $request, int $viewerId): array
    {
        $key = 'order_resource.debt_by_order.'.$viewerId;
        $cached = $request->attributes->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $map = DebtEntry::query()
            ->where(DebtEntry::USER_ID, $viewerId)
            ->whereNotNull(DebtEntry::ORDER_ID)
            ->groupBy(DebtEntry::ORDER_ID)
            ->selectRaw(DebtEntry::ORDER_ID.' as order_id, SUM('.DebtEntry::AMOUNT.') as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->order_id => (float) $row->total])
            ->all();

        $request->attributes->set($key, $map);

        return $map;
    }
}
