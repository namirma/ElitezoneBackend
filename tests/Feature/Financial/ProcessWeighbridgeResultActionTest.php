<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
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
 * Tests for ProcessWeighbridgeResultAction — the post-weighbridge settlement.
 *
 * Focus: the spec invariant "final invoice ≤ initial invoice". Over-delivery
 * (WAS > WBS) must NEVER charge the buyer (billing weight is capped at WBS);
 * under-delivery (WAS < WBS) refunds the buyer the POO − POOM difference.
 *
 * DatabaseTransactions: writes roll back at teardown; the live DB is not mutated.
 */
class ProcessWeighbridgeResultActionTest extends TestCase
{
    use DatabaseTransactions;

    private ProcessWeighbridgeResultAction $action;

    private User $buyer;

    private Wallet $buyerWallet;

    private Wallet $systemWallet;

    private Order $order;

    private float $oldPOO;

    protected function setUp(): void
    {
        parent::setUp();

        // PDF generation is irrelevant to the financial assertions — mock it so the
        // missing test-environment Blade views don't break the run.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateWeighbridgeDocs')
            ->andReturnNull();

        // SMS service has no TSMS credentials in test env — mock it so the constructor
        // does not throw TypeError (string property ← null config) and no real HTTP call
        // is made when the supplier-debt notification path is exercised.
        $this->mock(SmsService::class)
            ->shouldReceive('send')
            ->andReturn(true);

        $this->action = app(ProcessWeighbridgeResultAction::class);

        $systemUser = User::factory()->create();
        config(['financial.system_user_id' => $systemUser->id]);
        config(['financial.vat_user_id' => $systemUser->id]);
        // موجودی این کیف پول پایین‌تر در setUp و **دقیقاً برابر کمیسیونِ همین
        // سفارش** ست می‌شود (نه یک عدد دلبخواه) — توضیح کامل کنار همان خط.
        $this->systemWallet = Wallet::create([Wallet::USER_ID => $systemUser->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        // Pioneer badge on the buyer so beneficiary.badge->code resolves without a null read.
        // firstOrCreate: the 'pioneer' badge is now also seeded by a migration.
        $badge = Badge::firstOrCreate([Badge::CODE => 'pioneer'], [Badge::NAME => 'Pioneer']);

        $this->buyer = User::factory()->create([User::BADGE_ID => $badge->id]);
        $this->buyerWallet = Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => 1000, Wallet::BALANCE_HOLD => 0]);

        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 1_000_000, Wallet::BALANCE_HOLD => 0]);

        // Pre-weighbridge snapshot for WBS = 10, OP = 1000, VAT 10%, no shipping.
        // Computed via the same calculator so the test isn't coupled to the rate table.
        $calc = app(SettlementCalculatorService::class);
        $rates = $calc->getCommissionRates('pioneer');

        // ⚠️ نرخ مالیات صریح است و صفر نیست. قبلاً این تست انتظاراتش را با
        // `$vat = 0` می‌ساخت و روی سفارش هم `VAT_RATE => 0` می‌گذاشت — یعنی
        // دقیقاً همان حالتی که باگِ `$order->vat_rate ?? 10.0` تولید می‌کرد
        // (ستون `NOT NULL DEFAULT 0.00` پس `??` هیچ‌وقت فعال نمی‌شد). تست روی
        // خودِ باگ بنا شده بود و نمی‌توانست آن را بگیرد.
        $vatPercent = 10.0;
        $this->oldPOO = $calc->calculatePOO(0, 1000, 10, $rates['ow_final_rate'], $vatPercent, true, true);
        $oldPTS = $calc->calculatePTS(0, 1000, 10, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vatPercent, true, true);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::BENEFICIARY_USER_ID => $this->buyer->id, // self → no eliteYAR payout
            Order::ORDER_NUMBER => 'TEST-WB-'.Str::random(12),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::WEIGHT_BEFORE_SCALE => 10,
            Order::SHIPPING_COST => 0,
        ]);
        $this->order->forceFill([
            Order::OFFICIAL_PRICE => 1000,
            Order::VAT_RATE => $vatPercent,
            Order::PAYMENT_OF_OWNER => $this->oldPOO,
            Order::PAYMENT_TO_SUPPLIER => $oldPTS,
            Order::TRANS_COMMISSION => true,
            Order::TRANS_VAT => true,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1000,
            OrderItem::TOTAL_PRICE => 10000,
            OrderItem::QUANTITY => 10,
        ]);

        // ⚠️ کیف پول سازه **دقیقاً** کمیسیونِ همین سفارش را دارد (POO − PTS) —
        // یعنی همان چیزی که در تولید پس از پرداخت برایش می‌ماند: POO را گرفته و
        // بلافاصله PTS را پرداخته است.
        //
        // پیش‌تر اینجا یک ۱٬۰۰۰٬۰۰۰ ریالِ دلبخواه شارژ می‌شد «تا تسویه‌ی کسری
        // بتواند اجرا شود». آن عددِ سخاوتمندانه یک باگ واقعی را پنهان می‌کرد:
        // `SupplierDebtHandler` اول از سازه برداشت می‌کرد و بعد از سوپلایر پس
        // می‌گرفت، پس در محیط واقعی — که سازه فقط کمیسیون را دارد —
        // هر کم‌تحویلی با `InsufficientBalanceException` کلِ باسکول را rollback
        // می‌کرد. با این مقدارِ واقع‌بینانه، اگر کسی ترتیب legها را برگرداند
        // (عودت به خریدار پیش از بازپس‌گیری از سوپلایر) تست فوراً می‌شکند.
        $this->systemWallet->update([Wallet::BALANCE => $this->oldPOO - $oldPTS]);
    }

    #[Test]
    public function over_delivery_never_charges_the_buyer(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (20) > WBS (10): billing weight is capped at WBS, so nothing extra is charged.
        $result = $this->action->execute($this->order, 20.0);

        $this->buyerWallet->refresh();
        $this->order->refresh();

        $this->assertSame('equal', $result['status'], 'Over-delivery must settle as equal, never surplus');
        $this->assertEquals($balanceBefore, (float) $this->buyerWallet->balance, 'Buyer must not be charged for over-delivery');
        $this->assertEqualsWithDelta($this->oldPOO, (float) $this->order->payment_of_owner, 1.0, 'Final POO must not exceed the initial POO');
        $this->assertEquals(20.0, (float) $this->order->weight_after_scale, 'Physical weight is still recorded');
    }

    #[Test]
    public function under_delivery_refunds_the_buyer(): void
    {
        $balanceBefore = (float) $this->buyerWallet->balance;

        // WAS (5) < WBS (10): deficit → buyer is refunded the POO − POOM difference.
        $result = $this->action->execute($this->order, 5.0);

        $this->buyerWallet->refresh();

        $this->assertSame('deficit', $result['status']);
        $this->assertGreaterThan($balanceBefore, (float) $this->buyerWallet->balance, 'Buyer must be refunded on under-delivery');
    }
}
