<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\PriceSetting;
use App\Models\User;
use App\Services\Financial\SettlementCalculatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * OW «تخفیف ویژه‌ی خریدار» است، نه «سهم پلتفرم».
 *
 * چارتِ «پروسه خرید» مدیریت (نسخه‌ی ۲۰۲۶-۰۹-۲۰، گره‌ی ۷۹۶): «%OW = تخفیف ویژه خریدار»
 * و «%SSG = کارمزد بک آفیس». موتور هم همین را اجرا می‌کند: POO = مبنا × (۱ − %OW)،
 * یعنی OW همان بخشی از کارمزد است که خریدار **نمی‌پردازد**؛ سهمِ پلتفرم SSG است
 * (۱۰۰ − OW − CO).
 *
 * صفحه‌ی «پارامترهای مالی» (۲۰۲۶-۰۹-۱۶) OW را «سهم پلتفرم» می‌نامید و می‌گفت «همین
 * درصد است که واقعاً از خریدار گرفته می‌شود». مدیری که برای درآمدِ بیشتر آن را بالا
 * می‌برد، در واقع تخفیفِ خریدار را بیشتر و سهمِ سازه را کمتر می‌کرد.
 */
class PriceSettingOwMeaningTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);
    }

    #[Test]
    public function raising_ow_lowers_what_the_buyer_pays_and_shrinks_the_platform_share(): void
    {
        // معنای پولیِ OW — همان چیزی که برچسب باید توصیف کند.
        $calc = new SettlementCalculatorService;
        $at40 = $calc->calculatePOO(0, 1_000_000, 100, 0.4, 10);   // ۴۰٪ از ۱٪
        $at50 = $calc->calculatePOO(0, 1_000_000, 100, 0.5, 10);   // ۵۰٪ از ۱٪
        $this->assertLessThan($at40, $at50);

        PriceSetting::updateOrCreate(
            [PriceSetting::CODE => PriceSetting::CODE_OW_SHARE],
            [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '50'],
        );
        $this->assertSame(25.0, (new SettlementCalculatorService)->getCommissionRates('default')['ssg_percent_of_tc']);
    }

    #[Test]
    public function the_ow_parameter_is_labelled_as_the_buyers_discount(): void
    {
        $ow = collect($this->getJson('/api/admin/price-settings')->assertOk()->json('data.settings'))
            ->firstWhere('key', PriceSetting::CODE_OW_SHARE);

        $this->assertStringContainsString('تخفیف', $ow['label']);
        $this->assertStringContainsString('خریدار', $ow['label']);
        $this->assertStringNotContainsString('سهم پلتفرم', $ow['label']);
        $this->assertStringNotContainsString('از خریدار گرفته می‌شود', $ow['description']);
    }

    #[Test]
    public function no_parameter_describes_ow_as_the_platform_share(): void
    {
        foreach ($this->getJson('/api/admin/price-settings')->assertOk()->json('data.settings') as $row) {
            $this->assertStringNotContainsString('سهم پلتفرم', $row['label'].' '.$row['description'], $row['key']);
        }
    }

    #[Test]
    public function the_hundred_percent_guard_names_the_buyers_discount(): void
    {
        $res = $this->putJson('/api/admin/price-settings', ['settings' => [
            PriceSetting::CODE_OW_SHARE => 70,
            PriceSetting::CODE_CO_SHARE_GOLD => 40,
        ]])->assertStatus(422);

        $message = implode(' ', $res->json('errors')['settings.'.PriceSetting::CODE_CO_SHARE_GOLD] ?? []);
        $this->assertStringContainsString('تخفیف خریدار', $message);
        $this->assertStringNotContainsString('سهم پلتفرم', $message);
    }
}
