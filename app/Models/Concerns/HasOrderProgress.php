<?php

namespace App\Models\Concerns;

use App\Models\Order;
use Illuminate\Support\Facades\Lang;

trait HasOrderProgress
{
    /**
     * محاسبه مراحل پیشرفت سفارش برای نمایش در فرانت‌اند
     */
    public function getDashboardProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;

        $isCancelled = in_array($status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);

        // تشخیص پرداخت یکجا: کمتر از سقف رزرو یا پرداخت کل مبلغ در رزرو
        // سقف از config/financial.php خوانده می‌شود (نه hardcode)
        $threshold = (float) config('financial.reservation_threshold', 2_000_000_000);
        // مبنا پیش از تخفیف کوپن است — هم‌تراز با ProcessFullOrderPaymentAction،
        // وگرنه یک سفارشِ رزروی با کوپن در تایم‌لاین «پرداخت یکجا» نشان داده می‌شد.
        $isFullPayment = $this->reservationBasisAmount() < $threshold
            || $this->payment_to_reserve == $this->final_amount;

        // ترجمه وضعیت فعلی برای نمایش در هدر کارت
        $transKey = "orders.status.{$status}";
        $currentStatusLabel = Lang::has($transKey) ? __($transKey) : __('orders.status.unknown');

        // ---------------------------------------------------------
        // ۱. ساختار اولیه مراحل
        // ---------------------------------------------------------
        $stepsBlueprint = [];

        // اگر پرداخت کامل نیست، دایره "رزرو" اضافه می‌شود
        if (! $isFullPayment) {
            $stepsBlueprint[] = [
                'title' => 'سفارش رزرو شد',
                'triggers' => [Order::STATUS_PENDING, Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED],
            ];
        }

        $stepsBlueprint[] = [
            'title' => 'سفارش ثبت شد',
            'triggers' => [Order::STATUS_PROCESSING, Order::STATUS_PREPARING],
        ];

        $stepsBlueprint[] = [
            'title' => 'مقدار(وزن) تایید شد',
            'triggers' => [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_PENDING_SURPLUS_PAYMENT],
        ];

        $stepsBlueprint[] = [
            'title' => 'سفارش حمل شد',
            'triggers' => [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED],
        ];

        $stepsBlueprint[] = [
            'title' => 'سفارش تکمیل شد',
            'triggers' => [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED],
        ];

        // ---------------------------------------------------------
        // ۲. پیدا کردن مرحله فعلی (Current Node)
        // ---------------------------------------------------------
        $currentIndex = 0;
        if (! $isCancelled) {
            foreach ($stepsBlueprint as $index => $blueprint) {
                if (in_array($status, $blueprint['triggers'])) {
                    $currentIndex = $index;
                    break;
                }
            }
            if (in_array($status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED])) {
                $currentIndex = count($stepsBlueprint) - 1;
            }
        }

        // ---------------------------------------------------------
        // ۳. تخصیص وضعیت‌ها و لینک‌ها (دقیقاً طبق فیگما)
        // ---------------------------------------------------------
        $steps = [];
        $stepCounter = 1;

        foreach ($stepsBlueprint as $index => $blueprint) {

            // تعیین رنگ دایره
            if ($isCancelled) {
                // اگر لغو شده، فقط دایره اول قرمز میشه بقیه خاکستری
                $state = ($index === 0) ? 'cancelled' : 'inactive';
            } else {
                if ($index < $currentIndex) {
                    $state = 'completed'; // قبلی‌ها (تیک سبز)
                } elseif ($index === $currentIndex) {
                    $state = 'current';   // فعلی (آبی)
                } else {
                    $state = 'inactive';  // بعدی‌ها (خاکستری)
                }
            }

            // تعیین لینک‌های زیر دایره
            $actions = [];

            // لینک‌ها فقط زمانی نشان داده می‌شوند که دایره خاکستری (inactive) نباشد
            if ($state !== 'inactive') {

                if ($blueprint['title'] === 'سفارش رزرو شد') {
                    // پیش‌فاکتور (initial = DOC_PRE_INVOICE) همیشه قابل دانلود است
                    $actions[] = [
                        'label' => 'دریافت رسید',
                        'link' => "/api/profile/orders/{$id}/invoice/initial",
                        'type' => 'secondary',
                        'downloadable' => true,
                    ];
                    if ($state === 'current') {
                        $actions[] = ['label' => 'پرداخت فاکتور', 'link' => "/checkout/payment/{$id}", 'type' => 'primary'];
                    }
                    if ($state === 'cancelled') {
                        $actions[] = ['label' => 'خرید مجدد', 'link' => '/products', 'type' => 'primary'];
                    }
                }

                if ($blueprint['title'] === 'سفارش ثبت شد') {
                    // پیش‌فاکتور (initial = DOC_PRE_INVOICE) — نشان داده می‌شود در هر دو حالت current و completed
                    $actions[] = [
                        'label' => 'فاکتور اولیه پرداخت شده',
                        'link' => "/api/profile/orders/{$id}/invoice/initial",
                        'type' => 'secondary',
                        'downloadable' => true,
                    ];
                }

                if ($blueprint['title'] === 'مقدار(وزن) تایید شد') {
                    // بارنامه / مجوز خروج (K = DOC_BILL_OF_LADING) — فاکتور نهایی پس از وزن‌کشی
                    $actions[] = [
                        'label' => 'فاکتور نهایی',
                        'link' => "/api/profile/orders/{$id}/invoice/bill_of_lading",
                        'type' => 'secondary',
                        'downloadable' => true,
                    ];
                    // بارنامه: لینک صفحه جزئیات سفارش در SPA (navigate، نه دانلود)
                    $actions[] = ['label' => 'بارنامه', 'link' => "/profile/orders/{$id}", 'type' => 'secondary'];
                }

                if ($blueprint['title'] === 'سفارش حمل شد') {
                    // لینک SPA صفحه جزئیات سفارش (navigate، نه دانلود)
                    $actions[] = ['label' => 'لینک فرآیند تحویل', 'link' => "/profile/orders/{$id}", 'type' => 'secondary'];
                }
            }

            $steps[] = [
                'step' => $stepCounter++,
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        // باگ: وقتی status = delivered/completed، currentIndex برابر آخرین index می‌شود
        // و آخرین مرحله وضعیت 'current' می‌گیرد — در حالی که سفارش کاملاً تمام شده است.
        // رفع: آخرین step را به 'completed' تغییر می‌دهیم تا فرانت‌اند بداند همه مراحل
        // به پایان رسیده‌اند و نیازی به workaround normalizeSteps نیست.
        if (in_array($status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED])) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $currentStatusLabel,
            'steps' => $steps,
        ];
    }

    /**
     * محاسبه مراحل پیشرفت سفارش از دیدِ الیت یار (beneficiary)
     *
     * چهار مرحله ثابت — وضعیت مرحله بر اساس فیلدهای مدل تعیین می‌شود،
     * نه فقط ستون status، تا رویدادهایی مانند ثبت فاکتور مودیان نیز
     * به‌درستی منعکس شوند.
     */
    public function getEliteYARProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;

        $isCancelled = in_array($status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);

        $transKey = "orders.status.{$status}";
        $currentStatusLabel = Lang::has($transKey) ? __($transKey) : __('orders.status.unknown');

        // ---------------------------------------------------------
        // ۱. ساختار چهار مرحله (عناوین ثابت؛ بدون trigger-list)
        // ---------------------------------------------------------
        $stepsBlueprint = [
            ['title' => 'کارفرما خرید کرد'],
            ['title' => 'کارمزد الیت یار منظور شد'],
            ['title' => 'فاکتور خدمات ثبت شد'],
            ['title' => 'کارمزد الیت یار در دسترس است'],
        ];

        // ---------------------------------------------------------
        // ۲. تشخیص پیشرفته‌ترین مرحله فعال (از آخر به اول)
        //    چون ReleaseEliteYARHoldAction هر دو فیلد را اتمیک ست
        //    می‌کند، مرحله ۳ (elite_yar_moadian_ref) عملاً هرگز
        //    به‌عنوان «current» نمایش داده نمی‌شود — اما منطقاً
        //    پوشش داده شده است.
        // ---------------------------------------------------------
        $currentIndex = -1;

        if (! $isCancelled) {
            // مرحله ۴ فقط وقتی Doc Q ثبت و hold واقعاً آزاد شده — نه صرف تحویل کالا.
            // طبق پروسه خرید (بخش «الگوریتم وابستگی واریزها»):
            // «تا زمان صدور فاکتور سامانه مودیان توسط الیت یار در کیف پول وی هولد می‌شود».
            // پس delivered/completed به‌تنهایی hold را آزاد نمی‌کند؛ ReleaseEliteYARHoldAction لازم است.
            if ($this->{Order::ELITE_YAR_HOLD_RELEASED_AT} !== null) {
                $currentIndex = 3; // مرحله ۴: کارمزد الیت یار در دسترس
            } elseif ($this->{Order::ELITE_YAR_MOADIAN_REF} !== null) {
                $currentIndex = 2; // مرحله ۳: فاکتور خدمات ثبت شد (حالت گذرا — atomically به ۴ می‌رسد)
            } elseif (in_array($status, [
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_PENDING_SURPLUS_PAYMENT,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED,
                Order::STATUS_DELIVERED,  // کالا تحویل شده ولی الیت یار هنوز Doc Q ثبت نکرده
                Order::STATUS_COMPLETED,  // → کمیسیون در کیف‌پول الیت یار به‌صورت Hold؛ باید Doc Q ثبت کند
            ])) {
                $currentIndex = 1; // مرحله ۲: کارمزد الیت یار منظور شد
            } elseif (in_array($status, [
                Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING,
                Order::STATUS_PENDING,           // سفارش ثبت‌شده ولی هنوز پرداخت نشده
                Order::STATUS_RESERVED,          // رزرو پرداخت‌شده ولی قبل از تأیید
                Order::STATUS_RESERVED_EXTENDED, // رزرو تمدیدشده
            ])) {
                $currentIndex = 0; // مرحله ۱: کارفرما خرید کرد
            }
        }

        // ---------------------------------------------------------
        // ۳. تخصیص وضعیت و دکمه‌های هر مرحله
        // ---------------------------------------------------------
        $steps = [];
        $stepCounter = 1;

        foreach ($stepsBlueprint as $index => $blueprint) {

            // تعیین رنگ دایره
            if ($isCancelled) {
                $state = ($index === 0) ? 'cancelled' : 'inactive';
            } elseif ($currentIndex === -1) {
                $state = 'inactive'; // هنوز هیچ مرحله‌ای شروع نشده
            } elseif ($index < $currentIndex) {
                $state = 'completed';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            } else {
                $state = 'inactive';
            }

            // لینک‌ها فقط برای مراحل تکمیل‌شده یا فعلی نشان داده می‌شوند
            $actions = [];

            if ($state === 'completed' || $state === 'current') {
                switch ($blueprint['title']) {

                    case 'کارفرما خرید کرد':
                        $actions[] = [
                            'label' => 'دانلود فاکتور کالای خریداری شده',
                            'link' => "/api/profile/orders/{$id}/invoice/initial",
                            'type' => 'secondary',
                            'downloadable' => true,
                        ];
                        break;

                    case 'کارمزد الیت یار منظور شد':
                        $actions[] = [
                            'label' => 'مشاهده کیف پول',
                            'link' => '/profile/finance',
                            'type' => 'secondary',
                        ];
                        $actions[] = [
                            'label' => 'فاکتور خدمات خود را ثبت کنید',
                            'link' => "/api/profile/orders/{$id}/elite-yar-moadian-invoice",
                            'type' => 'primary',
                        ];
                        break;

                    case 'فاکتور خدمات ثبت شد':
                        $actions[] = [
                            'label' => 'فاکتور خدمات خود را ثبت کنید',
                            'link' => "/api/profile/orders/{$id}/elite-yar-moadian-invoice",
                            'type' => 'secondary',
                        ];
                        break;

                        // مرحله ۴ (کارمزد الیت یار در دسترس است): بدون دکمه
                }
            }

            $steps[] = [
                'step' => $stepCounter++,
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        // آخرین مرحله («کارمزد الیت یار در دسترس است») فقط وقتی Doc Q ثبت و hold آزاد شده «completed» می‌شود.
        // delivered/completed بدون Doc Q → مرحله ۲ current باقی می‌ماند (hold هنوز فعال است).
        if ($this->{Order::ELITE_YAR_HOLD_RELEASED_AT} !== null) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $currentStatusLabel,
            'steps' => $steps,
        ];
    }

    /**
     * محاسبه مراحل پیشرفت سفارش از دیدِ سوپلایر
     *
     * ساختار مشابه getDashboardProgressAttribute (خریدار)؛
     * عناوین و لینک‌های اکشن متفاوت است.
     * مرحله رزرو فقط در سفارش‌های رزروی (غیر پرداخت کامل) اضافه می‌شود.
     */
    public function getSupplierProgressAttribute(): array
    {
        $status = $this->status;
        $id = $this->id;

        $isCancelled = in_array($status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);

        $threshold = (float) config('financial.reservation_threshold', 2_000_000_000);
        // مبنا پیش از تخفیف کوپن است — هم‌تراز با ProcessFullOrderPaymentAction،
        // وگرنه یک سفارشِ رزروی با کوپن در تایم‌لاین «پرداخت یکجا» نشان داده می‌شد.
        $isFullPayment = $this->reservationBasisAmount() < $threshold
            || $this->payment_to_reserve == $this->final_amount;

        $transKey = "orders.status.{$status}";
        $currentStatusLabel = Lang::has($transKey) ? __($transKey) : __('orders.status.unknown');

        // ---------------------------------------------------------
        // ۱. ساختار مراحل (مرحله رزرو در صورت نیاز اضافه می‌شود)
        // ---------------------------------------------------------
        $stepsBlueprint = [];

        if (! $isFullPayment) {
            $stepsBlueprint[] = [
                'title' => 'رزرو سفارش ثبت شد',
                'triggers' => [Order::STATUS_PENDING, Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED],
            ];
        }

        $stepsBlueprint[] = [
            'title' => 'سفارش دریافت شد',
            'triggers' => [Order::STATUS_PROCESSING, Order::STATUS_PREPARING],
        ];

        $stepsBlueprint[] = [
            'title' => 'باسکول تأیید شد',
            'triggers' => [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_PENDING_SURPLUS_PAYMENT],
        ];

        $stepsBlueprint[] = [
            'title' => 'کالا ارسال شد',
            'triggers' => [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED],
        ];

        $stepsBlueprint[] = [
            'title' => 'مبلغ تسویه شد',
            'triggers' => [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED],
        ];

        // ---------------------------------------------------------
        // ۲. پیدا کردن مرحله فعلی (مشابه getDashboardProgressAttribute)
        // ---------------------------------------------------------
        $currentIndex = 0;
        if (! $isCancelled) {
            foreach ($stepsBlueprint as $index => $blueprint) {
                if (in_array($status, $blueprint['triggers'])) {
                    $currentIndex = $index;
                    break;
                }
            }
            if (in_array($status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED])) {
                $currentIndex = count($stepsBlueprint) - 1;
            }
        }

        // ---------------------------------------------------------
        // ۳. تخصیص وضعیت و اکشن‌های هر مرحله
        // ---------------------------------------------------------
        $steps = [];
        $stepCounter = 1;

        foreach ($stepsBlueprint as $index => $blueprint) {

            if ($isCancelled) {
                $state = ($index === 0) ? 'cancelled' : 'inactive';
            } else {
                if ($index < $currentIndex) {
                    $state = 'completed';
                } elseif ($index === $currentIndex) {
                    $state = 'current';
                } else {
                    $state = 'inactive';
                }
            }

            $actions = [];

            if ($state !== 'inactive') {
                switch ($blueprint['title']) {

                    case 'رزرو سفارش ثبت شد':
                        $actions[] = [
                            'label' => 'دانلود گزارش رزرو',
                            'link' => "/api/profile/orders/{$id}/invoice/reserve_report",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;

                    case 'سفارش دریافت شد':
                        $actions[] = [
                            'label' => 'دانلود دستور بارگیری',
                            'link' => "/api/profile/orders/{$id}/invoice/loading_order",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;

                    case 'باسکول تأیید شد':
                        $actions[] = [
                            'label' => 'دانلود مجوز خروج',
                            'link' => "/api/profile/orders/{$id}/invoice/exit_permit",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        // لینک ثبت فاکتور مودیان فقط تا زمانی که ارسال نشده
                        if ($this->{Order::SUPPLIER_MOADIAN_REF} === null) {
                            $actions[] = [
                                'label' => 'ثبت فاکتور مودیان',
                                'link' => "/api/profile/orders/{$id}/supplier-moadian-invoice",
                                'method' => 'POST',
                                'downloadable' => false,
                            ];
                        }
                        break;

                    case 'کالا ارسال شد':
                        $actions[] = [
                            'label' => 'مشاهده بارنامه',
                            'link' => "/api/profile/orders/{$id}/invoice/exit_permit",
                            'method' => 'GET',
                            'downloadable' => true,
                        ];
                        break;

                    case 'مبلغ تسویه شد':
                        $actions[] = [
                            'label' => 'مشاهده کیف پول',
                            'link' => '/profile/finance',
                            'method' => 'GET',
                            'downloadable' => false,
                        ];
                        break;
                }
            }

            $steps[] = [
                'step' => $stepCounter++,
                'title' => $blueprint['title'],
                'state' => $state,
                'actions' => $actions,
            ];
        }

        // مانند getDashboardProgressAttribute: آخرین مرحله وقتی تکمیل شده «completed» است
        if (in_array($status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED])) {
            $steps[count($steps) - 1]['state'] = 'completed';
        }

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $currentStatusLabel,
            'steps' => $steps,
        ];
    }
}
