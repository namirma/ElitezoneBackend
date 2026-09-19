<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\Order;
use App\Models\PriceSetting;
use App\Services\Financial\SettlementCalculatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * نرخ مالیات باید از یک منبع بیاید و هرگز صفر نشود.
 *
 * پیش‌زمینه: `orders.vat_rate` از نوع `decimal(5,2) NOT NULL DEFAULT 0.00` است و
 * هیچ‌کس پیش از پرداخت پرش نمی‌کند. سه محلِ محاسبه‌ی تسویه `$order->vat_rate ?? 10.0`
 * می‌نوشتند؛ چون مقدار `0.00` است و نه `null`، عملگر `??` هیچ‌وقت فعال نمی‌شد و
 * POO/PTS با **مالیات صفر** حساب می‌شد — در حالی که سبد خرید مالیات ۱۰٪ گرفته بود.
 * پیامد دوم: `sazeVat = gross × 0` می‌شد، پس هولد امانیِ مالیات ثبت نمی‌شد و اسناد
 * مودیان (O/P/Q) صفر آزاد می‌کردند.
 */
class VatRateResolutionTest extends TestCase
{
    // `DatabaseTransactions` مثل بقیه‌ی تست‌ها، نه `RefreshDatabase`.
    // `RefreshDatabase` وسط اجرا `migrate:fresh` می‌زند: اسکیما drop و دوباره
    // ساخته می‌شود، پس هر تستی که هم‌زمان روی همان دیتابیس بدود ممکن است ستونی را
    // نبیند که وجود دارد. تنها چیزی که این را از دیتابیسِ زنده دور نگه می‌داشت
    // گاردِ `CreatesApplication::guardAgainstLiveDatabase()` بود.
    use DatabaseTransactions;

    private SettlementCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(SettlementCalculatorService::class);
    }

    #[Test]
    public function a_zero_column_falls_back_to_the_live_setting_not_to_zero(): void
    {
        PriceSetting::updateOrCreate(
            [PriceSetting::CODE => PriceSetting::CODE_VAT_RATE],
            [PriceSetting::VALUE => '10', PriceSetting::NAME => 'مالیات بر ارزش افزوده']
        );

        // دقیقاً همان چیزی که دیتابیس برای یک سفارش تازه می‌گذارد.
        $order = (new Order)->forceFill([Order::VAT_RATE => '0.00']);

        $this->assertSame(
            10.0,
            $this->calculator->resolveVatRate($order),
            'A 0.00 column must resolve to the configured rate — this is the bug that made POO/PTS VAT-free.'
        );
    }

    #[Test]
    public function the_rate_tracks_the_setting_rather_than_a_hardcoded_ten(): void
    {
        PriceSetting::updateOrCreate(
            [PriceSetting::CODE => PriceSetting::CODE_VAT_RATE],
            [PriceSetting::VALUE => '9', PriceSetting::NAME => 'مالیات بر ارزش افزوده']
        );

        $order = (new Order)->forceFill([Order::VAT_RATE => '0.00']);

        $this->assertSame(
            9.0,
            $this->calculator->resolveVatRate($order),
            'Settlement must follow price_settings.vat_rate; a hardcoded 10 would desync it from cart pricing.'
        );
    }

    #[Test]
    public function a_real_snapshot_on_the_order_wins_over_the_live_setting(): void
    {
        // نرخ لحظه‌ی پرداخت باید ثابت بماند: اگر بعداً نرخ عوض شود، بازاجرای
        // باسکول همان سفارش نباید با نرخ جدید حساب شود.
        PriceSetting::updateOrCreate(
            [PriceSetting::CODE => PriceSetting::CODE_VAT_RATE],
            [PriceSetting::VALUE => '9', PriceSetting::NAME => 'مالیات بر ارزش افزوده']
        );

        // `VAT_RATE` عمداً در `$fillable` نیست (ستون‌های اسنپ‌شات مالی فقط با
        // forceFill نوشته می‌شوند) — سازنده‌ی معمولی بی‌صدا آن را دور می‌ریزد.
        $order = (new Order)->forceFill([Order::VAT_RATE => '10.00']);

        $this->assertSame(10.0, $this->calculator->resolveVatRate($order));
    }
}
