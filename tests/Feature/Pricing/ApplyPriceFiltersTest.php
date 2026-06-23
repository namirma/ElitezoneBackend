<?php

namespace Tests\Feature\Pricing;

use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Services\Pricing\Pipes\ApplyPriceFilters;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
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
        $this->pipe = new ApplyPriceFilters;
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

    /** @test */
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

    /** @test */
    public function general_percentage_filter_adjusts_both_list_and_payable(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('general');
        $this->makeFilter($cat, $type, amount: 10, isPercent: true);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        // +10% → both prices increase by 10%
        $this->assertSame(1_100_000.0, $ctx->payablePrice, '1,000,000 + 10% = 1,100,000');
        $this->assertSame(1_320_000.0, $ctx->listPrice, '1,200,000 + 10% = 1,320,000');
    }

    /** @test */
    public function special_filter_adjusts_only_payable_price(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');
        $this->makeFilter($cat, $type, amount: -50_000, isPercent: false);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(950_000.0, $ctx->payablePrice, 'Special filter discounted payable by 50k');
        $this->assertSame(1_200_000.0, $ctx->listPrice, 'List price untouched by special filter');
    }

    /** @test */
    public function both_filter_applies_net_delta_to_payable(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('both');
        $this->makeFilter($cat, $type, amount: 30_000, isPercent: false);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_030_000.0, $ctx->payablePrice, 'Both-side adjustment +30k applied');
    }

    /** @test */
    public function payable_price_is_clamped_to_zero(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');
        $this->makeFilter($cat, $type, amount: -50_000_000, isPercent: false);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(0.0, $ctx->payablePrice, 'Never goes negative');
    }

    /** @test */
    public function filters_are_applied_in_sort_order(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');

        // Apply +20% first (sort_order=1), then -100k (sort_order=2)
        $this->makeFilter($cat, $type, amount: 20, isPercent: true, sortOrder: 1);
        $this->makeFilter($cat, $type, amount: -100_000, isPercent: false, sortOrder: 2);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->listPrice = 1_200_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        // 1,000,000 × 1.20 = 1,200,000 then −100,000 = 1,100,000
        $this->assertSame(1_100_000.0, $ctx->payablePrice);
    }

    /** @test */
    public function inactive_filters_are_skipped(): void
    {
        $cat = $this->makeFilterCategory();
        $type = $this->makeFilterType('special');

        $filter = $this->makeFilter($cat, $type, amount: -200_000, isPercent: false);
        $filter->update(['is_active' => false]);

        $ctx = $this->makeContext();
        $ctx->payablePrice = 1_000_000;
        $ctx->filterCategoryIds = [$cat->id];

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'Inactive filter has no effect');
    }
}
