<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Financial\ProcessReservationCompletionAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\LedgerReconciler;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ترتیبِ legها در تکمیلِ وجهِ رزرو — شاخهٔ افتِ قیمت.
 *
 * بیعانه (PTR) در `ProcessReservationPaymentAction` با وضعیت HOLD روی کیف سازه
 * می‌نشیند، یعنی داخل `balance_hold` است نه `balance`. هر `debitSystem(...)` با
 * وضعیت AVAILABLE فقط `balance` را می‌بیند.
 *
 * اگر قیمت بین رزرو و تکمیل پایین بیاید (POO < PTR)، مابه‌التفاوت باید به خریدار
 * برگردد. تا پیش از این، آن برداشت **قبل از** آزادسازیِ هولد اجرا می‌شد — یعنی
 * سازه باید پولی را جلو می‌انداخت که همان بیعانهٔ همین سفارش بود و دو خط پایین‌تر
 * آزاد می‌شد. روی یک نصبِ تازه (کیف سازه صفر) نتیجه `InsufficientBalanceException`
 * و rollback شدنِ کلِ تکمیل وجه بود.
 *
 * سازه اینجا عمداً **هیچ موجودیِ آزادی ندارد**: تنها منبعِ پول، بیعانهٔ خودِ سفارش
 * است. این همان شرطی است که باگ را آشکار می‌کند.
 *
 * DatabaseTransactions: همهٔ نوشتن‌ها برمی‌گردند؛ دیتابیس زنده دست نمی‌خورد.
 */
class ReservationCompletionLegOrderTest extends TestCase
{
    use DatabaseTransactions;

    /** بیعانهٔ پرداخت‌شده در لحظهٔ رزرو — عمداً خیلی بزرگ‌تر از POO. */
    private const RESERVE = 100_000_000.0;

    /** قیمت واحد در لحظهٔ تکمیل، برای وزنِ ۱ — POO از این بسیار کوچک‌تر از PTR درمی‌آید. */
    private const OFFICIAL_PRICE = 1_000_000.0;

    private User $system;

    private User $buyer;

    private User $supplier;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = $this->mock(InvoiceGenerator::class);
        $generator->shouldReceive('generateReservationDocs')->andReturnNull();
        $generator->shouldReceive('generateReservationCompletionDocs')->andReturnNull();

        $this->system = User::factory()->create();
        config(['financial.system_user_id' => $this->system->id]);
        Wallet::create([Wallet::USER_ID => $this->system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => self::RESERVE, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->order = Order::create([
            Order::PLACER_USER_ID => $this->buyer->id,
            Order::ORDER_NUMBER => 'RESCOMP-'.Str::random(10),
            Order::STATUS => Order::STATUS_PENDING,
            Order::FINAL_AMOUNT => self::RESERVE,
            Order::PAYMENT_STATUS => 'unpaid',
            Order::SHIPPING_COST => 0,
        ]);

        OrderItem::factory()->create([
            OrderItem::ORDER_ID => $this->order->id,
            OrderItem::SUPPLIER_USER_ID => $this->supplier->id,
            OrderItem::UNIT_PRICE => self::OFFICIAL_PRICE,
            OrderItem::TOTAL_PRICE => self::OFFICIAL_PRICE,
            OrderItem::QUANTITY => 1,
        ]);

        // مرحلهٔ ۱ — رزرو واقعی: خریدار می‌پردازد، سازه بیعانه را **هولد** می‌کند.
        app(ProcessReservationPaymentAction::class)->execute($this->order, self::RESERVE, validityHours: 2);

        // اسنپ‌شاتِ قیمت در لحظهٔ تکمیل: قیمت افت کرده است.
        $this->order->refresh()->forceFill([
            Order::OFFICIAL_PRICE => self::OFFICIAL_PRICE,
            Order::WEIGHT_BEFORE_SCALE => 1,
            Order::VAT_RATE => 10,
        ])->save();
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::where(Wallet::USER_ID, $user->id)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_reserve_sits_in_hold_not_in_available_balance(): void
    {
        // پیش‌فرضی که کلِ این تست روی آن بنا شده — اگر روزی عوض شود باید اینجا بشکند.
        $saze = $this->walletOf($this->system);

        $this->assertEqualsWithDelta(0.0, (float) $saze->balance, 0.5);
        $this->assertEqualsWithDelta(self::RESERVE, (float) $saze->balance_hold, 0.5);
    }

    #[Test]
    public function a_price_drop_refund_is_funded_by_the_orders_own_reserve(): void
    {
        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $this->order->refresh();

        $this->assertSame(
            Order::STATUS_PROCESSING,
            $this->order->status,
            'تکمیل وجه نباید به‌خاطر ترتیبِ legها شکست بخورد — پولِ عودت همان بیعانهٔ همین سفارش است.'
        );
        $this->assertSame(Order::PAYMENT_PAID, $this->order->payment_status);

        $poo = (float) $this->order->{Order::PAYMENT_OF_OWNER};
        $this->assertGreaterThan(0.0, $poo);
        $this->assertLessThan(self::RESERVE, $poo, 'سناریو باید واقعاً شاخهٔ افتِ قیمت را بزند.');

        // خریدار: RESERVE پرداخت کرده، (RESERVE − POO) پس گرفته → خالص POO کسر شده.
        $this->assertEqualsWithDelta(
            self::RESERVE - $poo,
            (float) $this->walletOf($this->buyer)->balance,
            1.0,
            'مابه‌التفاوتِ افت قیمت باید به خریدار برگردد.'
        );
    }

    #[Test]
    public function the_saze_wallet_never_goes_negative_and_keeps_only_its_commission(): void
    {
        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $this->order->refresh();
        $saze = $this->walletOf($this->system);

        $poo = (float) $this->order->{Order::PAYMENT_OF_OWNER};
        $pts = (float) $this->order->{Order::PAYMENT_TO_SUPPLIER};

        $this->assertGreaterThanOrEqual(0.0, (float) $saze->balance);
        $this->assertEqualsWithDelta(0.0, (float) $saze->balance_hold, 0.5, 'هولدِ رزرو باید کاملاً آزاد شده باشد.');
        $this->assertEqualsWithDelta($poo - $pts, (float) $saze->balance, 1.0, 'سازه فقط کمیسیون را نگه می‌دارد.');

        $this->assertEqualsWithDelta($pts, (float) $this->walletOf($this->supplier)->balance, 1.0);
    }

    #[Test]
    public function the_hold_is_released_through_the_append_only_path(): void
    {
        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $release = WalletTransaction::where('wallet_id', $this->walletOf($this->system)->id)
            ->where('order_id', $this->order->id)
            ->where('type', WalletTransaction::TYPE_HOLD_RELEASE)
            ->first();

        $this->assertNotNull($release, 'آزادسازی باید یک ردیفِ مجزای hold_release بنویسد، نه جابه‌جاییِ خام.');
        $this->assertEqualsWithDelta(self::RESERVE, (float) $release->amount, 0.5);

        // ردیفِ هولدِ اصلی دست‌نخورده می‌ماند (همان قاعدهٔ CheckSupplierBalancesJob).
        $placement = WalletTransaction::where('wallet_id', $this->walletOf($this->system)->id)
            ->where('order_id', $this->order->id)
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->where('status', FinancialCodes::STATUS_HOLD)
            ->first();

        $this->assertNotNull($placement);
    }

    #[Test]
    public function every_wallet_touched_by_the_flow_still_reconciles(): void
    {
        // `LedgerReconciler` فقط روی شاخهٔ لاراول ۱۰ هست (روی ۱۳ پورت نشده). بقیهٔ
        // تست‌های این فایل مستقل از آن‌اند و روی هر دو شاخه می‌دوند.
        if (! class_exists(LedgerReconciler::class)) {
            $this->markTestSkipped('LedgerReconciler روی این شاخه وجود ندارد.');
        }

        app(ProcessReservationCompletionAction::class)->execute($this->order);

        $reconciler = app(LedgerReconciler::class);

        foreach ([$this->system, $this->supplier] as $user) {
            $this->assertSame(
                [],
                $reconciler->driftedWallets($user->id),
                "کیف کاربر {$user->id} با بازپخشِ دفتر نمی‌خواند."
            );
        }

        // جمعِ جبریِ تراکنش‌های سفارش باید دقیقاً صفر باشد — هیچ پولی خلق یا نابود نشده.
        $this->assertSame([], $reconciler->unbalancedOrders((int) $this->order->id));
    }
}
