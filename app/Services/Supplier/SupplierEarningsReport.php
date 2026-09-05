<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * گزارشِ درآمدِ تأمین‌کننده، با بازه‌ی زمانی.
 *
 * ## چرا لازم بود
 *
 * تنها دیدِ مالیِ تأمین‌کننده `dashboard.order_stats` بود که فقط **بر اساس
 * وضعیت** گروه می‌کرد. یعنی هیچ راهی برای «مردادِ امسال چقدر فروختم» یا
 * «کدام کارخانه‌ام بیشتر آورده» وجود نداشت، و مهم‌تر: `payment_to_supplier`
 * یک عددِ تنها بود که تأمین‌کننده نمی‌توانست با چیزی مطابقتش بدهد.
 *
 * ## دو تصمیمِ ظریف
 *
 * **مبنای تاریخ `weighing_completed_at` است نه `created_at`.** درآمد وقتی
 * قطعی می‌شود که باسکول تسویه شود — پیش از آن `payment_to_supplier` هنوز PTS
 * است (برآوردِ زمانِ پرداخت) و ممکن است با PTSM جابه‌جا شود. گروه‌بندی بر
 * اساس تاریخِ ثبتِ سفارش یعنی عددی که ماهِ بعد عوض می‌شود.
 *
 * **سفارشِ تسویه‌نشده حذف نمی‌شود، جدا شمرده می‌شود.** حذفشان یعنی
 * تأمین‌کننده نداند چقدر «در راه» دارد؛ قاطی‌کردنشان یعنی جمعِ ماه دروغ
 * بگوید. پس `settled` و `in_progress` دو بلوکِ جدا هستند.
 */
final class SupplierEarningsReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $supplierUserId, ?Carbon $from, ?Carbon $to): array
    {
        $mine = fn (Builder $q) => $q->whereHas(
            'items',
            fn (Builder $i) => $i->where('supplier_user_id', $supplierUserId)
        );

        $settled = Order::query()
            ->tap($mine)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->when($from, fn (Builder $q) => $q->where(Order::WEIGHING_COMPLETED_AT, '>=', $from))
            ->when($to, fn (Builder $q) => $q->where(Order::WEIGHING_COMPLETED_AT, '<=', $to));

        // در جریان: پرداخت شده ولی هنوز باسکول نخورده. رقمش PTS است، یعنی
        // برآورد — و همین‌طور هم برچسب می‌خورد.
        $inProgress = Order::query()
            ->tap($mine)
            ->whereNull(Order::WEIGHING_COMPLETED_AT)
            ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID);

        return [
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                // تا هیچ‌کس گمان نکند مبنا تاریخِ ثبتِ سفارش است.
                'basis' => 'weighing_completed_at',
            ],
            'settled' => [
                'orders' => (clone $settled)->count(),
                'net_payout' => (float) (clone $settled)->sum(Order::PAYMENT_TO_SUPPLIER),
                'buyer_value' => (float) (clone $settled)->sum(Order::FINAL_AMOUNT),
                'delivered_weight' => (float) Shipment::query()
                    ->whereIn(Shipment::ORDER_ID, (clone $settled)->select(Order::ID))
                    ->sum(Shipment::ACTUAL_WEIGHT),
            ],
            'in_progress' => [
                'orders' => (clone $inProgress)->count(),
                // عمداً `estimated_` : این رقم PTS است و با باسکول جابه‌جا می‌شود.
                'estimated_payout' => (float) (clone $inProgress)->sum(Order::PAYMENT_TO_SUPPLIER),
            ],
            'obligations' => [
                'debt' => (float) Order::query()->tap($mine)
                    ->where(Order::SUPPLIER_DEBT_PENDING, true)
                    ->sum(Order::SUPPLIER_DEBT_AMOUNT),
                'suspended_vat' => (float) Order::query()->tap($mine)
                    ->where(Order::SUPPLIER_DEBT_PENDING, true)
                    ->sum(Order::SUPPLIER_VAT_PENDING_AMOUNT),
                'awaiting_moadian' => Order::query()->tap($mine)
                    ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
                    ->whereNull(Order::SUPPLIER_MOADIAN_REF)
                    ->count(),
            ],
            'monthly' => $this->monthly($settled),
            'by_factory' => $this->byFactory($settled, $supplierUserId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthly(Builder $settled): array
    {
        return (clone $settled)
            ->selectRaw(
                "DATE_FORMAT(weighing_completed_at, '%Y-%m') as period,"
                .' COUNT(*) as orders,'
                .' SUM(COALESCE(payment_to_supplier, 0)) as net_payout'
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                // ماهِ میلادی است نه جلالی: مرزِ ماهِ جلالی در SQL بدونِ جدولِ
                // کمکی درست درنمی‌آید، و فرانت که تاریخ را جلالی نشان می‌دهد
                // می‌تواند همین برچسب را هم تبدیل کند.
                'period' => $row->getAttribute('period'),
                'orders' => (int) $row->getAttribute('orders'),
                'net_payout' => (float) $row->getAttribute('net_payout'),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byFactory(Builder $settled, int $supplierUserId): array
    {
        return (clone $settled)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('factories', 'factories.id', '=', 'order_items.factory_id')
            ->where('order_items.supplier_user_id', $supplierUserId)
            ->selectRaw(
                'factories.id as factory_id, factories.name as factory_name,'
                .' COUNT(DISTINCT orders.id) as orders,'
                .' SUM(COALESCE(orders.payment_to_supplier, 0)) as net_payout'
            )
            ->groupBy('factories.id', 'factories.name')
            ->orderByDesc('net_payout')
            ->get()
            ->map(fn ($row) => [
                'factory_id' => (int) $row->getAttribute('factory_id'),
                'factory_name' => $row->getAttribute('factory_name'),
                'orders' => (int) $row->getAttribute('orders'),
                'net_payout' => (float) $row->getAttribute('net_payout'),
            ])
            ->all();
    }
}
