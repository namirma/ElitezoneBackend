<?php

namespace App\Models\Concerns;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Lang;

/**
 * نوار پیشرفتِ سفارش برای سه مخاطب — خریدار، تأمین‌کننده، الیت یار.
 *
 * ⚠️ هر مرحله یک `key`ِ پایدار دارد و **همهٔ شاخه‌زدن‌ها روی همان کلید است، نه
 * روی عنوانِ فارسی**. پیش از این، منطق با `$blueprint['title'] === 'بارنامه…'`
 * مقایسه می‌شد؛ یعنی اصلاحِ یک نیم‌فاصله در برچسب، بی‌صدا دکمه‌های آن مرحله را
 * خاموش می‌کرد. عنوان‌ها از `lang/fa/orders.php` می‌آیند (قید ۱۴.۷).
 */
trait HasOrderProgress
{
    /** یک مرحله‌ی نوار پیشرفت، با عنوانِ ترجمه‌شده. */
    private function progressStep(string $audience, string $key, array $triggers = []): array
    {
        return [
            'key' => $key,
            'title' => __("orders.progress.{$audience}.{$key}"),
            'triggers' => $triggers,
        ];
    }

    /** برچسبِ وضعیتِ فعلی برای هدرِ کارت. */
    private function currentStatusLabel(string $status): string
    {
        $transKey = "orders.status.{$status}";

        return Lang::has($transKey) ? __($transKey) : __('orders.status.unknown');
    }

    /**
     * حالتِ دایره‌ی هر مرحله: تکمیل‌شده / جاری / خاکستری — یا لغو.
     *
     * در حالت لغو فقط دایره‌ی اول قرمز می‌شود و بقیه خاکستری می‌مانند.
     */
    private function progressState(int $index, int $currentIndex, bool $isCancelled): string
    {
        if ($isCancelled) {
            return $index === 0 ? 'cancelled' : 'inactive';
        }

        return match (true) {
            $index < $currentIndex => 'completed',
            $index === $currentIndex => 'current',
            default => 'inactive',
        };
    }

    /**
     * پیشرفته‌ترین مرحله‌ای که ماشه‌اش خورده.
     *
     * آخرین تطبیق برنده است، نه اولین — ماشه‌ها ناهم‌پوشان‌اند و با `break` روی
     * اولین تطبیق، وضعیت‌های عقب‌تر مرحله‌ی جلوتر را می‌بلعیدند (همان باگ بند ۱۱.۴).
     */
    private function currentProgressIndex(array $blueprint, string $status): int
    {
        $currentIndex = 0;

        foreach ($blueprint as $index => $step) {
            if (in_array($status, $step['triggers'], true)) {
                $currentIndex = $index;
            }
        }

        return $currentIndex;
    }

    /**
     * نوار پیشرفتِ خریدار — بند ۱۱.۱ سند بازطراحی.
     */
    public function getDashboardProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;
        $isCancelled = $status === Order::STATUS_CANCELLED;

        // بند ۱۱.۱: شرطِ نمایشِ مرحله‌ی «رزرو» از مقایسه با آستانه به
        // `payment_to_reserve > 0` تغییر کرد. آستانه‌ی قدیمی یک قاعده‌ی مبلغی بود
        // («سفارشِ بزرگ‌تر از X باید رزرو شود») و در مدل جدید اصلاً وجود ندارد —
        // رزرو حالا انتخابِ کاربر بر پایه‌ی توانِ پرداختِ خودش است.
        $wasReserved = (float) $this->payment_to_reserve > 0;

        $stepsBlueprint = [];

        if ($wasReserved) {
            $stepsBlueprint[] = $this->progressStep('buyer', 'reserved', [
                Order::STATUS_RESERVED,
            ]);
        }

        $stepsBlueprint[] = $this->progressStep('buyer', 'placed', [
            Order::STATUS_PROCESSING, Order::STATUS_AWAITING_WEIGHT_CORRECTION,
        ]);

        $stepsBlueprint[] = $this->progressStep('buyer', 'shipped', [
            Order::STATUS_SHIPPED, Order::STATUS_RETURNED_TO_ORIGIN,
        ]);

        $stepsBlueprint[] = $this->progressStep('buyer', 'delivered', [
            Order::STATUS_PARTIALLY_DELIVERED, Order::STATUS_DELIVERED,
        ]);

        // ماشه‌ی این مرحله یک ستون است نه یک وضعیت — سفارش پس از صدور فاکتور
        // مودیان همچنان `delivered` می‌ماند.
        $stepsBlueprint[] = $this->progressStep('buyer', 'moadian');

        $moadianIssued = $this->{Order::SYSTEM_MOADIAN_REF} !== null;

        $currentIndex = 0;
        if (! $isCancelled) {
            $currentIndex = $this->currentProgressIndex($stepsBlueprint, $status);

            if ($moadianIssued) {
                $currentIndex = count($stepsBlueprint) - 1;
            }
        }

        $steps = [];

        foreach ($stepsBlueprint as $index => $blueprint) {
            $state = $this->progressState($index, $currentIndex, $isCancelled);
            $actions = [];

            // لینک‌ها فقط روی دایره‌ای که خاکستری نیست
            if ($state !== 'inactive') {
                switch ($blueprint['key']) {
                    case 'reserved':
                        $actions[] = [
                            'label' => __('orders.actions.get_receipt'),
                            'link' => "/api/profile/orders/{$id}/invoice/initial",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        // اکشنِ «پرداخت فاکتور» برداشته شد: `/checkout/payment/{id}`
                        // هیچ‌وقت روتی در SPA نداشته و react-router برای مسیرِ
                        // بی‌تطابق هیچ‌چیز رندر نمی‌کند — نه ۴۰۴، نه خطای کنسول —
                        // پس کاربر به یک پوسته‌ی خالی می‌رسید. زائد هم بود:
                        // تکمیلِ پرداخت از `OrderResource.payment_url` انجام می‌شود.
                        if ($state === 'cancelled') {
                            $actions[] = [
                                'label' => __('orders.actions.buy_again'),
                                // کاتالوگ در SPA روی `/rebar` است؛ `/products`
                                // هیچ‌وقت وجود نداشته. فرانت لینک‌های تایم‌لاین را
                                // بدون عبور از جدولِ ترجمه‌اش navigate می‌کند، پس
                                // این ۴۰۴ زنده بود.
                                'link' => '/rebar',
                                'type' => 'primary',
                            ];
                        }
                        break;

                    case 'placed':
                        $actions[] = [
                            'label' => __('orders.actions.paid_initial_invoice'),
                            'link' => "/api/profile/orders/{$id}/invoice/initial",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        break;

                    case 'shipped':
                        $actions[] = [
                            'label' => __('orders.actions.final_invoice'),
                            'link' => "/api/profile/orders/{$id}/invoice/bill_of_lading",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        // اطلاعات راننده و خودرو per-shipment روی صفحه‌ی جزئیات است.
                        $actions[] = [
                            'label' => __('orders.actions.waybill_and_driver'),
                            'link' => "/profile/orders/{$id}",
                            'type' => 'secondary',
                        ];

                        // ⚠️ دکمه‌ی «تأیید تحویل» عمداً روی مرحله‌ی **در حال حمل**
                        // است، نه روی «تحویل شد» (پاسخ مدیر، دور ۱ پرسش ۸).
                        //
                        // ماشه‌ی مرحله‌ی `delivered` خودِ همین تأیید است، پس دکمه
                        // آنجا فقط **پس از** انجامِ کاری ظاهر می‌شد که قرار بود
                        // راه بیندازد — دقیقاً همان اشتباهی که دکمه‌ی فاکتور
                        // مودیانِ تأمین‌کننده داشت و جابه‌جا شد.
                        //
                        // فقط وقتی نشان داده می‌شود که واقعاً چیزی برای تأیید
                        // باشد: محموله‌ای که راننده‌اش رسیده و هنوز نه تحویل شده
                        // و نه عودت.
                        if ($state === 'current' && $this->hasShipmentAwaitingConfirmation()) {
                            $actions[] = [
                                'label' => __('orders.actions.confirm_delivery'),
                                'link' => "/profile/orders/{$id}",
                                'type' => 'primary',
                            ];
                        }
                        break;

                    case 'delivered':
                        // تحویلِ جزئی: بخشی رسیده و بخشی هنوز در راه است، پس
                        // دکمه باید همچنان در دسترس باشد.
                        if ($state === 'current' && $this->hasShipmentAwaitingConfirmation()) {
                            $actions[] = [
                                'label' => __('orders.actions.confirm_delivery'),
                                'link' => "/profile/orders/{$id}",
                                'type' => 'primary',
                            ];
                        }
                        break;

                    case 'moadian':
                        $actions[] = [
                            'label' => __('orders.actions.download_moadian_invoice'),
                            'link' => "/api/profile/orders/{$id}/invoice/tax_buyer",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        break;
                }
            }

            $steps[] = [
                'step' => $index + 1,
                // کلیدِ پایدارِ مرحله. داک‌بلاکِ همین تریت می‌گوید «همهٔ شاخه‌زدن‌ها
                // روی کلید است نه عنوانِ فارسی» — ولی کلید سریالایز نمی‌شد، پس
                // کلاینت چاره‌ای جز تطبیق روی همان عنوان نداشت و بازطراحیِ
                // برچسب‌ها بی‌صدا آیکونِ سه مرحله را خاموش کرد.
                'key' => $blueprint['key'],
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        // وقتی آخرین ماشه هم خورده، آخرین دایره باید سبز شود نه آبی.
        if ($moadianIssued) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $this->currentStatusLabel($status),
            'steps' => $steps,
        ];
    }

    /**
     * نوار پیشرفت از دیدِ الیت یار — بند ۱۱.۳.
     *
     * ساختارِ چهارمرحله‌ای درست است و می‌ماند؛ وضعیتِ هر مرحله از فیلدهای مدل
     * تعیین می‌شود نه فقط ستون `status`، تا رویدادهایی مثل ثبت فاکتور مودیان هم
     * منعکس شوند.
     */
    public function getEliteYARProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;
        $isCancelled = $status === Order::STATUS_CANCELLED;

        $stepsBlueprint = [
            $this->progressStep('elite_yar', 'purchased'),
            $this->progressStep('elite_yar', 'commission_booked'),
            $this->progressStep('elite_yar', 'invoice_submitted'),
            $this->progressStep('elite_yar', 'commission_available'),
        ];

        // از آخر به اول: ReleaseEliteYARHoldAction هر دو فیلد را اتمیک ست می‌کند،
        // پس مرحله‌ی ۳ عملاً هرگز «جاری» دیده نمی‌شود ولی منطقاً پوشش دارد.
        $holdReleased = $this->{Order::ELITE_YAR_HOLD_RELEASED_AT} !== null;
        $currentIndex = -1;

        if (! $isCancelled) {
            if ($holdReleased) {
                $currentIndex = 3;
            } elseif ($this->{Order::ELITE_YAR_MOADIAN_REF} !== null) {
                $currentIndex = 2;
            } elseif (in_array($status, [
                Order::STATUS_SHIPPED,
                Order::STATUS_PARTIALLY_DELIVERED,
                Order::STATUS_RETURNED_TO_ORIGIN,
                Order::STATUS_DELIVERED,  // کالا تحویل شده ولی Doc Q هنوز ثبت نشده
            ], true)) {
                $currentIndex = 1;
            } elseif (in_array($status, [
                Order::STATUS_PROCESSING,
                Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                Order::STATUS_RESERVED,
            ], true)) {
                $currentIndex = 0;
            }
        }

        $steps = [];

        foreach ($stepsBlueprint as $index => $blueprint) {
            if ($isCancelled) {
                $state = $index === 0 ? 'cancelled' : 'inactive';
            } elseif ($currentIndex === -1) {
                $state = 'inactive'; // هنوز هیچ مرحله‌ای شروع نشده
            } else {
                $state = $this->progressState($index, $currentIndex, false);
            }

            $actions = [];

            if ($state === 'completed' || $state === 'current') {
                switch ($blueprint['key']) {
                    case 'purchased':
                        $actions[] = [
                            'label' => __('orders.actions.download_purchased_invoice'),
                            'link' => "/api/profile/orders/{$id}/invoice/initial",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        break;

                    case 'commission_booked':
                        $actions[] = [
                            'label' => __('orders.actions.view_wallet'),
                            'link' => '/profile/finance',
                            'type' => 'secondary',
                        ];
                        $actions[] = [
                            'label' => __('orders.actions.submit_service_invoice'),
                            'link' => "/api/profile/orders/{$id}/elite-yar-moadian-invoice",
                            'type' => 'primary',
                        ];
                        break;

                    case 'invoice_submitted':
                        $actions[] = [
                            'label' => __('orders.actions.submit_service_invoice'),
                            'link' => "/api/profile/orders/{$id}/elite-yar-moadian-invoice",
                            'type' => 'secondary',
                        ];
                        break;

                        // مرحله ۴ بدون دکمه
                }
            }

            $steps[] = [
                'step' => $index + 1,
                // کلیدِ پایدارِ مرحله. داک‌بلاکِ همین تریت می‌گوید «همهٔ شاخه‌زدن‌ها
                // روی کلید است نه عنوانِ فارسی» — ولی کلید سریالایز نمی‌شد، پس
                // کلاینت چاره‌ای جز تطبیق روی همان عنوان نداشت و بازطراحیِ
                // برچسب‌ها بی‌صدا آیکونِ سه مرحله را خاموش کرد.
                'key' => $blueprint['key'],
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        // مرحله‌ی آخر فقط وقتی Doc Q ثبت و هولد آزاد شده «completed» می‌شود؛
        // تحویلِ کالا به‌تنهایی هولد را آزاد نمی‌کند.
        if ($holdReleased) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $this->currentStatusLabel($status),
            'steps' => $steps,
        ];
    }

    /**
     * نوار پیشرفتِ تأمین‌کننده — بند ۱۱.۲.
     */
    public function getSupplierProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;
        $isCancelled = $status === Order::STATUS_CANCELLED;

        // همان قاعده‌ی بند ۱۱.۱ — رزرو از روی بیعانه‌ی پرداخت‌شده تشخیص داده
        // می‌شود، نه از مقایسه با آستانه‌ای که دیگر وجود ندارد.
        $wasReserved = (float) $this->payment_to_reserve > 0;

        $stepsBlueprint = [];

        if ($wasReserved) {
            $stepsBlueprint[] = $this->progressStep('supplier', 'reserved', [
                Order::STATUS_RESERVED,
            ]);
        }

        $stepsBlueprint[] = $this->progressStep('supplier', 'received', [
            Order::STATUS_PROCESSING, Order::STATUS_AWAITING_WEIGHT_CORRECTION,
        ]);

        $stepsBlueprint[] = $this->progressStep('supplier', 'shipped', [
            Order::STATUS_SHIPPED, Order::STATUS_RETURNED_TO_ORIGIN,
        ]);

        $stepsBlueprint[] = $this->progressStep('supplier', 'settled', [
            Order::STATUS_PARTIALLY_DELIVERED, Order::STATUS_DELIVERED,
        ]);

        $stepsBlueprint[] = $this->progressStep('supplier', 'moadian');

        $moadianSubmitted = $this->{Order::SUPPLIER_MOADIAN_REF} !== null;

        $currentIndex = 0;
        if (! $isCancelled) {
            $currentIndex = $this->currentProgressIndex($stepsBlueprint, $status);

            if ($moadianSubmitted) {
                $currentIndex = count($stepsBlueprint) - 1;
            }
        }

        $steps = [];

        foreach ($stepsBlueprint as $index => $blueprint) {
            $state = $this->progressState($index, $currentIndex, $isCancelled);
            $actions = [];

            if ($state !== 'inactive') {
                switch ($blueprint['key']) {
                    case 'reserved':
                        $actions[] = [
                            'label' => __('orders.actions.download_reserve_report'),
                            'link' => "/api/profile/orders/{$id}/invoice/reserve_report",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;

                    case 'received':
                        $actions[] = [
                            'label' => __('orders.actions.download_loading_order'),
                            'link' => "/api/profile/orders/{$id}/invoice/loading_order",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;

                    case 'shipped':
                        $actions[] = [
                            'label' => __('orders.actions.download_exit_permit'),
                            'link' => "/api/profile/orders/{$id}/invoice/exit_permit",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        // دکمه‌ی ثبت روی همین مرحله می‌نشیند، نه روی مرحله‌ی پنجم:
                        // ماشه‌ی مرحله‌ی پنجم خودِ `supplier_moadian_ref` است، پس تا
                        // وقتی ثبت نشده inactive است و دکمه‌اش دیده نمی‌شود — یعنی
                        // دکمه‌ای که فقط بعد از انجامِ کار ظاهر می‌شود.
                        if (! $moadianSubmitted) {
                            $actions[] = [
                                'label' => __('orders.actions.submit_moadian_invoice'),
                                'link' => "/api/profile/orders/{$id}/supplier-moadian-invoice",
                                'method' => 'POST',
                                'downloadable' => false,
                            ];
                        }
                        break;

                    case 'settled':
                        $actions[] = [
                            'label' => __('orders.actions.view_wallet'),
                            'link' => '/profile/finance',
                            'method' => 'GET',
                            'downloadable' => false,
                        ];
                        break;

                    case 'moadian':
                        $actions[] = [
                            'label' => __('orders.actions.download_moadian_invoice'),
                            'link' => "/api/profile/orders/{$id}/invoice/tax_supplier",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;
                }
            }

            $steps[] = [
                'step' => $index + 1,
                // کلیدِ پایدارِ مرحله. داک‌بلاکِ همین تریت می‌گوید «همهٔ شاخه‌زدن‌ها
                // روی کلید است نه عنوانِ فارسی» — ولی کلید سریالایز نمی‌شد، پس
                // کلاینت چاره‌ای جز تطبیق روی همان عنوان نداشت و بازطراحیِ
                // برچسب‌ها بی‌صدا آیکونِ سه مرحله را خاموش کرد.
                'key' => $blueprint['key'],
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        if ($moadianSubmitted) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $this->currentStatusLabel($status),
            'steps' => $steps,
        ];
    }

    /**
     * آیا محموله‌ای هست که راننده‌اش رسیده و منتظر تأیید خریدار است؟
     *
     * `relationLoaded` اول چک می‌شود تا لیستِ سفارش‌ها N+1 نگیرد — همان الگوی
     * `Order::partyRolesFor()`.
     */
    protected function hasShipmentAwaitingConfirmation(): bool
    {
        if ($this->relationLoaded('shipments')) {
            // حلقه‌ی صریح و نه `contains` با کلوژرِ تایپ‌دار: larastan روی
            // شاخه‌ی L13 آیتم‌های رابطه را `Model` می‌بیند.
            foreach ($this->shipments as $shipment) {
                /** @var Shipment $shipment */
                if ($shipment->{Shipment::ARRIVED_AT} !== null
                    && $shipment->{Shipment::DELIVERED_AT} === null
                    && $shipment->{Shipment::RETURNED_AT} === null) {
                    return true;
                }
            }

            return false;
        }

        return $this->shipments()
            ->whereNotNull(Shipment::ARRIVED_AT)
            ->whereNull(Shipment::DELIVERED_AT)
            ->whereNull(Shipment::RETURNED_AT)
            ->exists();
    }
}
