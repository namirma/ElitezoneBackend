<?php

namespace App\Models\Concerns;

use App\Models\Order;
use Illuminate\Support\Facades\Lang;

trait HasOrderProgress
{
    /**
     * محاسبه مراحل پیشرفت سفارش برای نمایش در فرانت‌ند
     */
    public function getDashboardProgressAttribute(): array
    {
        $s = $this->status;
        $id = $this->id;
        $paymentStatus = $this->payment_status;

        $isCancelled = in_array($s, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);

        $stepsData = [
            1 => ['state' => 'inactive', 'actions' => []],
            2 => ['state' => 'inactive', 'actions' => []],
            3 => ['state' => 'inactive', 'actions' => []],
            4 => ['state' => 'inactive', 'actions' => []],
            5 => ['state' => 'inactive', 'actions' => []],
        ];

        // ---------------------------------------------------------
        // ۱. محاسبه وضعیت دایره‌ها (State Logic)
        // ---------------------------------------------------------

        $stepsData[1]['state'] = 'completed';

        if (!$isCancelled) {
            // مرحله ۲
            if (in_array($s, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED, Order::STATUS_PROCESSING, Order::STATUS_PREPARING])) {
                $stepsData[2]['state'] = 'current';
            } elseif (in_array($s, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
                $stepsData[2]['state'] = 'completed';
            }

            // مرحله ۳
            if ($s === Order::STATUS_WEIGHBRIDGE_DONE) {
                $stepsData[3]['state'] = 'current';
            } elseif (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
                $stepsData[3]['state'] = 'completed';
            }

            // مرحله ۴
            if (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED])) {
                $stepsData[4]['state'] = 'current';
            } elseif ($s === Order::STATUS_DELIVERED) {
                $stepsData[4]['state'] = 'completed';
            }

            // مرحله ۵
            if ($s === Order::STATUS_DELIVERED) {
                $stepsData[5]['state'] = 'completed';
            }
        }

        // ---------------------------------------------------------
        // ۲. محاسبه دکمه‌ها و لینک‌ها (Actions Logic)
        // ---------------------------------------------------------

        if (!$isCancelled) {
            // اکشن‌های مرحله ۱
            if ($paymentStatus === Order::PAYMENT_PENDING && in_array($s, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
                $stepsData[1]['actions'][] = [
                    'label' => __('orders.actions.pay_invoice'),
                    'link'  => "/checkout/payment/{$id}",
                    'type'  => 'primary'
                ];
            } elseif ($paymentStatus === Order::PAYMENT_PAID) {
                $stepsData[1]['actions'][] = [
                    'label' => __('orders.actions.get_receipt'),
                    'link'  => "/profile/orders/{$id}/invoice/initial",
                    'type'  => 'secondary'
                ];
            }

            // اکشن‌های مرحله ۲
            if (in_array($s, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
                $stepsData[2]['actions'][] = [
                    'label' => __('orders.actions.download_initial_invoice'),
                    'link'  => "/profile/orders/{$id}/invoice/initial",
                    'type'  => 'secondary'
                ];
            } else {
                $stepsData[2]['actions'][] = [
                    'label' => __('orders.actions.view_details'),
                    'link'  => "/profile/orders/{$id}",
                    'type'  => 'secondary'
                ];
            }

            // اکشن‌های مرحله ۳
            if ($s === Order::STATUS_WEIGHBRIDGE_DONE) {
                $stepsData[3]['actions'][] = [
                    'label' => __('orders.actions.final_invoice_settlement'),
                    'link'  => "/checkout/payment/{$id}",
                    'type'  => 'primary'
                ];
            } elseif (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
                $stepsData[3]['actions'][] = [
                    'label' => __('orders.actions.get_final_invoice'),
                    'link'  => "/profile/orders/{$id}/invoice/final",
                    'type'  => 'secondary'
                ];
            }

            // اکشن‌های مرحله ۴
            if (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED])) {
                $stepsData[4]['actions'][] = [
                    'label' => __('orders.actions.view_waybill'),
                    'link'  => "/profile/orders/{$id}",
                    'type'  => 'primary'
                ];
            } elseif ($s === Order::STATUS_DELIVERED) {
                $stepsData[4]['actions'][] = [
                    'label' => __('orders.actions.view_shipping_details'),
                    'link'  => "/profile/orders/{$id}",
                    'type'  => 'secondary'
                ];
            }

            // اکشن‌های مرحله ۵
            if ($s === Order::STATUS_DELIVERED) {
                $stepsData[5]['actions'][] = [
                    'label' => __('orders.actions.get_tax_invoice'),
                    'link'  => "/profile/orders/{$id}/invoice/tax",
                    'type'  => 'secondary'
                ];
            }
        }

        // ---------------------------------------------------------
        // ۳. ساخت خروجی نهایی
        // ---------------------------------------------------------

        // اصلاح حرفه‌ای: استفاده از فایل ترجمه به جای match طولانی
        // اگر وضعیت در فایل ترجمه باشد، آن را برمی‌گرداند، اگر نباشد خود کلید را برمی‌گرداند
        // برای اطمینان از مقدار پیش‌فرض، از دستور زیر استفاده می‌کنیم:

        $transKey = "orders.status.{$s}";
        $currentStatusLabel = Lang::has($transKey) ? __($transKey) : __('orders.status.unknown');

        return [
            'is_cancelled' => $isCancelled,
            'current_status_label' => $currentStatusLabel,
            'steps' => [
                ['step' => 1, 'title' => __('orders.steps.1'), 'state' => $stepsData[1]['state'], 'actions' => $stepsData[1]['actions']],
                ['step' => 2, 'title' => __('orders.steps.2'), 'state' => $stepsData[2]['state'], 'actions' => $stepsData[2]['actions']],
                ['step' => 3, 'title' => __('orders.steps.3'), 'state' => $stepsData[3]['state'], 'actions' => $stepsData[3]['actions']],
                ['step' => 4, 'title' => __('orders.steps.4'), 'state' => $stepsData[4]['state'], 'actions' => $stepsData[4]['actions']],
                ['step' => 5, 'title' => __('orders.steps.5'), 'state' => $stepsData[5]['state'], 'actions' => $stepsData[5]['actions']],
            ]
        ];
    }
}
