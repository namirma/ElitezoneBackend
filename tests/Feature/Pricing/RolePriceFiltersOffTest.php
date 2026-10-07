<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Models\PriceSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Pricing\Pipes\ApplyPriceFilters;
use App\Services\PricingRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * مدیریت، دورِ هفتم، پرسشِ ۷-۲ (۱۴۰۵/۰۷/۱۵): افزایشِ قیمتِ نقش‌محور هم «خاموش شود؛ قیمتِ فروش برای همه برابرِ
 * قیمتِ تأمین‌کننده». فیلترِ منفی از دورِ پنجم اعمال نمی‌شد؛ حالا هیچ فیلتری. ماژول پشتِ
 * `features.role_price_filters` (پیش‌فرض خاموش) می‌ماند — `ApplyPriceFiltersTest` آن را روشن می‌کند.
 */
class RolePriceFiltersOffTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function activeMarkup(float $amount, bool $isPercent): PriceFilterCategory
    {
        $category = PriceFilterCategory::create(['name' => 'دسته', 'code' => 'cat_'.Str::random(6), 'is_active' => true]);
        $type = PriceFilterType::create(['name' => 'نوع', 'code' => 'T_'.Str::random(6), 'apply_on' => 'general']);
        $filter = PriceFilter::create([
            'price_filter_type_id' => $type->id, 'name' => 'افزایش', 'code' => 'F_'.Str::random(6),
            'amount' => $amount, 'is_percentage' => $isPercent, 'is_active' => true,
        ]);
        $category->filters()->attach($filter->id);

        return $category;
    }

    #[Test]
    public function an_active_markup_no_longer_changes_the_price(): void
    {
        $category = $this->activeMarkup(5, true);
        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$category->id];

        app(ApplyPriceFilters::class)->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'قیمتِ فروش = قیمتِ تأمین‌کننده');
        $this->assertSame(1_200_000.0, $ctx->listPrice);
        $this->assertSame(1_000_000.0, $ctx->supplierPrice);
    }

    #[Test]
    public function the_buyer_discount_percent_is_still_reported(): void
    {
        // تخفیفِ خریدارِ دارای الیت‌یار فیلترِ قیمت نیست و نباید با خاموشیِ فیلترها از کارتِ محصول بیفتد.
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE], [PriceSetting::NAME => 'tc', PriceSetting::VALUE => '1']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '40']);
        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->filterCategoryCode = PricingRoleResolver::WITH_ELITE_YAR;

        app(ApplyPriceFilters::class)->handle($ctx, fn ($c) => $c);

        $this->assertEqualsWithDelta(0.4, $ctx->buyerDiscountPercent, 0.0001);
    }

    #[Test]
    public function the_panel_cannot_switch_a_filter_on(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        Sanctum::actingAs($this->grantAllAdminPermissions($admin));
        $type = PriceFilterType::create(['name' => 'نوع', 'code' => 'T_'.Str::random(6), 'apply_on' => 'general']);
        $payload = ['price_filter_type_id' => $type->id, 'name' => 'افزایش', 'amount' => 2, 'is_percentage' => true];

        // فعال — صریح یا با پیش‌فرضِ ستون — رد می‌شود.
        $this->postJson('/api/admin/price-filters', $payload + ['code' => 'A_'.Str::random(6)])
            ->assertStatus(422)->assertJsonPath('message', __('admin.filters.role_price_filters_off'));
        $this->postJson('/api/admin/price-filters', $payload + ['code' => 'B_'.Str::random(6), 'is_active' => true])->assertStatus(422);

        // غیرفعال ساخته می‌شود؛ روشن‌کردنش (toggle یا update) نه؛ خاموش‌ماندن و حذف آزاد است.
        $id = $this->postJson('/api/admin/price-filters', $payload + ['code' => 'C_'.Str::random(6), 'is_active' => false])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/price-filters/{$id}/toggle")->assertStatus(422);
        $this->putJson("/api/admin/price-filters/{$id}", ['is_active' => true])->assertStatus(422);
        $this->putJson("/api/admin/price-filters/{$id}", ['name' => 'نامِ تازه'])->assertOk();
        $this->assertFalse((bool) PriceFilter::findOrFail($id)->is_active);
        $this->deleteJson("/api/admin/price-filters/{$id}")->assertOk();
    }

    #[Test]
    public function an_already_active_filter_can_still_be_switched_off(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']));
        Sanctum::actingAs($this->grantAllAdminPermissions($admin));
        $filter = $this->activeMarkup(1000, false)->filters()->firstOrFail();

        $this->postJson("/api/admin/price-filters/{$filter->id}/toggle")->assertOk();
        $this->assertFalse((bool) $filter->fresh()->is_active);
    }
}
