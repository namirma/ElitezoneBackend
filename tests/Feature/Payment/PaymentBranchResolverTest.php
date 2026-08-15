<?php

namespace Tests\Feature\Payment;

use App\Models\Cart;
use App\Models\Setting;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\PaymentBranch;
use App\Services\Payment\PaymentBranchResolver;
use App\Services\Payment\ReserveWarningResolver;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * درخت تصمیم پرداخت — بند ۳.۳، با مقادیر مرزی (§۱۳.۲).
 *
 * مرزها دقیقاً `≤` هستند و روی همان‌ها آزمایش می‌شوند: برابرِ دقیق، یک ریال
 * کمتر، یک ریال بیشتر. اینجا جایی است که یک `<` به‌جای `≤` کاربری را که
 * دقیقاً به‌اندازه‌ی سفارشش پول دارد به شاخه‌ی رزرو می‌فرستد.
 */
class PaymentBranchResolverTest extends TestCase
{
    use DatabaseTransactions;

    private const SHAPARAK = 2_000_000_000.0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => (string) self::SHAPARAK]);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => (string) self::SHAPARAK]);
        Cache::forget(Setting::CACHE_KEY);
    }

    private function userWithBalance(float $balance): User
    {
        $user = User::factory()->create();
        Wallet::create([
            Wallet::USER_ID => $user->id,
            Wallet::BALANCE => $balance,
            Wallet::BALANCE_HOLD => 0,
        ]);

        return $user->fresh();
    }

    private function resolver(): PaymentBranchResolver
    {
        Cache::forget(Setting::CACHE_KEY);

        return app(PaymentBranchResolver::class);
    }

    // ── مرز الف/ب: Final در برابر موجودی کیف پول ────────────────────────────

    #[Test]
    public function an_exact_wallet_match_stays_on_the_wallet_branch(): void
    {
        // `≤` و نه `<`: کاربری که دقیقاً به‌اندازه‌ی سفارشش پول دارد نباید به
        // درگاه فرستاده شود تا صفر ریال شارژ کند.
        $branch = $this->resolver()->resolve($this->userWithBalance(1_000_000), 1_000_000);

        $this->assertSame(PaymentBranch::WALLET, $branch->branch);
        $this->assertSame(0.0, $branch->gatewayAmount);
        $this->assertFalse($branch->reserveAvailable());
    }

    #[Test]
    public function one_rial_over_the_wallet_moves_to_the_gateway_branch(): void
    {
        $branch = $this->resolver()->resolve($this->userWithBalance(1_000_000), 1_000_001);

        $this->assertSame(PaymentBranch::GATEWAY, $branch->branch);
        $this->assertSame(1.0, $branch->gatewayAmount, 'فقط مابه‌التفاوت از درگاه شارژ می‌شود.');
        $this->assertSame(1_000_000.0, $branch->walletAmount);
        $this->assertFalse($branch->reserveAvailable());
    }

    #[Test]
    public function an_empty_wallet_still_uses_the_gateway_branch_below_the_cap(): void
    {
        $branch = $this->resolver()->resolve($this->userWithBalance(0), 500_000);

        $this->assertSame(PaymentBranch::GATEWAY, $branch->branch);
        $this->assertSame(500_000.0, $branch->gatewayAmount);
    }

    // ── مرز ب/ج: Final در برابر موجودی + سقف درگاه ─────────────────────────

    #[Test]
    public function exactly_wallet_plus_shaparak_is_still_payable(): void
    {
        $wallet = 5_000_000.0;
        $branch = $this->resolver()->resolve(
            $this->userWithBalance($wallet),
            $wallet + self::SHAPARAK,
        );

        $this->assertSame(PaymentBranch::GATEWAY, $branch->branch);
        $this->assertSame(self::SHAPARAK, $branch->gatewayAmount);
    }

    #[Test]
    public function one_rial_beyond_the_cap_becomes_reserve_only(): void
    {
        $wallet = 5_000_000.0;
        $branch = $this->resolver()->resolve(
            $this->userWithBalance($wallet),
            $wallet + self::SHAPARAK + 1,
        );

        $this->assertSame(PaymentBranch::RESERVE, $branch->branch);
        $this->assertTrue($branch->reserveAvailable());
        $this->assertSame(self::SHAPARAK, $branch->reserveAmount);
    }

    #[Test]
    public function the_reserve_option_is_hidden_whenever_the_buyer_can_pay(): void
    {
        // نکته‌ی ظریفِ بند ۳.۳: رزرو راهِ گریز از ناتوانیِ پرداخت است، نه یک
        // انتخابِ دلخواه که هر کسی بتواند با آن موجودی را قفل کند.
        $rich = $this->userWithBalance(10_000_000_000);

        $this->assertFalse($this->resolver()->resolve($rich, 1_000_000)->reserveAvailable());
        $this->assertFalse($this->resolver()->resolve($rich, 10_000_000_000)->reserveAvailable());
    }

    #[Test]
    public function the_same_order_branches_differently_for_two_buyers(): void
    {
        // معیار «توان پرداخت همین کاربر» است و نه اندازه‌ی سفارش — همان چیزی
        // که جایگزین `financial.reservation_threshold` شد.
        $amount = 3_000_000_000.0;

        $this->assertSame(
            PaymentBranch::GATEWAY,
            $this->resolver()->resolve($this->userWithBalance(2_000_000_000), $amount)->branch,
        );

        $this->assertSame(
            PaymentBranch::RESERVE,
            $this->resolver()->resolve($this->userWithBalance(0), $amount)->branch,
        );
    }

    #[Test]
    public function a_coupon_that_drops_the_total_changes_the_branch(): void
    {
        // Final مبلغ **پس از** کوپن است (بند ۳.۳)، پس تخفیف می‌تواند سفارشی را
        // از شاخه‌ی رزرو به شاخه‌ی درگاه بیاورد.
        $user = $this->userWithBalance(0);
        $gross = self::SHAPARAK + 100_000;

        $this->assertSame(PaymentBranch::RESERVE, $this->resolver()->resolve($user, $gross)->branch);
        $this->assertSame(PaymentBranch::GATEWAY, $this->resolver()->resolve($user, $gross - 200_000)->branch);
    }

    #[Test]
    public function a_guest_without_a_wallet_is_treated_as_zero_balance(): void
    {
        $branch = $this->resolver()->resolve(null, 100_000);

        $this->assertSame(PaymentBranch::GATEWAY, $branch->branch);
        $this->assertSame(0.0, $branch->walletBalance);
    }

    // ── هشدارِ رزرو دیرهنگام (الحاقیه‌ی ۱، تغییر ۲) ─────────────────────────

    private function cartForSupplierWithReserveTime(?CarbonInterface $reserveTime): Cart
    {
        $supplier = User::factory()->create();
        SupplierProfile::create([SupplierProfile::USER_ID => $supplier->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => $reserveTime,
            ])->save();

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplier->id])->save();

        return $cart->fresh();
    }

    private function setWarningThreshold(int $minutes): void
    {
        Setting::updateOrCreate(
            [Setting::KEY => 'reserve_warning_threshold_minutes'],
            [Setting::VALUE => (string) $minutes]
        );
        Cache::forget(Setting::CACHE_KEY);
    }

    #[Test]
    public function no_warning_when_the_price_window_is_comfortably_far(): void
    {
        $this->setWarningThreshold(180);
        $cart = $this->cartForSupplierWithReserveTime(now()->addDays(2));

        $warning = app(ReserveWarningResolver::class)->forCart($cart);

        $this->assertFalse($warning['show']);
    }

    #[Test]
    public function a_warning_fires_inside_the_threshold_and_names_the_remaining_time(): void
    {
        $this->setWarningThreshold(180);
        $cart = $this->cartForSupplierWithReserveTime(now()->addMinutes(45));

        $warning = app(ReserveWarningResolver::class)->forCart($cart);

        $this->assertTrue($warning['show']);
        $this->assertEqualsWithDelta(45 * 60, $warning['remaining_seconds'], 60);
        $this->assertNotSame('', $warning['message']);
        // متن باید هر دو نکته را بگوید: کم‌بودنِ وقت، و اینکه تمدید دستِ
        // تأمین‌کننده است (الحاقیه‌ی ۱).
        $this->assertStringContainsString('تأمین‌کننده', $warning['message']);
    }

    #[Test]
    public function an_expired_price_window_shows_no_warning(): void
    {
        // قیمت‌ها منقضی‌اند — این «کم‌وقتی» نیست، اصلاً قابل رزرو نیست و مسیرِ
        // خودش جلویش را می‌گیرد. دو پیامِ متناقض به کاربر نمی‌رود.
        $this->setWarningThreshold(180);
        $cart = $this->cartForSupplierWithReserveTime(now()->subMinute());

        $this->assertFalse(app(ReserveWarningResolver::class)->forCart($cart)['show']);
    }

    #[Test]
    public function a_cart_without_a_locked_supplier_shows_no_warning(): void
    {
        $this->setWarningThreshold(180);
        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);

        $this->assertFalse(app(ReserveWarningResolver::class)->forCart($cart)['show']);
    }
}
