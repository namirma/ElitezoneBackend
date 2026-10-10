<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\SalesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سقفِ شارژِ کیف پول با درگاه کارت = ۲ میلیارد ریال (علی ۱۴۰۵/۰۷/۱۹)، از **یک** منبع:
 * `shaparak_value`ِ مؤثر (تنظیمِ ادمین، پیش‌فرض config/sales.php). قبلاً درخواستِ شارژ
 * `financial.deposit_max_shaparak` (۲۰۰ میلیون ریال، ده برابر کمتر) را اعمال می‌کرد.
 */
class ShaparakDepositCapTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::where(Setting::KEY, 'shaparak_value')->delete();
        Cache::forget(Setting::CACHE_KEY);
        Sanctum::actingAs(User::factory()->create());
    }

    private function deposit(int $amount)
    {
        return $this->postJson('/api/profile/financial/wallet/deposit', ['amount' => $amount, 'payment_method' => 'shaparak']);
    }

    #[Test]
    public function the_default_cap_is_two_billion_rials(): void
    {
        $this->assertSame(2_000_000_000.0, app(SalesSettings::class)->shaparakValue());

        // اعتبارسنجی رد می‌شود؛ ۵۰۳ = «درگاه هنوز وصل نیست»، یعنی مبلغ پذیرفته شد.
        $this->deposit(1_500_000_000)->assertStatus(503);
        $this->deposit(2_000_000_000)->assertStatus(503);
        $this->deposit(2_000_000_001)->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function the_cap_follows_the_admin_setting_and_the_public_settings_show_the_same_number(): void
    {
        Setting::updateOrCreate([Setting::KEY => 'shaparak_value'], [Setting::VALUE => '500000000']);
        Cache::forget(Setting::CACHE_KEY);

        $this->deposit(500_000_000)->assertStatus(503);
        $this->deposit(500_000_001)->assertStatus(422)->assertJsonValidationErrors('amount');

        $shaparak = collect($this->getJson('/api/settings/public')->assertOk()->json('data.wallet_deposit_methods'))
            ->firstWhere('key', 'shaparak');
        $this->assertSame(500_000_000, $shaparak['max']);
    }
}
