<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\Pipes\ApplyVat;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * VAT is applied as a percentage of payable_price (read from price_settings).
 * Only runs when context->withVat is true.
 */
class ApplyVatTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPricingFixtures;

    private ApplyVat $pipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipe = new ApplyVat();
    }

    /** @test */
    public function it_does_nothing_when_withVat_is_false(): void
    {
        $ctx = $this->makeContext();
        $ctx->withVat = false;
        $ctx->payablePrice = 1_000_000;

        $next = fn ($c) => $c;
        $this->pipe->handle($ctx, $next);

        $this->assertSame(0.0, $ctx->vatAmount, 'No VAT applied when withVat=false');
    }

    /** @test */
    public function it_applies_default_vat_rate_when_setting_missing(): void
    {
        $ctx = $this->makeContext();
        $ctx->withVat = true;
        $ctx->payablePrice = 1_000_000;

        $next = fn ($c) => $c;
        $this->pipe->handle($ctx, $next);

        // PriceSetting::getValueAsFloat falls back to 10.0 if missing
        $this->assertGreaterThan(0.0, $ctx->vatAmount, 'VAT should be calculated');
        $this->assertSame(10.0, $ctx->vatRate);
    }

    /** @test */
    public function it_calculates_vat_as_percentage_of_payable_price(): void
    {
        $this->setVatRate(9.0);

        $ctx = $this->makeContext();
        $ctx->withVat = true;
        $ctx->payablePrice = 1_000_000;

        $next = fn ($c) => $c;
        $this->pipe->handle($ctx, $next);

        $this->assertSame(9.0, $ctx->vatRate);
        $this->assertSame(90_000.0, $ctx->vatAmount, '9% of 1,000,000 = 90,000');
    }

    /** @test */
    public function it_uses_overridden_vat_rate_from_settings(): void
    {
        $this->setVatRate(15.0);

        $ctx = $this->makeContext();
        $ctx->withVat = true;
        $ctx->payablePrice = 2_000_000;

        $next = fn ($c) => $c;
        $this->pipe->handle($ctx, $next);

        $this->assertSame(15.0, $ctx->vatRate);
        $this->assertSame(300_000.0, $ctx->vatAmount, '15% of 2,000,000 = 300,000');
    }

    /** @test */
    public function it_invokes_next_pipe(): void
    {
        $ctx = $this->makeContext();
        $ctx->withVat = false;

        $called = false;
        $next = function ($c) use (&$called) {
            $called = true;
            return $c;
        };

        $this->pipe->handle($ctx, $next);

        $this->assertTrue($called, 'Pipe must call $next');
    }
}
