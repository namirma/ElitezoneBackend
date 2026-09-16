<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\PriceSetting;
use App\Models\User;
use App\Services\Financial\SettlementCalculatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laratrust\Models\Permission;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پارامترهای مالی از پنل (قانون طلایی: چیزی که سایت می‌خواند باید از پنل مدیریت شود)
 * و ریشه‌ی باگِ کش: `PriceSetting::getValue` پیش‌فرضِ اولین فراخوان را ۲۴ ساعت
 * به همه می‌داد.
 */
class AdminPriceSettingsTest extends TestCase
{
    use DatabaseTransactions, GrantsAdminPermissions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create();
        $this->admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($this->admin);
        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function get_value_never_caches_the_callers_default(): void
    {
        PriceSetting::where(PriceSetting::CODE, PriceSetting::CODE_TC_PERCENTAGE)->delete();

        $this->assertSame(0, PriceSetting::getValue(PriceSetting::CODE_TC_PERCENTAGE, 0));
        $this->assertSame(1.0, PriceSetting::getValueAsFloat(PriceSetting::CODE_TC_PERCENTAGE, 1.0));

        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE], [PriceSetting::NAME => 'tc', PriceSetting::VALUE => '2']);
        $this->assertSame(2.0, PriceSetting::getValueAsFloat(PriceSetting::CODE_TC_PERCENTAGE, 1.0));
    }

    #[Test]
    public function index_lists_every_catalog_parameter_with_metadata(): void
    {
        $res = $this->getJson('/api/admin/price-settings')->assertOk();
        $rows = collect($res->json('data.settings'))->keyBy('key');

        $this->assertSame(array_keys(PriceSetting::catalog()), $rows->keys()->all());
        $tc = $rows->get(PriceSetting::CODE_TC_PERCENTAGE);
        $this->assertSame('1', $tc['default']);
        $this->assertSame('percent', $tc['unit']);
        $this->assertEquals(20, $tc['max']);
        foreach (['label', 'description', 'value', 'updated_at', 'updated_by'] as $k) {
            $this->assertArrayHasKey($k, $tc);
        }
    }

    #[Test]
    public function update_writes_logs_invalidates_cache_and_the_calculator_reads_it(): void
    {
        $calc = new SettlementCalculatorService;
        $this->assertSame(40.0, $calc->getCommissionRates('gold')['ow_percent_of_tc']);

        $this->putJson('/api/admin/price-settings', ['settings' => [
            PriceSetting::CODE_TC_PERCENTAGE => 2,
            PriceSetting::CODE_OW_SHARE => 50,
            PriceSetting::CODE_CO_SHARE_GOLD => 30,
        ]])->assertOk();

        $this->assertSame(2.0, $calc->currentTcPercentage());
        $rates = $calc->getCommissionRates('gold');
        $this->assertSame(50.0, $rates['ow_percent_of_tc']);
        $this->assertSame(30.0, $rates['co_percent_of_tc']);
        $this->assertSame(20.0, $rates['ssg_percent_of_tc']);
        $this->assertSame(1.0, $rates['ow_final_rate']);

        $row = PriceSetting::where(PriceSetting::CODE, PriceSetting::CODE_TC_PERCENTAGE)->first();
        $this->assertSame($this->admin->id, $row->{PriceSetting::UPDATED_BY});

        $log = Activity::query()->latest('id')->first();
        $this->assertSame('پارامترهای مالی بروزرسانی شد.', $log->description);
        $this->assertSame('2', $log->properties['changes'][PriceSetting::CODE_TC_PERCENTAGE]['after']);
    }

    #[Test]
    public function update_rejects_out_of_range_unknown_and_inconsistent_values(): void
    {
        $this->putJson('/api/admin/price-settings', ['settings' => [PriceSetting::CODE_VAT_RATE => 31]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.vat_rate']);
        $this->putJson('/api/admin/price-settings', ['settings' => [PriceSetting::CODE_TC_PERCENTAGE => -1]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.tc_percentage']);
        $this->putJson('/api/admin/price-settings', ['settings' => ['nope' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.nope']);
        $this->putJson('/api/admin/price-settings', ['settings' => [PriceSetting::CODE_OW_SHARE => 70, PriceSetting::CODE_CO_SHARE_GOLD => 37.5]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.co_share_gold']);

        $this->assertSame(1.0, (new SettlementCalculatorService)->currentTcPercentage());
    }

    #[Test]
    public function content_only_admin_gets_403(): void
    {
        $content = User::factory()->create();
        $content->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $content->syncPermissions([Permission::firstOrCreate(['name' => 'manage-content'], ['display_name' => 'manage-content'])->id]);
        $this->setAdminLevel($content, User::ADMIN_LEVEL_OWNER);
        Sanctum::actingAs($content);

        $this->getJson('/api/admin/price-settings')->assertStatus(403);
        $this->putJson('/api/admin/price-settings', ['settings' => [PriceSetting::CODE_TC_PERCENTAGE => 2]])->assertStatus(403);
    }
}
