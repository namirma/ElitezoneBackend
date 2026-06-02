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
        $threshold = (float) config('financial.reservation_threshold', 1_950_000_000);
        $isFullPayment = (float) $this->final_amount < $threshold
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
                        'link' => "/api/profile/orders/{$id}/invoices/initial",
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
                        'link' => "/api/profile/orders/{$id}/invoices/initial",
                        'type' => 'secondary',
                        'downloadable' => true,
                    ];
                }

                if ($blueprint['title'] === 'مقدار(وزن) تایید شد') {
                    // مجوز خروج (exit_permit = DOC_EXIT_PERMIT) — فاکتور نهایی پس از وزن‌کشی
                    $actions[] = [
                        'label' => 'فاکتور نهایی',
                        'link' => "/api/profile/orders/{$id}/invoices/exit_permit",
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
}
