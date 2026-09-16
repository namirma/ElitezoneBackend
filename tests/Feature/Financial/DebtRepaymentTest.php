<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnDamage;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Shipping\ReturnDamageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بازپرداخت بدهی از کیف پول — `POST /api/profile/debts/repay`.
 *
 * ⚠️ **چرا این اندپوینت لازم بود.** بدهی هم ثبت سفارش را می‌بست
 * (`PlaceOrderAction::assertNotInDebt`) هم برداشت را
 * (`RequestWithdrawalAction`)، ولی تنها راه‌های تسویه‌اش سه مسیرِ رویدادمحور
 * بودند: اضافه‌بار، خسارت عودت، و سقف خسارت. کاربری که بدهی‌اش از یکی از
 * آن‌ها آمده بود و آن رویداد دیگر تکرار نمی‌شد، **برای همیشه قفل می‌ماند** —
 * نه خودش کاری می‌توانست بکند نه ادمین (که فقط `credit_limit` را می‌گذارد،
 * نه مانده را صفر می‌کند).
 */
class DebtRepaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private DebtEngine $debtEngine;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        $system = User::factory()->create();
        $this->systemUser = $system;
        config(['financial.system_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $this->user = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $this->user->id,
            Wallet::BALANCE => 5_000_000,
            Wallet::BALANCE_HOLD => 0,
        ]);

        $this->debtEngine = app(DebtEngine::class);
    }

    private function incurDebt(float $amount): void
    {
        $this->debtEngine->incur(
            (int) $this->user->id, $amount, DebtEntry::TYPE_ADJUSTMENT, null, 'بدهی آزمایشی'
        );
    }

    #[Test]
    public function a_debtor_can_clear_the_whole_balance_from_their_wallet(): void
    {
        $this->incurDebt(2_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay')->assertOk();

        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertSame(
            3_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->user->id)->value(Wallet::BALANCE)
        );

        // ⚠️ رگرسیونی که این می‌گیرد و تستِ قبلی نمی‌گرفت: `FundWalletAction`
        // پیش‌فرضش یک ردیفِ `deposit/pending` می‌ساخت که موجودی را **تکان
        // نمی‌داد**. یعنی کیف پولِ بدهکار کسر می‌شد، کیف پولِ سازه چیزی
        // نمی‌گرفت، بدهی هم بسته می‌شد — و پول از سیستم ناپدید می‌شد.
        //
        // ‏`financial:doctor` هم نمی‌دیدش: `deposit/pending` سهمِ صفر دارد، پس
        // هر دو کیف پول با دفترِ خودشان می‌خواندند. ناترازی بین‌کیف‌پولی بود.
        $this->assertSame(
            2_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->systemUser->id)->value(Wallet::BALANCE),
            'بازپرداخت باید به کیف پولِ سازه برسد، نه اینکه فقط یک درخواستِ واریز بسازد.'
        );
    }

    #[Test]
    public function no_money_disappears_from_the_system_during_a_repayment(): void
    {
        // جمعِ کلِ پولِ روی میز پیش و پس از بازپرداخت باید یکی بماند — بدهی
        // جابه‌جا می‌شود، خلق یا نابود نمی‌شود.
        $this->incurDebt(3_000_000);
        Sanctum::actingAs($this->user);

        $before = (float) Wallet::whereIn(Wallet::USER_ID, [$this->user->id, $this->systemUser->id])
            ->sum(Wallet::BALANCE);

        $this->postJson('/api/profile/debts/repay')->assertOk();

        $after = (float) Wallet::whereIn(Wallet::USER_ID, [$this->user->id, $this->systemUser->id])
            ->sum(Wallet::BALANCE);

        $this->assertSame($before, $after, 'مجموعِ موجودی‌ها نباید عوض شود.');

        // و ردیفِ بستانکار باید به رویدادِ خودش قابلِ ردیابی باشد. `$extraMeta`
        // پارامتر بود ولی بی‌صدا دور ریخته می‌شد (فقط `receipt_info` جان سالم
        // به در می‌برد)، پس تگِ `kind` هرگز به ردیف نمی‌رسید و در گزارشِ مالی
        // فقط یک واریزِ بی‌نشان دیده می‌شد.
        $credit = WalletTransaction::query()
            ->whereHas('wallet', fn ($q) => $q->where('user_id', $this->systemUser->id))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('debt_repayment', $credit->meta['kind'] ?? null);
    }

    #[Test]
    public function a_partial_amount_leaves_the_remainder_owing(): void
    {
        $this->incurDebt(2_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay', ['amount' => 500_000])->assertOk();

        $this->assertSame(1_500_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    /** پرداختِ بیش از بدهی نباید کاربر را طلبکار کند. */
    #[Test]
    public function paying_more_than_the_balance_is_clamped_to_the_balance(): void
    {
        $this->incurDebt(1_000_000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay', ['amount' => 4_000_000])->assertOk();

        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertSame(
            4_000_000.0,
            (float) Wallet::where(Wallet::USER_ID, $this->user->id)->value(Wallet::BALANCE)
        );
    }

    #[Test]
    public function a_user_with_no_debt_is_refused(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/profile/debts/repay')->assertStatus(422);
    }

    /** موجودیِ ناکافی نباید دفتر بدهی را ببندد. */
    #[Test]
    public function an_empty_wallet_cannot_clear_the_debt(): void
    {
        $this->incurDebt(9_000_000);
        Sanctum::actingAs($this->user);

        // ۴۰۲ و نه ۴۲۲ — `InsufficientBalanceException` کدِ خودش را دارد.
        $this->postJson('/api/profile/debts/repay')->assertStatus(402);

        // نکته‌ی اصلیِ این تست: کسرِ ناموفق نباید دفتر را ببندد. ترتیبِ
        // «اول کیف پول، بعد دفتر» دقیقاً برای همین است.
        $this->assertSame(9_000_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    private function wallet(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    // ── هر بدهی از جریانِ خودش ───────────────────────────────────────────────

    /**
     * کسری باسکول: پیش‌تر بازپرداخت پرچم سفارش را باز می‌گذاشت و کران همان ۲
     * میلیون را دوباره از تأمین‌کننده برمی‌داشت — بدهیِ ۲ میلیونی، ۴ میلیون.
     */
    #[Test]
    public function a_weighbridge_deficit_is_reclaimed_once_and_the_buyer_is_refunded(): void
    {
        Queue::fake();
        // سازه پس از پرداخت فقط کمیسیون را نگه می‌دارد؛ عودت از طلب بزرگ‌تر است.
        Wallet::where(Wallet::USER_ID, $this->systemUser->id)->update([Wallet::BALANCE => 1_000_000]);
        $buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $buyer->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $order = Order::create([
            Order::PLACER_USER_ID => $buyer->id,
            Order::ORDER_NUMBER => 'DEF-'.Str::random(10),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        OrderItem::factory()->create([OrderItem::ORDER_ID => $order->id, OrderItem::SUPPLIER_USER_ID => $this->user->id]);

        Wallet::where(Wallet::USER_ID, $this->user->id)->update([Wallet::BALANCE => 0]); // تأمین‌کننده خالی → پارک
        app(SupplierDebtHandler::class)->handle($order->load('items'), 3_000_000, 2_000_000);
        Wallet::where(Wallet::USER_ID, $this->user->id)->update([Wallet::BALANCE => 5_000_000]); // شارژ

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay')->assertOk()->assertJsonPath('data.repaid', '2000000');

        app(CheckSupplierBalancesJob::class)->handle(app(SupplierDebtHandler::class));

        $this->assertSame(3_000_000.0, $this->wallet($this->user), 'طلب فقط یک بار وصول می‌شود.');
        $this->assertSame(3_000_000.0, $this->wallet($buyer), 'عودتِ خریدار با همان تسویه پرداخت می‌شود.');
        $this->assertSame(0.0, $this->wallet($this->systemUser));
        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertFalse((bool) $order->fresh()->{Order::SUPPLIER_DEBT_PENDING});
    }

    /**
     * خسارت عودت: پیش‌تر پول به سازه می‌رفت نه تأمین‌کننده، خسارت باز می‌ماند و
     * پرداختِ خسارت خریدار را دوباره می‌دوشید.
     */
    #[Test]
    public function a_return_damage_debt_pays_the_supplier_and_is_never_charged_twice(): void
    {
        Queue::fake();
        $supplier = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);

        $order = Order::create([
            Order::PLACER_USER_ID => $this->user->id,
            Order::ORDER_NUMBER => 'RTN-'.Str::random(10),
            Order::STATUS => Order::STATUS_RETURNED_TO_ORIGIN,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::SUBTOTAL => 10_000_000,
        ]);
        OrderItem::factory()->create([OrderItem::ORDER_ID => $order->id, OrderItem::SUPPLIER_USER_ID => $supplier->id]);
        $shipment = Shipment::create([
            Shipment::ORDER_ID => $order->id, Shipment::WEIGHT => 1000, Shipment::SHIPPING_COST => 0,
            Shipment::STATUS => Shipment::STATUS_RETURNED,
        ]);
        $shipment->forceFill([Shipment::RETURNED_AT => now()])->save();

        $damages = app(ReturnDamageService::class);
        $damage = $damages->record($shipment->fresh(), 1_000_000, 'کرایه عودت');
        $damages->approveByBackoffice($damage, 1);
        $damages->approveByBuyer($damage->fresh());

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay')->assertOk();
        $this->postJson("/api/profile/damages/{$damage->id}/settle")->assertOk();

        $this->assertSame(4_000_000.0, $this->wallet($this->user));
        $this->assertSame(1_000_000.0, $this->wallet($supplier), 'هزینه‌ی عودت مالِ تأمین‌کننده است.');
        $this->assertSame(0.0, $this->wallet($this->systemUser));
        $this->assertNotNull($damage->fresh()->{ReturnDamage::SETTLED_AT});
        $this->assertSame(Shipment::STATUS_PENDING, $shipment->fresh()->{Shipment::STATUS});
    }

    /**
     * اضافه‌بار: سازه کلِ مابه‌التفاوت را در باسکول گرفته بود، پس اعتبارِ دوباره‌ی
     * سازه پول می‌ساخت؛ و بارِ متوقف آزاد نمی‌شد.
     */
    #[Test]
    public function an_overweight_debt_goes_through_its_own_settlement_and_releases_the_load(): void
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->user->id,
            Order::ORDER_NUMBER => 'OW-'.Str::random(10),
            Order::STATUS => Order::STATUS_AWAITING_WEIGHT_CORRECTION,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $order->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();
        $this->debtEngine->incur((int) $this->user->id, 250_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay')->assertOk();

        $this->assertSame(4_750_000.0, $this->wallet($this->user));
        $this->assertSame(0.0, $this->wallet($this->systemUser), 'سازه پیش‌تر اعتبار گرفته؛ دومی پول خلق می‌کند.');
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->{Order::STATUS});
        $this->assertSame(0.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    #[Test]
    public function a_partial_amount_never_pays_an_order_bound_debt_in_part(): void
    {
        $order = Order::create([
            Order::PLACER_USER_ID => $this->user->id,
            Order::ORDER_NUMBER => 'OW-'.Str::random(10),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        $order->forceFill([Order::WEIGHING_COMPLETED_AT => now()])->save();
        $this->debtEngine->incur((int) $this->user->id, 2_000_000, DebtEntry::TYPE_OVERWEIGHT, $order, 'اضافه‌بار');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay', ['amount' => 500_000])
            ->assertStatus(422)
            ->assertJsonPath('message', __('orders.debt_repay_amount_too_small'));

        $this->assertSame(2_000_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
        $this->assertSame(5_000_000.0, $this->wallet($this->user));
    }

    /** نوعی که جریانش در دسترس نیست پول جابه‌جا نمی‌کند؛ ۴۲۲ و نامِ مسیرِ درست. */
    #[Test]
    public function a_debt_that_cannot_be_routed_is_refused_without_moving_money(): void
    {
        $order = Order::create([
            Order::PLACER_USER_ID => User::factory()->create()->id,
            Order::ORDER_NUMBER => 'DEF-'.Str::random(10),
            Order::STATUS => Order::STATUS_SHIPPED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
        ]);
        // ردیفِ کسری بدون پرچمِ سفارش — جریانِ تأمین‌کننده چیزی برای تسویه ندارد.
        $this->debtEngine->incur((int) $this->user->id, 1_000_000, DebtEntry::TYPE_WEIGHBRIDGE_DEFICIT, $order, 'کسری');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/profile/debts/repay')
            ->assertStatus(422)
            ->assertJsonPath('message', __('orders.debt_route_unavailable', ['order' => $order->order_number]));

        $this->assertSame(5_000_000.0, $this->wallet($this->user));
        $this->assertSame(1_000_000.0, $this->debtEngine->balanceFor((int) $this->user->id));
    }

    #[Test]
    public function the_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/profile/debts/repay')->assertUnauthorized();
    }

    /**
     * دفترهای مالی نباید با حذفِ والدشان ناپدید شوند.
     *
     * `wallet_transactions`/`debt_entries` صریحاً append-only طراحی شده‌اند تا
     * `financial:doctor` بتواند موجودی را بازپخش کند؛ `cascadeOnDelete` همان
     * تضمین را نقض می‌کرد — یک `$user->delete()` کلِ تاریخچه را می‌برد.
     */
    #[Test]
    public function a_user_with_a_debt_ledger_cannot_be_hard_deleted(): void
    {
        $this->incurDebt(1_000_000);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $this->user->id)->delete();
    }
}
