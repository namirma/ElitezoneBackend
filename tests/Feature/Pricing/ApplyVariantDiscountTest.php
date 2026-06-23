<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\Pipes\ApplyVariantDiscount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Variant-level discount is read from the ProductVariant's
 * discount_type / discount_value / discount_start_date / discount_end_date.
 * Supports both 'percentage' and 'fixed_amount' types; never produces a
 * negative payable price.
 */
class ApplyVariantDiscountTest extends TestCase
{
    use CreatesPricingFixtures;
    use DatabaseTransactions;

    private ApplyVariantDiscount $pipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipe = new ApplyVariantDiscount;
    }

    /** @test */
    public function it_does_nothing_when_no_discount_set(): void
    {
        $variant = $this->makeVariant();   // no discount fields
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice);
        $this->assertSame(0.0, $ctx->variantDiscountAmount);
    }

    /** @test */
    public function it_applies_percentage_discount(): void
    {
        $variant = $this->makeVariant(null, [
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(100_000.0, $ctx->variantDiscountAmount, '10% of 1,000,000 = 100,000');
        $this->assertSame(900_000.0, $ctx->payablePrice);
        $this->assertSame('percentage', $ctx->variantDiscountType);
    }

    /** @test */
    public function it_applies_fixed_amount_discount(): void
    {
        $variant = $this->makeVariant(null, [
            'discount_type' => 'fixed_amount',
            'discount_value' => 50_000,
        ]);
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(50_000.0, $ctx->variantDiscountAmount);
        $this->assertSame(950_000.0, $ctx->payablePrice);
        $this->assertSame('fixed_amount', $ctx->variantDiscountType);
    }

    /** @test */
    public function it_clamps_payable_price_to_zero_when_discount_exceeds_price(): void
    {
        $variant = $this->makeVariant(null, [
            'discount_type' => 'fixed_amount',
            'discount_value' => 5_000_000,
        ]);
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(0.0, $ctx->payablePrice, 'Price never goes negative');
    }

    /** @test */
    public function it_ignores_discount_before_start_date(): void
    {
        $variant = $this->makeVariant(null, [
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'discount_start_date' => now()->addDay(),
        ]);
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'Discount not yet active');
        $this->assertSame(0.0, $ctx->variantDiscountAmount);
    }

    /** @test */
    public function it_ignores_discount_after_end_date(): void
    {
        $variant = $this->makeVariant(null, [
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'discount_end_date' => now()->subDay(),
        ]);
        $ctx = $this->makeContext($variant);
        $ctx->payablePrice = 1_000_000;

        $this->pipe->handle($ctx, fn ($c) => $c);

        $this->assertSame(1_000_000.0, $ctx->payablePrice, 'Discount has expired');
        $this->assertSame(0.0, $ctx->variantDiscountAmount);
    }
}
