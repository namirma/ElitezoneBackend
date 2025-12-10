<?php

namespace App\Models\Concerns;

use App\Models\Order;

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

        // مرحله ۱: همیشه تکمیل است (چون سفارش ثبت شده)
        $stepsData[1]['state'] = 'completed';

        // مرحله ۲: ثبت اولیه
        if (in_array($s, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED, Order::STATUS_PROCESSING, Order::STATUS_PREPARING])) {
            $stepsData[2]['state'] = 'current';
        } elseif (in_array($s, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
            $stepsData[2]['state'] = 'completed';
        }

        // مرحله ۳: باسکول و ثبت نهایی
        if ($s === Order::STATUS_WEIGHBRIDGE_DONE) {
            $stepsData[3]['state'] = 'current';
        } elseif (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
            $stepsData[3]['state'] = 'completed';
        }

        // مرحله ۴: بارنامه و حمل
        if (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED])) {
            $stepsData[4]['state'] = 'current';
        } elseif ($s === Order::STATUS_DELIVERED) {
            $stepsData[4]['state'] = 'completed';
        }

        // مرحله ۵: تحویل نهایی
        if ($s === Order::STATUS_DELIVERED) {
            $stepsData[5]['state'] = 'completed';
        }

        // ---------------------------------------------------------
        // ۲. محاسبه دکمه‌ها و لینک‌ها (Actions Logic)
        // ---------------------------------------------------------

        // اکشن‌های مرحله ۱ (رزرو)
        if ($paymentStatus === Order::PAYMENT_PENDING && in_array($s, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
            $stepsData[1]['actions'][] = [
                'label' => 'پرداخت فاکتور',
                'link'  => "/checkout/payment/{$id}",
                'type'  => 'primary'
            ];
        } elseif ($paymentStatus === Order::PAYMENT_PAID) {
            $stepsData[1]['actions'][] = [
                'label' => 'دریافت رسید',
                'link'  => "/profile/orders/{$id}/invoice/initial",
                'type'  => 'secondary'
            ];
        }

        // اکشن‌های مرحله ۲ (ثبت اولیه)
        if (in_array($s, [Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
            $stepsData[2]['actions'][] = [
                'label' => 'دانلود فاکتور اولیه',
                'link'  => "/profile/orders/{$id}/invoice/initial",
                'type'  => 'secondary'
            ];
        } else {
            $stepsData[2]['actions'][] = [
                'label' => 'مشاهده جزئیات',
                'link'  => "/profile/orders/{$id}",
                'type'  => 'secondary'
            ];
        }

        // اکشن‌های مرحله ۳ (باسکول / نهایی)
        if ($s === Order::STATUS_WEIGHBRIDGE_DONE) {
            $stepsData[3]['actions'][] = [
                'label' => 'فاکتور نهایی (تسویه)',
                'link'  => "/checkout/payment/{$id}",
                'type'  => 'primary'
            ];
        } elseif (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])) {
            $stepsData[3]['actions'][] = [
                'label' => 'دریافت فاکتور نهایی',
                'link'  => "/profile/orders/{$id}/invoice/final",
                'type'  => 'secondary'
            ];
        }

        // اکشن‌های مرحله ۴ (حمل)
        if (in_array($s, [Order::STATUS_WAYBILL_ISSUED, Order::STATUS_SHIPPED])) {
            $stepsData[4]['actions'][] = [
                'label' => 'مشاهده بارنامه',
                'link'  => "/profile/orders/{$id}",
                'type'  => 'primary'
            ];
        } elseif ($s === Order::STATUS_DELIVERED) {
            $stepsData[4]['actions'][] = [
                'label' => 'مشاهده جزئیات حمل',
                'link'  => "/profile/orders/{$id}",
                'type'  => 'secondary'
            ];
        }

        // اکشن‌های مرحله ۵ (تکمیل)
        if ($s === Order::STATUS_DELIVERED) {
            $stepsData[5]['actions'][] = [
                'label' => 'دریافت فاکتور رسمی',
                'link'  => "/profile/orders/{$id}/invoice/tax",
                'type'  => 'secondary'
            ];
        }

        return [
            'is_cancelled' => in_array($s, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]),
            'steps' => [
                ['step' => 1, 'title' => 'رزرو سفارش',           'state' => $stepsData[1]['state'], 'actions' => $stepsData[1]['actions']],
                ['step' => 2, 'title' => 'ثبت اولیه پس از پرداخت', 'state' => $stepsData[2]['state'], 'actions' => $stepsData[2]['actions']],
                ['step' => 3, 'title' => 'ثبت نهایی بعد از باسکول', 'state' => $stepsData[3]['state'], 'actions' => $stepsData[3]['actions']],
                ['step' => 4, 'title' => 'در حال حمل',            'state' => $stepsData[4]['state'], 'actions' => $stepsData[4]['actions']],
                ['step' => 5, 'title' => 'تکمیل شده',             'state' => $stepsData[5]['state'], 'actions' => $stepsData[5]['actions']],
            ]
        ];
    }
}
