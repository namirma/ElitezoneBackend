<?php

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * خریدارِ دارای کوپن باید فقط «POO − تخفیف» موجودی لازم داشته باشد.
 *
 * لِگ‌های دفتری تغییری نکرده‌اند (WO→WSSG کل POO و WSSG→WO تخفیف) — فقط ترتیبشان
 * اصلاح شده. پیش از این `debitBuyer(POO)` اول اجرا می‌شد، پس کاربری که دقیقاً
 * مبلغِ تخفیف‌خوردهٔ نمایش‌داده‌شده در UI را داشت InsufficientBalanceException
 * می‌گرفت — یعنی کوپن عملاً غیرقابل‌استفاده بود مگر با موجودیِ کاملِ بدون تخفیف.
 */
class CouponBalanceGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const DISCOUNT = 500_000.0;

    private ProcessPreScalePaymentAction $action;

    private User $system;

    protected function setUp(): void
    {
        parent::setUp();

        // بلیدهای PDF نباید در تست رندر شوند — mock قبل از resolve شدن اکشن.
        $this->mock(InvoiceGenerator::class)
            ->shouldReceive('generateDirectPurchaseDocs')
            ->andReturnNull();

        $this->action = app(ProcessPreScalePaymentAction::class);

        $this->system = User::factory()->create();
        config([
            'financial.system_user_id' => $this->system->id,
            'financial.vat_user_id' => $this->system->id,
        ]);

        $this->walletFor($this->system, 500_000_000);
    }

    private function walletFor(User $user, float $balance): Wallet
    {
        return Wallet::create([
            Wallet::USER_ID => $user->id,
            Wallet::BALANCE => $balance,
            Wallet::BALANCE_HOLD => 0,
        ]);
    }

    /**
     * یک سفارشِ آمادهٔ پرداخت با (یا بدونِ) کوپن، به‌همراه کیف‌پولِ خریدار و تامین‌کننده.
     *
     * @return array{0: Order, 1: Wallet}
     */
    private function makePayableOrder(float $discount, float $buyerBalance): array
    {
        $buyer = User::factory()->create();
        $supplier = User::factory()->create();

        $buyerWallet = $this->walletFor($buyer, $buyerBalance);
        $this->walletFor($supplier, 0);

        $couponId = null;

        if ($discount > 0) {
            $couponId = Coupon::create([
                Coupon::CODE => 'TST-'.strtoupper(uniqid()),
                Coupon::USER_ID => $buyer->id,
                Coupon::TYPE => Coupon::TYPE_FIXED,
                Coupon::AMOUNT => $discount,
            ])->id;
        }

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'CPN-'.uniqid(),
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PENDING,
            Order::FINAL_AMOUNT => 10_000_000,
            Order::SHIPPING_COST => 0,
            Order::COUPON_ID => $couponId,
        ]);

        $order->forceFill([
            Order::COUPON_DISCOUNT_AMOUNT => $discount,
            Order::OFFICIAL_PRICE => 1_000,
            Order::WEIGHT_BEFORE_SCALE => 10_000,
            Order::VAT_RATE => 10,
        ])->save();

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $order->id,
            OrderItem::SUPPLIER_USER_ID => $supplier->id,
            OrderItem::UNIT_PRICE => 1_000,
            OrderItem::TOTAL_PRICE => 10_000_000,
            OrderItem::QUANTITY => 10_000,
        ]);

        return [$order->fresh(), $buyerWallet];
    }

    /**
     * POO از فرمول می‌آید و در تست بازسازی‌اش شکننده است؛ به‌جای آن یک سفارشِ
     * دورریختنی با موجودیِ فراوان پرداخت می‌شود و POOِ ثبت‌شده خوانده می‌شود.
     */
    private function discoverPoo(): float
    {
        [$order] = $this->makePayableOrder(discount: 0, buyerBalance: 500_000_000);

        $this->action->execute($order);

        return (float) $order->fresh()->{Order::PAYMENT_OF_OWNER};
    }

    #[Test]
    public function a_buyer_holding_only_the_discounted_amount_can_pay(): void
    {
        $poo = $this->discoverPoo();
        $this->assertGreaterThan(self::DISCOUNT, $poo, 'سفارشِ تست باید از تخفیف بزرگ‌تر باشد.');

        [$order, $buyerWallet] = $this->makePayableOrder(
            discount: self::DISCOUNT,
            buyerBalance: $poo - self::DISCOUNT,
        );

        $this->action->execute($order);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $buyerWallet->fresh()->balance,
            1.0,
            'خریدار باید دقیقاً POO − تخفیف پرداخت کرده باشد.',
        );
    }

    #[Test]
    public function the_coupon_discount_is_still_an_explicit_saze_expense(): void
    {
        $poo = $this->discoverPoo();

        [$order] = $this->makePayableOrder(
            discount: self::DISCOUNT,
            buyerBalance: $poo,
        );

        $systemBefore = (float) Wallet::where(Wallet::USER_ID, $this->system->id)->value(Wallet::BALANCE);

        $this->action->execute($order);

        $fresh = $order->fresh();

        // WSSG = +POO − تخفیف − PTS — دفتر متوازن می‌ماند و تخفیف از جیب سازه است.
        $this->assertEqualsWithDelta(
            $systemBefore
                + (float) $fresh->{Order::PAYMENT_OF_OWNER}
                - self::DISCOUNT
                - (float) $fresh->{Order::PAYMENT_TO_SUPPLIER},
            (float) Wallet::where(Wallet::USER_ID, $this->system->id)->value(Wallet::BALANCE),
            1.0,
        );
    }
}
