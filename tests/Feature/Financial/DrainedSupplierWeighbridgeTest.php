<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Badge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ این تست یک **نقصِ شناخته‌شده** را پین می‌کند، نه رفتارِ مطلوب را.
 *
 * مسیرِ «پارک بدهیِ سوپلایر» (`SupplierDebtHandler::parkDebtAndNotify`،
 * `orders.supplier_debt_pending`، `CheckSupplierBalancesJob` و پیامکِ اطلاع به
 * سوپلایر) دقیقاً برای یک حالت ساخته شده: **سوپلایر پول ندارد**. سوپلایر PTS را در
 * لحظهٔ پرداخت می‌گیرد و می‌تواند فوراً برداشت کند، پس «کیفِ خالی در زمانِ باسکول»
 * حالتِ عادیِ همین مسیر است، نه یک حاشیه.
 *
 * ولی بلافاصله بعد از پارک شدنِ بدهی، `WeighbridgeVatRouter::routeSupplierVat()`
 * مالیاتِ امانیِ PTSM را **بدونِ قید** از همان کیفِ خالی برمی‌دارد. نتیجه:
 * `InsufficientBalanceException` و rollback شدنِ کلِ باسکول — از جمله خودِ پارک.
 *
 * یعنی کلِ ماشینِ «بدهیِ سوپلایر» فقط در پنجرهٔ باریکی کار می‌کند که سوپلایر برای
 * مالیات پول دارد ولی برای بازپس‌گیریِ کسری ندارد. در حالتِ متعارف — کیف خالی —
 * هیچ کم‌تحویلی‌ای اصلاً قابل ثبت نیست.
 *
 * ✅ رفعِ این نیاز به یک **تصمیمِ سیاستِ مالی** دارد (چه کسی مالیاتی را که سوپلایر
 *    ندارد جلو می‌اندازد، یا آیا مالیات هم مثل کسری پارک می‌شود)، پس عمداً اینجا
 *    درست نشده. وقتی تصمیم گرفته شد، این تست را **تغییر دهید** تا مسیر موفق را
 *    ادعا کند — حذفش نکنید.
 */
class DrainedSupplierWeighbridgeTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_deficit_cannot_be_recorded_at_all_when_the_supplier_wallet_is_empty(): void
    {
        $this->mock(InvoiceGenerator::class)->shouldReceive('generateWeighbridgeDocs')->andReturnNull();
        $this->mock(SmsService::class)->shouldReceive('send')->andReturn(true);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id, 'financial.vat_user_id' => $systemUser->id]);
        $systemWallet = Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $badge = Badge::firstOrCreate([Badge::CODE => 'pioneer'], [Badge::NAME => 'Pioneer']);
        $buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // ⚠️ کیف سوپلایر **خالی** است — او PTS را گرفته و برداشت کرده.
        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');
        $vat = 10.0;
        $poo = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], $vat, true, true);
        $pts = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat, true, true);

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::BENEFICIARY_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'DRAIN-'.Str::random(10),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::WEIGHT_BEFORE_SCALE => 10,
            Order::SHIPPING_COST => 0,
        ]);
        $order->forceFill([
            Order::OFFICIAL_PRICE => 1000,
            Order::VAT_RATE => $vat,
            Order::PAYMENT_OF_OWNER => $poo,
            Order::PAYMENT_TO_SUPPLIER => $pts,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1000,
            OrderItem::TOTAL_PRICE => 10000,
            OrderItem::QUANTITY => 10,
        ]);

        // سازه فقط کمیسیون را دارد — همان چیزی که در تولید پس از پرداخت برایش می‌ماند.
        $systemWallet->update([Wallet::BALANCE => $poo - $pts]);

        // کم‌تحویلی: ۸ کیلو به‌جای ۱۰.
        try {
            app(ProcessWeighbridgeResultAction::class)->execute($order, 8.0);
            $this->fail(
                'باسکول موفق شد — یعنی مسیرِ پارکِ بدهی رفع شده است. این تست را به '
                .'ادعای مسیرِ موفق تغییر دهید (وضعیت deficit_pending_supplier + پرچم بدهی).'
            );
        } catch (InsufficientBalanceException) {
            // رفتارِ فعلی: مالیاتِ امانیِ سوپلایر از کیفِ خالی برداشت می‌شود و می‌ترکد.
        }

        $order->refresh();

        $this->assertFalse(
            (bool) $order->{Order::SUPPLIER_DEBT_PENDING},
            'پارکِ بدهی همراهِ کلِ تراکنش rollback می‌شود — چیزی نیمه‌کاره روی سفارش نمی‌ماند.'
        );
        $this->assertNull(
            $order->{Order::WEIGHING_COMPLETED_AT},
            'باسکول اصلاً ثبت نمی‌شود؛ کم‌تحویلی برای این سفارش قابل ثبت نیست.'
        );
    }
}
