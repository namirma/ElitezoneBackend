<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FactoryStock;
use App\Models\Order;
use App\Models\OrderUnit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\PricingRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pricing\CreatesPricingFixtures;
use Tests\TestCase;

/**
 * پاسخ‌های مدیریت به پرسش‌های ۱ و ۸ (۱۴۰۵/۰۷/۱۳، `docs/session/MANAGEMENT_ANSWERS_2026-10-05.md`):
 *
 *  ۱) الیت‌یار = **دارنده‌ی لایسنسِ سازه ۹۰** (نه عضوِ سایت)؛ قدیمی‌ها (مهندس/پیمانکار) و
 *     تازه‌ها (`elite_yar`) هر دو الیت‌یارند؛ بی‌لایسنس ⇒ **هولد**. قیمتِ با تخفیف به
 *     الیت‌یارِ تأییدشده و کارفرمایی می‌رسد که الیت‌یارِ تأییدشده دارد.
 *  ۸) سفارشِ خودِ کارفرما: یک الیت‌یارِ تأییدشده ⇒ خودکار؛ چند تا ⇒ انتخاب.
 *
 * هر تست همان چیزی را می‌سنجد که پیش از این اشتباه بود: گروهِ قیمت و الیت‌یارِ سفارش
 * از نقش‌های قدیمی خوانده می‌شدند، پس الیت‌یارِ تازه‌ی سازه ۹۰ کارفرما حساب می‌شد و
 * کارفرمایی که الیت‌یار داشت نه تخفیف می‌دید نه سفارشش الیت‌یار داشت.
 */
class EliteYARPricingAndAttachmentTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private User $supplier;

    private $factory;

    private $location;

    private $variant;

    private ShippingMethod $shipping;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // سازه ۹۰ در تست‌ها در دسترس نیست؛ SyncUserBadgeAction با outage به آخرین وضعیت
        // (ستونِ users.is_saze90_user) برمی‌گردد — همان پرچمی که fixtureها می‌نویسند.
        Http::fake(['*saze90*' => Http::response(null, 503)]);

        $this->mock(InvoiceGenerator::class, function ($mock) {
            $mock->shouldReceive('generateDirectPurchaseDocs')->andReturnNull();
            $mock->shouldReceive('generateReservationDocs')->andReturnNull();
        });

        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '2000000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->supplier = User::factory()->create();
        $this->supplier->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'supplier']));
        Wallet::create([Wallet::USER_ID => $this->supplier->id, Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0]);
        SupplierProfile::create([SupplierProfile::USER_ID => $this->supplier->id])
            ->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED, SupplierProfile::RESERVE_TIME => now()->addDays(3)])
            ->save();

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

        $this->shipping = ShippingMethod::create([ShippingMethod::NAME => 'تست حمل', ShippingMethod::CODE => 'SHIP_'.Str::random(6)]);

        $system = User::factory()->create();
        config(['financial.system_user_id' => $system->id, 'financial.vat_user_id' => $system->id]);
        Wallet::create([Wallet::USER_ID => $system->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name], ['display_name' => $name]);
    }

    /** الیت‌یارِ تازه (فقط نقشِ `elite_yar`)، با یا بی لایسنسِ سازه ۹۰. */
    private function eliteYAR(bool $licensed): User
    {
        $user = User::factory()->create([User::IS_SAZE90_USER => $licensed]);
        $user->addRole($this->role('elite_yar'));
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);

        return $user;
    }

    private function employer(): User
    {
        $user = User::factory()->create();
        $user->addRole($this->role('employer'));
        Wallet::create([Wallet::USER_ID => $user->id, Wallet::BALANCE => 500_000_000, Wallet::BALANCE_HOLD => 0]);

        return $user;
    }

    private function link(User $employer, User $eliteYAR, string $status = 'approved'): void
    {
        $employer->technicalEliteYARs()->attach($eliteYAR->id, ['status' => $status]);
    }

    private function fillCart(User $owner, int $kg = 100): Cart
    {
        $unit = OrderUnit::create([OrderUnit::NAME => 'کیلوگرم', OrderUnit::CODE => 'KG_'.Str::random(6), OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1]);

        $cart = Cart::create([Cart::USER_ID => $owner->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $this->supplier->id,
            Cart::PARKED_UNTIL => now()->addHours(2),
            Cart::SHIPPING_METHOD_ID => $this->shipping->id,
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

    private function pay(User $buyer, array $extra = [])
    {
        Sanctum::actingAs($buyer);

        return $this->postJson('/api/checkout/pay', array_merge([
            'address_id' => Address::create([
                Address::USER_ID => $buyer->id,
                Address::TITLE => 'خانه',
                Address::POSTAL_ADDRESS => 'آدرس تست',
                Address::RECEIVER_NAME => 'گیرنده',
                Address::RECEIVER_MOBILE => '09120000000',
            ])->id,
            'shipping_method_id' => $this->shipping->id,
        ], $extra));
    }

    private function lastOrderOf(User $placer): Order
    {
        return Order::where(Order::PLACER_USER_ID, $placer->id)->latest('id')->firstOrFail();
    }

    // ── پرسشِ ۱: کیست الیت‌یار، چه کسی تخفیف می‌گیرد، هولد ─────────────────────

    #[Test]
    public function a_saze90_licence_holder_with_only_the_elite_yar_role_gets_the_discounted_price_group(): void
    {
        $eliteYAR = $this->eliteYAR(licensed: true);

        $this->assertTrue($eliteYAR->isEliteYAR());
        $this->assertSame(User::ELITE_YAR_STATUS_VERIFIED, $eliteYAR->eliteYARStatus());
        $this->assertSame(PricingRoleResolver::WITH_ELITE_YAR, app(PricingRoleResolver::class)->resolve($eliteYAR));
    }

    #[Test]
    public function an_elite_yar_without_a_licence_is_on_hold_and_priced_without_the_discount(): void
    {
        $onHold = $this->eliteYAR(licensed: false);

        $this->assertSame(User::ELITE_YAR_STATUS_ON_HOLD, $onHold->eliteYARStatus());
        $this->assertSame(PricingRoleResolver::WITHOUT_ELITE_YAR, app(PricingRoleResolver::class)->resolve($onHold));

        Sanctum::actingAs($onHold);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.elite_yar_status', User::ELITE_YAR_STATUS_ON_HOLD)
            ->assertJsonPath('data.is_verified', false);
    }

    #[Test]
    public function a_legacy_engineer_is_still_an_elite_yar_and_is_on_hold_without_a_licence(): void
    {
        $legacy = User::factory()->create([User::IS_SAZE90_USER => false]);
        $legacy->addRole($this->role('engineer'));

        $this->assertTrue($legacy->isEliteYAR());
        $this->assertSame(User::ELITE_YAR_STATUS_ON_HOLD, $legacy->eliteYARStatus());
    }

    #[Test]
    public function a_client_with_a_verified_elite_yar_gets_the_discounted_group_and_a_plain_client_does_not(): void
    {
        $withEliteYAR = $this->employer();
        $this->link($withEliteYAR, $this->eliteYAR(licensed: true));

        $withOnHoldOnly = $this->employer();
        $this->link($withOnHoldOnly, $this->eliteYAR(licensed: false));

        $withPendingOnly = $this->employer();
        $this->link($withPendingOnly, $this->eliteYAR(licensed: true), status: 'pending');

        $resolver = app(PricingRoleResolver::class);
        $this->assertSame(PricingRoleResolver::WITH_ELITE_YAR, $resolver->resolve($withEliteYAR));
        $this->assertSame(PricingRoleResolver::WITHOUT_ELITE_YAR, $resolver->resolve($withOnHoldOnly));
        $this->assertSame(PricingRoleResolver::WITHOUT_ELITE_YAR, $resolver->resolve($withPendingOnly));
        $this->assertSame(PricingRoleResolver::WITHOUT_ELITE_YAR, $resolver->resolve($this->employer()));
    }

    // ── پرسشِ ۸: الیت‌یارِ سفارشِ کارفرما ─────────────────────────────────────

    #[Test]
    public function a_client_with_exactly_one_verified_elite_yar_gets_them_attached_to_the_order_automatically(): void
    {
        $employer = $this->employer();
        $eliteYAR = $this->eliteYAR(licensed: true);
        $this->link($employer, $eliteYAR);
        // یک الیت‌یارِ هولد و یک دعوتِ در انتظار هم دارد — هیچ‌کدام نباید شمرده شوند.
        $this->link($employer, $this->eliteYAR(licensed: false));
        $this->link($employer, $this->eliteYAR(licensed: true), status: 'pending');
        $this->fillCart($employer);

        $this->pay($employer)->assertOk();

        $this->assertSame($eliteYAR->id, (int) $this->lastOrderOf($employer)->{Order::ELITE_YAR_USER_ID});
    }

    #[Test]
    public function a_client_with_two_verified_elite_yars_must_pick_one(): void
    {
        $employer = $this->employer();
        $first = $this->eliteYAR(licensed: true);
        $second = $this->eliteYAR(licensed: true);
        $this->link($employer, $first);
        $this->link($employer, $second);
        $this->fillCart($employer);

        $this->pay($employer)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['elite_yar_user_id']);

        $this->assertDatabaseMissing('orders', [Order::PLACER_USER_ID => $employer->id]);

        $this->pay($employer, ['elite_yar_user_id' => $second->id])->assertOk();

        $this->assertSame($second->id, (int) $this->lastOrderOf($employer)->{Order::ELITE_YAR_USER_ID});
    }

    #[Test]
    public function a_client_without_any_verified_elite_yar_places_an_order_with_none(): void
    {
        $employer = $this->employer();
        $this->link($employer, $this->eliteYAR(licensed: false));
        $this->fillCart($employer);

        $this->pay($employer)->assertOk();

        $this->assertNull($this->lastOrderOf($employer)->{Order::ELITE_YAR_USER_ID});
    }

    // ── پرسشِ ۱ (قیدِ سوم): الیت‌یارِ هولد ───────────────────────────────────

    #[Test]
    public function an_on_hold_elite_yar_cannot_buy_for_an_employer_but_may_buy_for_themself(): void
    {
        $onHold = $this->eliteYAR(licensed: false);
        $employer = $this->employer();
        $this->link($employer, $onHold);

        $this->fillCart($onHold);
        $this->pay($onHold, ['beneficiary_user_id' => $employer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['beneficiary_user_id']);

        $this->pay($onHold)->assertOk();

        // بی‌الیت‌یار ثبت می‌شود: نه سهم، نه تخفیفِ الیت‌یاری.
        $order = $this->lastOrderOf($onHold);
        $this->assertNull($order->{Order::ELITE_YAR_USER_ID});
        $this->assertNull($order->{Order::BENEFICIARY_USER_ID});
    }

    #[Test]
    public function a_verified_elite_yar_buying_for_themself_is_recorded_as_the_orders_elite_yar(): void
    {
        $eliteYAR = $this->eliteYAR(licensed: true);
        $this->fillCart($eliteYAR);

        $this->pay($eliteYAR)->assertOk();

        $order = $this->lastOrderOf($eliteYAR);
        $this->assertSame($eliteYAR->id, (int) $order->{Order::ELITE_YAR_USER_ID});
        $this->assertSame($eliteYAR->id, (int) $order->{Order::PAYER_USER_ID});
        $this->assertNull($order->{Order::BENEFICIARY_USER_ID});
    }
}
