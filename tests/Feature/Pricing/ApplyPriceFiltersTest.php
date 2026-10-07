<?php

namespace Tests\Feature\Pricing;

use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Models\PriceSetting;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\Pricing\Pipes\ApplyPriceFilters;
use App\Services\Pricing\PricingContext;
use App\Services\PricingRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApplyPriceFilters applies a chain of price adjustments grouped by their
 * filterType.apply_on column:
 *   - 'general' : adjusts both listPrice and payablePrice
 *   - 'special' : adjusts only payablePrice (e.g. engineer discount)
 *   - 'both'    : applied as a delta on payablePrice
 * Each filter can be a percentage or a fixed amount.
 */
class ApplyPriceFiltersTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private ApplyPriceFilters $pipe;

    protected function setUp(): void
    {
        parent::setUp();
        // ماژولِ فیلتر را می‌سنجد؛ از دورِ هفتم (پرسشِ ۷-۲) پشتِ پرچمِ خاموش است — `RolePriceFiltersOffTest`.
        config(['features.role_price_filters' => true]);
        $this->pipe = app(ApplyPriceFilters::class);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_TC_PERCENTAGE], [PriceSetting::NAME => 'tc', PriceSetting::VALUE => '1']);
        PriceSetting::updateOrCreate([PriceSetting::CODE => PriceSetting::CODE_OW_SHARE], [PriceSetting::NAME => 'ow', PriceSetting::VALUE => '40']);
    }

    /**
     * کفِ قراردادی (پرسشِ ۶ مدیریت) نسبت به کارمزدِ تأمین‌کننده است. تست‌های حسابِ
     * فیلتر با تخفیف‌های ۵٪ و ۱۰٪ نوشته شده‌اند، پس به تأمین‌کننده‌ای با کارمزدِ
     * قراردادیِ ۲۰٪ وصل می‌شوند تا کف (۸۰٪ ÷ ۹۲٪ ≈ ۸۷٪) زیرِ آن‌ها بماند و فقط
     * **حسابِ فیلتر** سنجیده شود؛ کف تستِ خودش را دارد.
     */
    private function makeContextWithContractCommission(float $percent = 20.0): PricingContext
    {
        $ctx = $this->makeContext();
        $supplier = User::factory()->create();
        SupplierProfile::create([SupplierProfile::USER_ID => $supplier->id])
            ->forceFill([SupplierProfile::COMMISSION_PERCENT => $percent])->save();
        $ctx->factory->forceFill(['supplier_user_id' => $supplier->id])->save();

        return $ctx;
    }

    private function makeFilterType(string $applyOn): PriceFilterType
    {
        return PriceFilterType::create([
            'name' => "Type-{$applyOn}",
            'code' => 'TYPE_'.strtoupper($applyOn).'_'.Str::random(4),
            'apply_on' => $applyOn,
        ]);
    }

    private function makeFilterCategory(string $code = 'cat'): PriceFilterCategory
    {
        return PriceFilterCategory::create([
            'name' => 'دسته فیلتر تست',
            'code' => $code.'_'.Str::random(6),
            'is_active' => true,
        ]);
    }

    private function makeFilter(
        PriceFilterCategory $category,
        PriceFilterType $type,
        float $amount,
        bool $isPercent,
        int $sortOrder = 0,
    ): PriceFilter {
        // PriceFilter ↔ PriceFilterCategory is many-to-many through
        // price_filter_category_items pivot, NOT a direct FK column.
        $filter = PriceFilter::create([
            'price_filter_type_id' => $type->id,
            'name' => 'فیلتر تست',
            'code' => 'FILT_'.Str::random(6),
            'amount' => $amount,
            'is_percentage' => $isPercent,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        $category->filters()->attach($filter->id);

        return $filter;
    }

    #[Test]
    public function it_does_nothing_when_no_filters_attached(): void
    {
        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice);
        $this->assertSame(1_200_000.0, $ctx->listPrice);
    }

    #[Test]
    public function general_percentage_filter_adjusts_both_list_and_payable(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('general');
        $this->makeFilter($cat, $type, amount: 10, isPercent: true);

        $ctx = $this->makeContextWithContractCommission();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        // +10% → both prices increase by 10%
        $this->assertSame(1_100_000.0, $ctx->payablePrice, '1,000,000 + 10% = 1,100,000');
        $this->assertSame(1_320_000.0, $ctx->listPrice, '1,200,000 + 10% = 1,320,000');
    }

    #[Test]
    public function special_filter_adjusts_only_payable_price(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');
        $this->makeFilter($cat, $type, amount: 50_000, isPercent: false);

        $ctx = $this->makeContextWithContractCommission();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_050_000.0, $ctx->payablePrice, 'Special filter raised payable by 50k');
        $this->assertSame(1_200_000.0, $ctx->listPrice, 'List price untouched by special filter');
    }

    #[Test]
    public function both_filter_applies_net_delta_to_payable(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('both');
        $this->makeFilter($cat, $type, amount: 30_000, isPercent: false);

        $ctx = $this->makeContextWithContractCommission();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_030_000.0, $ctx->payablePrice, 'Both-side adjustment +30k applied');
    }

    /**
     * مدیریت، دورِ پنجم، پرسش‌های ۲ تا ۴ (۱۴۰۵/۰۷/۱۴): «قیمتِ فروش هرگز کمتر از قیمتِ اعلامیِ
     * تأمین‌کننده منهای تخفیفِ خریدارِ دارای الیت‌یار نیست» و جز تخفیفِ الیت‌یاری و کوپن «هیچ
     * تخفیفِ دیگری در سیستم وجود ندارد». فیلترِ قیمتِ منفی (تخفیفِ نقش‌محور) نادیده گرفته می‌شود.
     */
    #[Test]
    public function a_negative_role_filter_is_ignored_so_the_price_never_drops_below_the_supplier_price(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');
        $this->makeFilter($cat, $type, amount: -50_000_000, isPercent: false);
        $this->makeFilter($cat, $type, amount: -30, isPercent: true, sortOrder: 2);

        $ctx = $this->makeContextWithContractCommission(20.0);
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'تخفیفِ نقش‌محور اعمال نمی‌شود');
        $this->assertSame(1_200_000.0, $ctx->listPrice);
        $this->assertSame(1_000_000.0, $ctx->supplierPrice);
    }

    /**
     * کارتِ محصول (پرسشِ ۱): درصدِ «تخفیف» فقط برای گروهِ «دارای الیت‌یار» — %TCِ قراردادِ همین
     * تأمین‌کننده × سهمِ OW (۴۰٪). قیمت خودش دست نمی‌خورد؛ تخفیف سطرِ جدای سفارش است.
     */
    #[Test]
    public function the_buyer_discount_percent_is_exposed_only_for_the_with_elite_yar_group(): void
    {
        $ctx = $this->makeContextWithContractCommission(20.0);
        $ctx->payablePrice = 1_000_000;
        $ctx->filterCategoryCode = PricingRoleResolver::WITH_ELITE_YAR;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertEqualsWithDelta(8.0, $ctx->buyerDiscountPercent, 0.0001, '۲۰٪ × ۴۰٪');
        $this->assertSame(1_000_000.0, $ctx->payablePrice);

        $without = $this->makeContextWithContractCommission(20.0);
        $without->payablePrice = 1_000_000;
        $without->filterCategoryCode = PricingRoleResolver::WITHOUT_ELITE_YAR;

        $this->pipe->handle($without, fn ($c) => $c);

        $this->assertSame(0.0, $without->buyerDiscountPercent);
    }

    #[Test]
    public function a_markup_above_the_supplier_price_is_never_touched_by_the_floor(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');
        $this->makeFilter($cat, $type, amount: 25_000, isPercent: false);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_025_000.0, $ctx->payablePrice);
        $this->assertFalse($ctx->priceFloorApplied);
        $this->assertSame(1_000_000.0, $ctx->supplierPrice);
    }

    #[Test]
    public function filters_are_applied_in_sort_order(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');

        // Apply +20% first (sort_order=1), then +100k (sort_order=2)
        $this->makeFilter($cat, $type, amount: 20, isPercent: true, sortOrder: 1);
        $this->makeFilter($cat, $type, amount: 100_000, isPercent: false, sortOrder: 2);

        $ctx = $this->makeContextWithContractCommission();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        // 1,000,000 × 1.20 = 1,200,000 then +100,000 = 1,300,000 (the reverse order would give 1,320,000)
        $this->assertSame(1_300_000.0, $ctx->payablePrice);
    }

    #[Test]
    public function inactive_filters_are_skipped(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');

        $filter = $this->makeFilter($cat, $type, amount: -200_000, isPercent: false);
        $filter->update(['is_active' => false]);

        $ctx = $this->makeContextWithContractCommission();
        $ctx->payablePrice = 1_000_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'Inactive filter has no effect');
    }
}
