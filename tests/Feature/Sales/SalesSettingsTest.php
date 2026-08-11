<?php

namespace Tests\Feature\Sales;

use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\SalesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پارامترهای مسیر فروش — بند ۲.۱ سند بازطراحی.
 *
 * نکتهٔ اصلیِ این کلاس clamp در لحظهٔ خواندن است: اعتبارسنجیِ فرم به‌تنهایی کافی
 * نیست، چون ترتیبِ ویرایشِ ادمین می‌تواند یک `reserve_value`ِ معتبرِ دیروز را به
 * مقداری بزرگ‌تر از سقفِ امروز تبدیل کند.
 */
class SalesSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private function settings(): SalesSettings
    {
        Cache::forget(Setting::CACHE_KEY);

        return app(SalesSettings::class);
    }

    private function storeSetting(string $key, string $value): void
    {
        Setting::updateOrCreate([Setting::KEY => $key], [Setting::VALUE => $value]);
    }

    #[Test]
    public function it_falls_back_to_config_when_the_key_is_absent(): void
    {
        $this->assertSame(
            (float) config('sales.shaparak_value'),
            $this->settings()->shaparakValue(),
        );
        $this->assertSame(120, (int) $this->settings()->parkTime()->totalMinutes);
    }

    #[Test]
    public function a_stored_setting_overrides_the_config_default(): void
    {
        $this->storeSetting('park_time_minutes', '45');

        $this->assertSame(45, (int) $this->settings()->parkTime()->totalMinutes);
    }

    #[Test]
    public function an_empty_stored_value_falls_back_instead_of_becoming_zero(): void
    {
        // ادمین فیلد را خالی می‌گذارد؛ «پاک کردم» یعنی پیش‌فرض، نه صفر.
        // بدون این، park_time = 0 و هر سبد بلافاصله منقضی می‌شد.
        $this->storeSetting('park_time_minutes', '');

        $this->assertSame(120, (int) $this->settings()->parkTime()->totalMinutes);
    }

    #[Test]
    public function reserve_value_is_clamped_to_shaparak_value_at_read_time(): void
    {
        $this->storeSetting('shaparak_value', '2000000000');
        $this->storeSetting('reserve_value', '2000000000');

        $this->assertSame(2_000_000_000.0, $this->settings()->reserveValue());

        // ادمین فقط سقف را پایین می‌آورد و بیعانهٔ قدیمی در جدول دست‌نخورده می‌ماند.
        $this->storeSetting('shaparak_value', '500000000');

        $this->assertSame(500_000_000.0, $this->settings()->reserveValue());
    }

    #[Test]
    public function coupon_visibility_all_and_none_ignore_the_user(): void
    {
        $user = User::factory()->create();

        $this->storeSetting('coupon_visibility', 'all');
        $this->assertTrue($this->settings()->couponVisibleFor($user));
        $this->assertTrue($this->settings()->couponVisibleFor(null));

        $this->storeSetting('coupon_visibility', 'none');
        $this->assertFalse($this->settings()->couponVisibleFor($user));
        $this->assertFalse($this->settings()->couponVisibleFor(null));
    }

    #[Test]
    public function coupon_visibility_selected_reads_the_user_flag(): void
    {
        $this->storeSetting('coupon_visibility', 'selected');

        // `coupon_enabled` عمداً در `$fillable` نیست — مثل `status` و `trans_*`
        // فقط بک‌آفیس می‌نویسدش، پس مسیرِ نوشتنش forceFill است نه mass-assign.
        $enabled = tap(User::factory()->create(), fn (User $u) => $u->forceFill([User::COUPON_ENABLED => true])->save());
        $disabled = tap(User::factory()->create(), fn (User $u) => $u->forceFill([User::COUPON_ENABLED => false])->save());
        $undecided = User::factory()->create();

        $this->assertTrue($this->settings()->couponVisibleFor($enabled));
        $this->assertFalse($this->settings()->couponVisibleFor($disabled));
        $this->assertFalse($this->settings()->couponVisibleFor($undecided));
        $this->assertFalse($this->settings()->couponVisibleFor(null));
    }

    #[Test]
    public function the_six_keys_are_writable_by_admin_but_never_public(): void
    {
        $allowed = config('settings.allowed_keys');
        $public = config('settings.public_keys');

        foreach (array_keys(SalesSettings::validationRules()) as $key) {
            $this->assertContains($key, $allowed, "کلید «{$key}» باید قابل ویرایش ادمین باشد.");
            $this->assertNotContains($key, $public, "کلید «{$key}» داخلی است و نباید عمومی شود.");
        }
    }
}
