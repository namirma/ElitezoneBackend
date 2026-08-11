<?php

namespace Tests\Feature\Supplier;

use App\Models\Cart;
use App\Models\Setting;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Cart\CartParkService;
use App\Services\Cart\CartPriceValidityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اعتبارِ قیمتِ تأمین‌کننده (`reserve_time`) — بند ۲.۲، ۳.۱، ۳.۲ و گاردهای بند ۹.
 *
 * این یک عددِ نمایشی نیست: مهلتِ سبدِ خریداران، دیده‌شدنِ محصول در لیست عمومی و
 * اجازه‌ی افزودن به سبد هر سه از همین ستون تغذیه می‌شوند.
 */
class SupplierReserveTimeTest extends TestCase
{
    use DatabaseTransactions;

    private function approvedSupplier(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'supplier'], ['display_name' => 'تأمین‌کننده']));

        SupplierProfile::create([SupplierProfile::USER_ID => $user->id])
            ->forceFill([SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED])
            ->save();

        return $user;
    }

    private function profileOf(User $user): SupplierProfile
    {
        return SupplierProfile::where(SupplierProfile::USER_ID, $user->id)->firstOrFail();
    }

    // ── گاردهای بند ۹ ───────────────────────────────────────────────────────

    #[Test]
    public function a_past_reserve_time_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedSupplier());

        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('reserve_time');
    }

    #[Test]
    public function reserve_time_can_be_extended_but_never_shortened(): void
    {
        // کوتاه‌کردن ممنوع است چون خریدارانِ دارای سبدِ منتظر مهلتشان را از همین
        // عدد گرفته‌اند؛ جلو کشیدنش سبدِ آن‌ها را زودتر از موعد منقضی می‌کند.
        $supplier = $this->approvedSupplier();
        Sanctum::actingAs($supplier);

        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->addDays(5)->toIso8601String(),
        ])->assertOk();

        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->addDays(10)->toIso8601String(),
        ])->assertOk();

        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->addDays(6)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('reserve_time');

        $this->assertTrue(
            $this->profileOf($supplier)->{SupplierProfile::RESERVE_TIME}->isAfter(now()->addDays(9)),
            'مقدار باید روی همان ۱۰ روز بماند.',
        );
    }

    #[Test]
    public function a_non_supplier_cannot_set_a_reserve_time(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();
    }

    // ── پنجره‌ی اعتبار ──────────────────────────────────────────────────────

    #[Test]
    public function the_price_window_is_invalid_when_null_or_past(): void
    {
        $supplier = $this->approvedSupplier();
        $profile = $this->profileOf($supplier);

        $this->assertFalse($profile->hasValidPriceWindow(), 'null یعنی هنوز اعلام نشده.');

        $profile->update([SupplierProfile::RESERVE_TIME => now()->subMinute()]);
        $this->assertFalse($profile->fresh()->hasValidPriceWindow(), 'گذشته یعنی منقضی.');

        $profile->update([SupplierProfile::RESERVE_TIME => now()->addDay()]);
        $this->assertTrue($profile->fresh()->hasValidPriceWindow());
    }

    #[Test]
    public function the_cart_price_validity_comes_from_the_suppliers_reserve_time(): void
    {
        // بند ۱۷.۲: این سرویس از قبل به‌عنوان همین نقطه‌ی اتصال نوشته شده بود.
        $supplier = $this->approvedSupplier();
        $deadline = now()->addDays(3)->startOfSecond();
        $this->profileOf($supplier)->update([SupplierProfile::RESERVE_TIME => $deadline]);

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplier->id])->save();

        $resolved = app(CartPriceValidityResolver::class)->resolve($cart->fresh());

        $this->assertNotNull($resolved);
        $this->assertSame($deadline->toIso8601String(), $resolved->toIso8601String());
    }

    // ── قاعده‌ی نزدیکی به reserve_time (بند ۳.۱) ────────────────────────────

    #[Test]
    public function a_cart_parked_near_the_price_deadline_waits_until_after_it(): void
    {
        // کاربر باید فرصتِ کاملِ park_time را روی قیمتِ *جدید* داشته باشد، نه
        // مهلتی که وسطش قیمت عوض می‌شود.
        Setting::updateOrCreate([Setting::KEY => 'park_time_minutes'], [Setting::VALUE => '120']);
        Cache::forget(Setting::CACHE_KEY);

        $supplier = $this->approvedSupplier();
        $reserveTime = now()->addMinutes(30)->startOfSecond();
        $this->profileOf($supplier)->update([SupplierProfile::RESERVE_TIME => $reserveTime]);

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplier->id])->save();

        app(CartParkService::class)->parkIfNeeded($cart->fresh());

        // انتظار: reserve_time + 120 دقیقه، نه now + 120.
        $this->assertEqualsWithDelta(
            $reserveTime->copy()->addMinutes(120)->timestamp,
            $cart->fresh()->{Cart::PARKED_UNTIL}->timestamp,
            60,
        );
    }

    #[Test]
    public function a_cart_parked_far_from_the_price_deadline_uses_the_plain_window(): void
    {
        Setting::updateOrCreate([Setting::KEY => 'park_time_minutes'], [Setting::VALUE => '120']);
        Cache::forget(Setting::CACHE_KEY);

        $supplier = $this->approvedSupplier();
        $this->profileOf($supplier)->update([SupplierProfile::RESERVE_TIME => now()->addDays(3)]);

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([Cart::SUPPLIER_USER_ID => $supplier->id])->save();

        app(CartParkService::class)->parkIfNeeded($cart->fresh());

        $this->assertEqualsWithDelta(
            now()->addMinutes(120)->timestamp,
            $cart->fresh()->{Cart::PARKED_UNTIL}->timestamp,
            60,
        );
    }

    // ── سبدِ منتظر (بند ۳.۲) ────────────────────────────────────────────────

    #[Test]
    public function a_cart_waiting_for_new_prices_is_not_expired_by_the_cron(): void
    {
        // «سبدهای منتظر تا لحظه‌ی اعلام قیمت جدید زنده می‌مانند» — منقضی‌کردنشان
        // خریدار را بابت تأخیرِ تأمین‌کننده جریمه می‌کند.
        $supplier = $this->approvedSupplier();
        $this->profileOf($supplier)->update([SupplierProfile::RESERVE_TIME => now()->subHour()]);

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $supplier->id,
            Cart::PARKED_UNTIL => now()->subMinute(),
        ])->save();

        $this->artisan('carts:expire-parked')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id), 'سبدِ منتظر نباید حذف شود.');
    }

    #[Test]
    public function announcing_new_prices_reparks_the_waiting_carts(): void
    {
        Setting::updateOrCreate([Setting::KEY => 'park_time_minutes'], [Setting::VALUE => '120']);
        Cache::forget(Setting::CACHE_KEY);

        $supplier = $this->approvedSupplier();
        $this->profileOf($supplier)->update([SupplierProfile::RESERVE_TIME => now()->subHour()]);

        $cart = Cart::create([Cart::USER_ID => User::factory()->create()->id]);
        $cart->forceFill([
            Cart::SUPPLIER_USER_ID => $supplier->id,
            Cart::PARKED_UNTIL => now()->subMinute(),
            Cart::PARK_WARNED_AT => now()->subMinutes(30),
        ])->save();

        Sanctum::actingAs($supplier);
        $this->putJson('/api/profile/supplier/reserve-time', [
            'reserve_time' => now()->addDays(2)->toIso8601String(),
        ])->assertOk();

        $fresh = $cart->fresh();
        $this->assertTrue($fresh->{Cart::PARKED_UNTIL}->isFuture(), 'سبد باید مهلتِ تازه بگیرد.');
        $this->assertNull($fresh->{Cart::PARK_WARNED_AT}, 'هشدارِ قبلی باید پاک شود تا دوباره ارسال شود.');
    }
}
