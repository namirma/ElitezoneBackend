<?php

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Financial\WeighbridgeReconciler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * گاردهای دادهٔ خراب در مسیر تسویه.
 *
 * قاعدهٔ مشترک این تست‌ها: دادهٔ نامعتبر باید عملیات را **متوقف** کند، نه اینکه پول
 * جابه‌جا کند. این همان الگویی است که پیش‌تر برای weight_before_scale برقرار بود و
 * حالا برای official_price هم برقرار است.
 *
 * باگی که پین می‌شود: اگر هم `official_price` و هم `unit_price` صفر باشند، POOM روی
 * op=0 تقریباً صفر می‌شد، diffPOO به‌شدت منفی می‌شد و شاخهٔ «کسری» فعال می‌شد —
 * یعنی عودتِ کلِ مبلغ به خریدار و ثبتِ بدهی برای تأمین‌کننده، فقط به‌خاطر یک ردیفِ
 * ناقص و بدون اینکه هیچ کیلوگرمی کم آمده باشد.
 */
class SettlementDataGuardsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_reconciler_refuses_an_order_with_no_usable_price(): void
    {
        $order = Order::factory()->create([
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::SHIPPING_COST => 1_000_000,
        ]);

        // هر دو منبعِ قیمت صفر — دقیقاً همان ردیفِ خرابی که کسریِ کاذب می‌ساخت.
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => 0,
        ]);

        $order->forceFill([
            Order::WEIGHT_BEFORE_SCALE => 10_000,
            Order::OFFICIAL_PRICE => 0,
            Order::VAT_RATE => 10,
            Order::COMMISSION_RATE => 1.0,
            Order::PAYMENT_OF_OWNER => 500_000_000,
            Order::PAYMENT_TO_SUPPLIER => 480_000_000,
        ])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('orders.invalid_official_price'));

        app(WeighbridgeReconciler::class)->reconcile(
            $order->fresh(['items', 'eliteYAR.badge']),
            10_000.0
        );
    }

    #[Test]
    public function a_valid_price_still_reconciles_normally(): void
    {
        // گاردِ بالا نباید مسیرِ سالم را ببندد.
        $order = Order::factory()->create([
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::SHIPPING_COST => 1_000_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => 200_000,
        ]);

        $order->forceFill([
            Order::WEIGHT_BEFORE_SCALE => 10_000,
            Order::OFFICIAL_PRICE => 200_000,
            Order::VAT_RATE => 10,
            Order::COMMISSION_RATE => 1.0,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
            Order::PAYMENT_OF_OWNER => 0,
            Order::PAYMENT_TO_SUPPLIER => 0,
        ])->save();

        $result = app(WeighbridgeReconciler::class)->reconcile(
            $order->fresh(['items', 'eliteYAR.badge']),
            10_000.0
        );

        $this->assertGreaterThan(0, $result->newPOO);
        $this->assertGreaterThan(0, $result->newPTS);
        $this->assertSame(10_000.0, $result->billingWeight);
    }

    #[Test]
    public function over_delivery_is_clamped_to_the_ordered_weight(): void
    {
        // ثابتِ مستندشده در spec: فاکتور نهایی هرگز بیش از فاکتور اولیه نیست.
        $order = Order::factory()->create([
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::SHIPPING_COST => 1_000_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => 200_000,
        ]);

        $order->forceFill([
            Order::WEIGHT_BEFORE_SCALE => 10_000,
            Order::OFFICIAL_PRICE => 200_000,
            Order::VAT_RATE => 10,
            Order::COMMISSION_RATE => 1.0,
        ])->save();

        // تحویلِ بیشتر از سفارش — وزنِ صورت‌حساب باید روی وزنِ سفارش کلamp شود.
        $result = app(WeighbridgeReconciler::class)->reconcile(
            $order->fresh(['items', 'eliteYAR.badge']),
            12_500.0
        );

        $this->assertSame(10_000.0, $result->billingWeight,
            'اضافه‌تحویل نباید به خریدار صورت‌حساب شود.');
    }
}
