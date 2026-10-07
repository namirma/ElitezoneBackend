<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Actions\Order\RefundOrderToWalletAction;
use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponFunder;
use App\Models\CouponFundingLeg;
use App\Models\EngineerProfile;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Marketing\CouponFundingService;
use App\Services\Marketing\CouponService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FundsCouponWallet;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * تأمینِ تخفیفِ کوپن از سه منبع — `docs/specs/COUPON_FUNDING_2026-09-15.md`.
 *
 * fixtures عیناً از `CouponSourceOfTruthTest`: سبدِ تک‌ردیفیِ ۹۹۰٬۰۰۰ ریال/کیلو،
 * ۱۰۰ کیلو، کیف پولِ خریدار و سازه پر. کیف پولِ تأمین‌کننده این‌جا ۱۰ میلیون
 * دارد تا لِگِ خودش را بپوشاند.
 */
class CouponFundingTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;
    use FundsCouponWallet;
    use GrantsAdminPermissions;

    private const SUPPLIER_WALLET = 10_000_000.0;

    private const SYSTEM_WALLET = 500_000_000.0;

    /** تخفیفِ ۵۰/۵۰: سهمِ شرکت (۵۰۰ هزار) زیرِ استخرِ همین سفارش (≈۵۴۰ هزار) می‌ماند، پس از موجودیِ سازه چیزی نمی‌رود. */
    private const SPLIT_DISCOUNT = 1_000_000.0;

    private User $buyer;

    private User $supplier;

    private User $system;

    /** «کیف پول کوپن‌های تخفیف» — سهمِ شرکت از کوپن فقط از این‌جا (مدیریت، دورِ پنجم). */
    private User $couponWallet;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();
        // ماژولِ مشارکتِ تأمین‌کننده/الیت‌یار در کوپن از دورِ ششم (۱۴۰۵/۰۷/۱۵) خاموش است («فقط شرکت کوپن
        // صادر می‌کند»)؛ کد مانده (قانونِ یک) و این تست‌ها با پرچمِ روشن سالم نگهش می‌دارند.
        config(['features.partner_coupons' => true]);

        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
            $mock->shouldReceive('generateCancellationDocs')->andReturnNull();
        });

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Setting::updateOrCreate([Setting::KEY => 'reserve_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->buyer = User::factory()->create();
        Wallet::create([Wallet::USER_ID => $this->buyer->id, Wallet::BALANCE => self::SYSTEM_WALLET, Wallet::BALANCE_HOLD => 0]);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => self::SUPPLIER_WALLET, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $this->supplier->id])
            ->forceFill([
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                SupplierProfile::RESERVE_TIME => now()->addDays(3),
            ])->save();

        $this->factory = $this->makeFactory();
        $this->factory->forceFill(['supplier_user_id' => $this->supplier->id])->save();
        $this->location = $this->makeLocation($this->factory);
        $this->variant = $this->makeVariant();
        $this->makePrice($this->variant, $this->factory, $this->location, general: 1_000_000, special: 900_000);

        FactoryStock::updateOrCreate([
            'product_variant_id' => $this->variant->id,
            'factory_id' => $this->factory->id,
            'factory_location_id' => $this->location->id,
        ], ['quantity' => 100_000]);

        $this->shipping = ShippingMethod::create([
            ShippingMethod::NAME => 'تست حمل',
            ShippingMethod::CODE => 'SHIP_'.Str::random(6),
        ]);

        $this->system = User::factory()->create();
        config([
            'financial.system_user_id' => $this->system->id,
            'financial.vat_user_id' => $this->system->id,
        ]);
        Wallet::create([Wallet::USER_ID => $this->system->id, Wallet::BALANCE => self::SYSTEM_WALLET, Wallet::BALANCE_HOLD => 0]);

        $this->couponWallet = $this->fundCouponWallet(self::SYSTEM_WALLET);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  list<array{0: string, 1: int|null, 2: float, 3?: string}>  $funders  [نوع، کاربر، درصد، وضعیت]
     */
    private function makeCoupon(float $amount, array $funders, string $type = Coupon::TYPE_FIXED): Coupon
    {
        $coupon = Coupon::create([
            Coupon::CODE => 'FND-'.strtoupper(Str::random(6)),
            Coupon::TYPE => $type,
            Coupon::AMOUNT => $amount,
        ]);

        foreach ($funders as [$funderType, $userId, $percent]) {
            $coupon->funders()->create([
                CouponFunder::FUNDER_TYPE => $funderType,
                CouponFunder::FUNDER_USER_ID => $userId,
                CouponFunder::SHARE_PERCENT => $percent,
                CouponFunder::STATUS => CouponFunder::STATUS_ACCEPTED,
                CouponFunder::RESPONDED_AT => now(),
            ]);
        }

        return $coupon->fresh(['funders']);
    }

    private function fillCart(int $kg, ?string $couponCode): Cart
    {
        $unit = OrderUnit::create([
            OrderUnit::NAME => 'کیلوگرم',
            OrderUnit::CODE => 'KG_'.Str::random(6),
            OrderUnit::UNIT => 'kg',
            OrderUnit::AMOUNT => 1,
        ]);

        $cart = Cart::create([Cart::USER_ID => $this->buyer->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplier->id,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
            Cart::COUPON_CODE => $couponCode,
        ])->save();

        $cart->items()->create([
            CartItem::PRODUCT_VARIANT_ID => $this->variant->id,
            CartItem::FACTORY_ID => $this->factory->id,
            CartItem::FACTORY_LOCATION_ID => $this->location->id,
            CartItem::SHIPPING_METHOD_ID => $this->shipping->id,
            CartItem::QUANTITIES => [(string) $unit->id => $kg],
            CartItem::WEIGHT => $kg,
            CartItem::QUANTITY => $kg,
            CartItem::UNIT_NAME => 'کیلوگرم',
            CartItem::PRICE => 900_000,
            CartItem::FINAL_PRICE => 900_000,
            CartItem::VAT_AMOUNT => 90_000,
            CartItem::TOTAL_WITH_VAT => $kg * 990_000,
        ]);

        return $cart->fresh(['items']);
    }

    private function pay(array $extra = [])
    {
        Sanctum::actingAs($this->buyer);

        return $this->postJson('/api/checkout/pay', array_merge([
            'address_id' => Address::create([
                Address::USER_ID => $this->buyer->id,
                Address::TITLE => 'خانه',
                Address::POSTAL_ADDRESS => 'آدرس تست',
                Address::RECEIVER_NAME => 'گیرنده',
                Address::RECEIVER_MOBILE => '09120000000',
            ])->id,
            'shipping_method_id' => $this->shipping->id,
        ], $extra));
    }

    private function balance(User $user): float
    {
        return (float) Wallet::where(Wallet::USER_ID, $user->id)->value(Wallet::BALANCE);
    }

    /** الیت‌یارِ تأییدشده و وصل‌شده به خریدار (مسیرِ کارفرما با الیت‌یار). */
    private function makeVerifiedEliteYARFor(User $employer, float $wallet): User
    {
        // تأییدِ لانچ = عضویتِ سازه ۹۰؛ پرچمِ مدرکیِ پایین فقط با روشِ مدرکیِ روشن می‌شمارد.
        $eliteYAR = User::factory()->create([User::IS_SAZE90_USER => true]);
        $eliteYAR->addRole(Role::firstOrCreate(['name' => 'engineer'], ['display_name' => 'engineer']));
        EngineerProfile::create([
            EngineerProfile::USER_ID => $eliteYAR->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
            EngineerProfile::IS_VERIFIED => true,
        ]);
        $employer->technicalEliteYARs()->attach($eliteYAR->id, ['status' => 'approved']);
        Wallet::create([Wallet::USER_ID => $eliteYAR->id, Wallet::BALANCE => $wallet, Wallet::BALANCE_HOLD => 0]);

        return $eliteYAR;
    }

    // ── لحظه‌ی پرداخت ───────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_and_company_split_is_debited_from_both_wallets(): void
    {
        $coupon = $this->makeCoupon(self::SPLIT_DISCOUNT, [
            [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 50],
            [CouponFunder::TYPE_COMPANY, null, 50],
        ]);
        $this->fillCart(100, $coupon->code);

        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();
        $poo = (float) $order->{Order::PAYMENT_OF_OWNER};
        $pts = (float) $order->{Order::PAYMENT_TO_SUPPLIER};

        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertEqualsWithDelta(self::SPLIT_DISCOUNT, (float) $order->{Order::COUPON_DISCOUNT_AMOUNT}, 1.0);

        // دو لِگ، یکی برای هر تأمین‌کننده، جمعشان دقیقاً تخفیف.
        $legs = CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)->get();
        $this->assertCount(2, $legs);
        $this->assertEqualsWithDelta(self::SPLIT_DISCOUNT, (float) $legs->sum(CouponFundingLeg::AMOUNT), 0.0);
        $this->assertEqualsWithDelta(self::SPLIT_DISCOUNT / 2, (float) $legs->firstWhere(CouponFundingLeg::FUNDER_TYPE, CouponFunder::TYPE_SUPPLIER)->amount, 0.0);

        // خریدار: POO پرداخته و کلِ تخفیف را پس گرفته.
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET - $poo + self::SPLIT_DISCOUNT, $this->balance($this->buyer), 1.0);
        // تأمین‌کننده: PTS گرفته و نصفِ تخفیف داده.
        $this->assertEqualsWithDelta(self::SUPPLIER_WALLET + $pts - self::SPLIT_DISCOUNT / 2, $this->balance($this->supplier), 1.0);
        // سازه: POO گرفته و PTS داده؛ سهمِ شرکت از تخفیف از کیف پولِ کوپن رفته، نه از سازه.
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET + $poo - $pts, $this->balance($this->system), 1.0);
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET - self::SPLIT_DISCOUNT / 2, $this->balance($this->couponWallet), 0.0);
        $this->assertSame(
            FinancialCodes::WALLET_COUPON,
            $legs->firstWhere(CouponFundingLeg::FUNDER_TYPE, CouponFunder::TYPE_COMPANY)->{CouponFundingLeg::WALLET_CODE}
        );

        // لِگِ تأمین‌کننده در دفترِ کیف پولش با شرحِ تأمین ثبت شده.
        $supplierWalletId = Wallet::where(Wallet::USER_ID, $this->supplier->id)->value('id');
        $this->assertTrue(
            WalletTransaction::where('wallet_id', $supplierWalletId)
                ->where('order_id', $order->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAW)
                ->where('description', 'like', '%تأمین%'.$coupon->code.'%')
                ->exists()
        );
    }

    #[Test]
    public function a_refund_returns_each_leg_to_its_funder(): void
    {
        $coupon = $this->makeCoupon(self::SPLIT_DISCOUNT, [
            [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 50],
            [CouponFunder::TYPE_COMPANY, null, 50],
        ]);
        $this->fillCart(100, $coupon->code);
        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();

        app(RefundOrderToWalletAction::class)->execute($order);

        $this->assertSame(Order::PAYMENT_REFUNDED, $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET, $this->balance($this->buyer), 1.0);
        $this->assertEqualsWithDelta(self::SUPPLIER_WALLET, $this->balance($this->supplier), 1.0);
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET, $this->balance($this->system), 1.0);
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET, $this->balance($this->couponWallet), 0.0, 'سهمِ شرکت به کیف پولِ کوپن برمی‌گردد.');
        $this->assertSame(
            0,
            CouponFundingLeg::where(CouponFundingLeg::ORDER_ID, $order->id)->whereNull(CouponFundingLeg::REVERSED_AT)->count()
        );
    }

    // ── لحظه‌ی ثبت سفارش — گاردها ──────────────────────────────────────────

    /**
     * پاسخِ مدیریت به پرسشِ ۱۰ (۱۴۰۵/۰۷/۱۳، گزینه‌ی ب): سقفِ «سهمِ شرکت از همین سفارش» برداشته
     * شد. دورِ پنجم (۱۴۰۵/۰۷/۱۴): سهمِ شرکت فقط از «کیف پول کوپن‌های تخفیف» — نه از WSSG و نه از
     * استخرِ سفارش — «مادام که این کیف پول دارای منابع است».
     */
    #[Test]
    public function a_company_coupon_larger_than_the_orders_company_share_is_funded_from_the_coupon_wallet(): void
    {
        // ۵ میلیون از ~۹۹ میلیون سفارش؛ سهمِ شرکت از استخرِ این سفارش چند صد هزار ریال است.
        $coupon = $this->makeCoupon(5_000_000, [[CouponFunder::TYPE_COMPANY, null, 100]]);
        $this->fillCart(100, $coupon->code);

        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();
        $poo = (float) $order->{Order::PAYMENT_OF_OWNER};
        $pts = (float) $order->{Order::PAYMENT_TO_SUPPLIER};

        $this->assertEqualsWithDelta(5_000_000.0, (float) $order->{Order::COUPON_DISCOUNT_AMOUNT}, 1.0);
        // سازه فقط POO − PTS؛ ۵ میلیون تخفیف از کیف پولِ کوپن.
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET + $poo - $pts, $this->balance($this->system), 1.0);
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET - 5_000_000, $this->balance($this->couponWallet), 0.0);
        $this->assertNotNull($coupon->fresh()->{Coupon::USED_AT}, 'کوپنِ بی‌بودجه تک‌مصرفه است.');
    }

    #[Test]
    public function a_company_coupon_the_coupon_wallet_cannot_cover_is_refused_before_any_write(): void
    {
        // سازه پُر است ولی کیف پولِ کوپن خالی: سهمِ شرکت دیگر از سازه برداشته نمی‌شود.
        Wallet::where(Wallet::USER_ID, $this->couponWallet->id)->update([Wallet::BALANCE => 1_000_000]);
        $coupon = $this->makeCoupon(5_000_000, [[CouponFunder::TYPE_COMPANY, null, 100]]);
        $this->fillCart(100, $coupon->code);

        $response = $this->pay();

        $response->assertStatus(422);
        $this->assertStringContainsString('کیف پول کوپن‌های تخفیف', $response->json('message'));
        $this->assertSame(0, Order::where(Order::PLACER_USER_ID, $this->buyer->id)->count(), 'سفارشی نباید ساخته شود.');
        $this->assertNull($coupon->fresh()->{Coupon::USED_AT}, 'کوپن نباید مصرف شود.');
    }

    // ── بودجه‌ی کمپین (پرسشِ ۱۰ مدیریت) ──────────────────────────────────────

    #[Test]
    public function a_campaign_coupon_is_reusable_until_its_budget_is_consumed(): void
    {
        // سقفِ بودجه از دورِ ششم (پرسشِ ۴) خاموش است؛ این تست ماژولِ نگه‌داشته را با پرچمِ روشن می‌سنجد.
        config(['features.campaign_budget' => true]);
        $coupon = $this->makeCoupon(400_000, [[CouponFunder::TYPE_COMPANY, null, 100]]);
        $coupon->forceFill([Coupon::BUDGET_TOTAL => 1_000_000])->save();

        // اول و دوم: ۴۰۰ + ۴۰۰ هزار از بودجه‌ی یک میلیون.
        foreach ([400_000, 800_000] as $expectedUsed) {
            $this->fillCart(100, $coupon->code);
            $this->pay()->assertOk();

            $fresh = $coupon->fresh();
            $this->assertNull($fresh->{Coupon::USED_AT}, 'کوپنِ کمپینی هرگز used_at نمی‌گیرد.');
            $this->assertEqualsWithDelta($expectedUsed, (float) $fresh->{Coupon::BUDGET_USED}, 0.0);
            $this->assertEqualsWithDelta(1_000_000 - $expectedUsed, $fresh->budgetRemaining(), 0.0);
        }

        // سوم: ۴۰۰ هزار > ۲۰۰ هزارِ باقی‌مانده ⇒ رد با پیامِ بودجه، بی‌هیچ نوشتنی.
        $this->fillCart(100, $coupon->code);
        $response = $this->pay();
        $response->assertStatus(422);
        $this->assertStringContainsString('بودجه', $response->json('message'));
        $this->assertSame(2, Order::where(Order::PLACER_USER_ID, $this->buyer->id)->count());
        $this->assertEqualsWithDelta(800_000, (float) $coupon->fresh()->{Coupon::BUDGET_USED}, 0.0);
    }

    #[Test]
    public function a_split_campaign_coupon_only_charges_the_companys_share_to_the_budget(): void
    {
        // ۵۰/۵۰ با تأمین‌کننده: از ۱ میلیون تخفیف فقط ۵۰۰ هزار سهمِ شرکت است و همان از بودجه می‌رود.
        $coupon = $this->makeCoupon(self::SPLIT_DISCOUNT, [
            [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 50],
            [CouponFunder::TYPE_COMPANY, null, 50],
        ]);
        $coupon->forceFill([Coupon::BUDGET_TOTAL => 10_000_000])->save();
        $this->fillCart(100, $coupon->code);

        $this->pay()->assertOk();

        $this->assertEqualsWithDelta(self::SPLIT_DISCOUNT / 2, (float) $coupon->fresh()->{Coupon::BUDGET_USED}, 0.0);
    }

    #[Test]
    public function releasing_a_campaign_order_returns_the_companys_share_to_the_budget(): void
    {
        $coupon = $this->makeCoupon(400_000, [[CouponFunder::TYPE_COMPANY, null, 100]]);
        $coupon->forceFill([Coupon::BUDGET_TOTAL => 1_000_000, Coupon::BUDGET_USED => 400_000])->save();
        $order = Order::factory()->create([Order::COUPON_ID => $coupon->id]);
        $order->update([Order::COUPON_DISCOUNT_AMOUNT => 400_000]);

        app(CouponService::class)->releaseForOrder($order->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $coupon->fresh()->{Coupon::BUDGET_USED}, 0.0);
    }

    #[Test]
    public function the_admin_creates_a_campaign_by_giving_the_coupon_a_budget(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/coupons', [
            Coupon::CODE => 'CAMP-'.strtoupper(Str::random(5)),
            Coupon::TYPE => Coupon::TYPE_PERCENT,
            Coupon::AMOUNT => 5,
            Coupon::BUDGET_TOTAL => 2_000_000,
        ])->assertCreated()->json('data');

        $this->assertTrue($created['is_campaign']);
        $this->assertSame('2000000', $created[Coupon::BUDGET_TOTAL]);
        $this->assertSame('2000000', $created['budget_remaining']);
    }

    #[Test]
    public function an_elite_yar_coupon_over_the_elite_yar_share_is_rejected_naming_the_elite_yar(): void
    {
        $eliteYAR = $this->makeVerifiedEliteYARFor($this->buyer, wallet: 50_000_000);

        $coupon = $this->makeCoupon(5_000_000, [[CouponFunder::TYPE_ELITE_YAR, $eliteYAR->id, 100]]);
        $this->fillCart(100, $coupon->code);

        $response = $this->pay(['elite_yar_user_id' => $eliteYAR->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('سقف تأمین او', $response->json('message'));
        $this->assertStringContainsString($eliteYAR->mobile, $response->json('message'));
        $this->assertSame(0, Order::where(Order::PLACER_USER_ID, $this->buyer->id)->count());
    }

    #[Test]
    public function an_elite_yar_coupon_within_the_share_is_debited_from_the_elite_yar_wallet(): void
    {
        $eliteYAR = $this->makeVerifiedEliteYARFor($this->buyer, wallet: 50_000_000);

        // سهمِ خالصِ الیت‌یار روی این سفارش چند ده هزار ریال است؛ ۱۰٬۰۰۰ ریال زیرِ سقف می‌ماند.
        $coupon = $this->makeCoupon(10_000, [[CouponFunder::TYPE_ELITE_YAR, $eliteYAR->id, 100]]);
        $this->fillCart(100, $coupon->code);

        $this->pay(['elite_yar_user_id' => $eliteYAR->id])->assertOk();

        $this->assertEqualsWithDelta(50_000_000 - 10_000, $this->balance($eliteYAR), 0.0);
        $this->assertSame(1, CouponFundingLeg::where(CouponFundingLeg::FUNDER_USER_ID, $eliteYAR->id)->count());
    }

    #[Test]
    public function a_supplier_whose_wallet_cannot_cover_the_leg_is_named_instead_of_the_buyer(): void
    {
        Wallet::where(Wallet::USER_ID, $this->supplier->id)->update([Wallet::BALANCE => 100_000]);

        $coupon = $this->makeCoupon(2_000_000, [[CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 100]]);
        $this->fillCart(100, $coupon->code);

        $response = $this->pay();

        $response->assertStatus(422);
        $this->assertStringContainsString('مشکل از کیف پول شما نیست', $response->json('message'));
        $this->assertStringContainsString($this->supplier->mobile, $response->json('message'));
        $this->assertEqualsWithDelta(self::SYSTEM_WALLET, $this->balance($this->buyer), 0.0, 'از خریدار چیزی کم نشده.');
    }

    #[Test]
    public function a_supplier_coupon_only_applies_to_that_suppliers_orders(): void
    {
        $other = User::factory()->create();
        $other->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'supplier']));
        Wallet::create([Wallet::USER_ID => $other->id, Wallet::BALANCE => 50_000_000, Wallet::BALANCE_HOLD => 0]);

        $coupon = $this->makeCoupon(2_000_000, [[CouponFunder::TYPE_SUPPLIER, $other->id, 100]]);
        $this->fillCart(100, $coupon->code);

        $response = $this->pay();

        $response->assertStatus(422);
        $this->assertStringContainsString('همان تأمین‌کننده', $response->json('message'));
    }

    #[Test]
    public function a_coupon_with_a_pending_funder_is_not_usable_anywhere(): void
    {
        $coupon = $this->makeCoupon(2_000_000, [[CouponFunder::TYPE_COMPANY, null, 50]]);
        $coupon->funders()->create([
            CouponFunder::FUNDER_TYPE => CouponFunder::TYPE_SUPPLIER,
            CouponFunder::FUNDER_USER_ID => $this->supplier->id,
            CouponFunder::SHARE_PERCENT => 50,
            CouponFunder::STATUS => CouponFunder::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/checkout/coupon-preview', ['code' => $coupon->code])
            ->assertStatus(422)
            ->assertJsonPath('message', __('coupons.funding_not_ready'));

        $this->fillCart(100, $coupon->code);
        $this->pay()->assertStatus(422)->assertJsonPath('message', __('coupons.funding_not_ready'));
    }

    #[Test]
    public function the_split_is_whole_rials_and_the_last_funder_absorbs_the_remainder(): void
    {
        $coupon = $this->makeCoupon(1_000_001, [
            [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 33.33],
            [CouponFunder::TYPE_COMPANY, null, 66.67],
        ]);

        $legs = app(CouponFundingService::class)->plan(
            $coupon,
            1_000_001,
            ['company' => 1_000_000_000, 'elite_yar' => 0],
            $this->supplier->id,
            null,
        );

        // ۳۳٫۳۳٪ × ۱٬۰۰۰٬۰۰۱ = ۳۳۳٬۳۰۰٫۳۳ → کف؛ باقی‌مانده به ردیفِ آخر.
        $this->assertSame(333_300.0, $legs[0]['amount']);
        $this->assertSame(666_701.0, $legs[1]['amount']);
        $this->assertSame(1_000_001.0, $legs[0]['amount'] + $legs[1]['amount']);
    }

    #[Test]
    public function a_coupon_with_no_funder_rows_is_the_company_at_one_hundred_percent(): void
    {
        $coupon = Coupon::create([
            Coupon::CODE => 'LEGACY-'.strtoupper(Str::random(4)),
            Coupon::TYPE => Coupon::TYPE_FIXED,
            Coupon::AMOUNT => 10_000,
        ]);

        $this->assertTrue($coupon->isFundingReady());

        // بی‌ردیف = شرکت ۱۰۰٪؛ بیشتر از استخرِ سفارش، از «کیف پول کوپن‌های تخفیف» (دورِ پنجم).
        $legs = app(CouponFundingService::class)->plan($coupon, 10_000, ['company' => 5_000, 'elite_yar' => 0], $this->supplier->id, null);
        $this->assertCount(1, $legs);
        $this->assertSame(CouponFunder::TYPE_COMPANY, $legs[0]['funder']->{CouponFunder::FUNDER_TYPE});
        $this->assertEqualsWithDelta(10_000.0, $legs[0]['amount'], 0.0);

        // … و وقتی کیف پولِ کوپن موجودی ندارد، همان‌جا با پیامِ روشن رد می‌شود — حتی با سازه‌ی پُر.
        Wallet::where(Wallet::USER_ID, $this->couponWallet->id)->update([Wallet::BALANCE => 0]);
        try {
            app(CouponFundingService::class)->plan($coupon, 10_000, ['company' => 5_000, 'elite_yar' => 0], $this->supplier->id, null);
            $this->fail('بی‌موجودیِ کیف پولِ کوپن باید رد شود.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('کیف پول کوپن‌های تخفیف', $e->getMessage());
        }
    }

    // ── پیشنهاد و پاسخ ─────────────────────────────────────────────────────

    #[Test]
    public function a_supplier_creates_a_split_coupon_and_the_admin_accepts_the_company_half(): void
    {
        Bus::fake([SendSmsJob::class]);

        Sanctum::actingAs($this->supplier);
        $create = $this->postJson('/api/profile/supplier/coupons', [
            'code' => 'SPLIT-'.strtoupper(Str::random(4)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 2_000_000,
            'funders' => [
                ['funder_type' => CouponFunder::TYPE_SUPPLIER, 'share_percent' => 50],
                ['funder_type' => CouponFunder::TYPE_COMPANY, 'share_percent' => 50],
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.funding_status', 'pending')
            ->assertJsonPath('data.created_by_user_id', $this->supplier->id);

        $couponId = $create->json('data.id');
        $companyRow = CouponFunder::where(CouponFunder::COUPON_ID, $couponId)
            ->where(CouponFunder::FUNDER_TYPE, CouponFunder::TYPE_COMPANY)->firstOrFail();
        $this->assertSame(CouponFunder::STATUS_PENDING, $companyRow->status);
        $this->assertSame(
            CouponFunder::STATUS_ACCEPTED,
            CouponFunder::where(CouponFunder::COUPON_ID, $couponId)->where(CouponFunder::FUNDER_USER_ID, $this->supplier->id)->value('status'),
            'ردیفِ خودِ سازنده خودکار پذیرفته می‌شود.'
        );

        // ادمین: صف، بعد پذیرش.
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/coupon-funders?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $companyRow->id]);

        $this->postJson("/api/admin/coupon-funders/{$companyRow->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', CouponFunder::STATUS_ACCEPTED);

        $this->assertTrue(Coupon::findOrFail($couponId)->isFundingReady());

        // پاسخ به سازنده پیامک می‌شود.
        Bus::assertDispatched(SendSmsJob::class);

        // بارِ دوم ۴۰۹.
        $this->postJson("/api/admin/coupon-funders/{$companyRow->id}/accept")->assertStatus(409);
    }

    #[Test]
    public function a_proposed_supplier_gets_a_request_and_only_they_can_answer_it(): void
    {
        Bus::fake([SendSmsJob::class]);

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/coupons', [
            'code' => 'ADM-'.strtoupper(Str::random(4)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => [
                ['funder_type' => CouponFunder::TYPE_SUPPLIER, 'funder_mobile' => $this->supplier->mobile, 'share_percent' => 60],
                ['funder_type' => CouponFunder::TYPE_COMPANY, 'share_percent' => 40],
            ],
        ]);

        $create->assertCreated()->assertJsonPath('data.funding_status', 'pending');

        // شرکت با ادمین خودکار پذیرفته؛ تأمین‌کننده pending و پیامک گرفته.
        $funders = collect($create->json('data.funders'));
        $this->assertSame(CouponFunder::STATUS_ACCEPTED, $funders->firstWhere('funder_type', CouponFunder::TYPE_COMPANY)['status']);
        $supplierRow = $funders->firstWhere('funder_type', CouponFunder::TYPE_SUPPLIER);
        $this->assertSame(CouponFunder::STATUS_PENDING, $supplierRow['status']);
        $this->assertSame($this->supplier->id, $supplierRow['funder_user_id']);
        Bus::assertDispatched(SendSmsJob::class);

        // غریبه نمی‌تواند پاسخ دهد.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/profile/coupon-funders/{$supplierRow['id']}/accept")->assertStatus(403);

        // خودِ تأمین‌کننده: می‌بیند و رد می‌کند.
        Sanctum::actingAs($this->supplier);
        $this->getJson('/api/profile/coupon-funders?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $supplierRow['id']]);

        $this->postJson("/api/profile/coupon-funders/{$supplierRow['id']}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', CouponFunder::STATUS_DECLINED);

        $this->assertSame('declined', Coupon::findOrFail($create->json('data.id'))->fundingStatus());
    }

    #[Test]
    public function funder_shares_must_sum_to_one_hundred(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/coupons', [
            'code' => 'SUM-'.strtoupper(Str::random(4)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => [
                ['funder_type' => CouponFunder::TYPE_COMPANY, 'share_percent' => 70],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['funders']);

        // ورودیِ ناهنجار ۴۲۲ می‌ماند، نه ۵۰۰.
        $this->postJson('/api/admin/coupons', [
            'code' => 'SUM-'.strtoupper(Str::random(4)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => ['x', ['funder_type' => ['a'], 'share_percent' => 'b']],
        ])->assertStatus(422);
    }

    #[Test]
    public function the_creator_must_fund_their_own_coupon(): void
    {
        Sanctum::actingAs($this->supplier);

        $this->postJson('/api/profile/supplier/coupons', [
            'code' => 'OWN-'.strtoupper(Str::random(4)),
            'type' => Coupon::TYPE_FIXED,
            'amount' => 1_000_000,
            'funders' => [['funder_type' => CouponFunder::TYPE_COMPANY, 'share_percent' => 100]],
        ])->assertStatus(422)->assertJsonPath('errors.funders.0', __('coupons.creator_must_fund'));
    }

    #[Test]
    public function funding_legs_are_visible_to_the_funder_per_order(): void
    {
        $coupon = $this->makeCoupon(self::SPLIT_DISCOUNT, [
            [CouponFunder::TYPE_SUPPLIER, $this->supplier->id, 50],
            [CouponFunder::TYPE_COMPANY, null, 50],
        ]);
        $this->fillCart(100, $coupon->code);
        $this->pay()->assertOk();

        $order = Order::where(Order::PLACER_USER_ID, $this->buyer->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->supplier);
        $this->getJson('/api/profile/coupon-funding-legs')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.coupon_code', $coupon->code)
            ->assertJsonPath('data.0.amount', '500000');

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/coupon-funding-legs?funder_type=company')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.amount', '500000');
    }
}
