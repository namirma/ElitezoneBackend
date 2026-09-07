<?php

namespace Tests\Feature\Financial;

use App\Http\Requests\Financial\WalletDepositRequest;
use App\Services\Payment\PaymentBranchResolver;
use App\Services\Sales\SalesSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سقفِ درگاه باید **یک** عدد باشد.
 *
 * این تست وجود دارد چون دو تعریفِ جدا از یک واقعیت داشتیم و ۱۰ برابر اختلاف
 * داشتند: `PaymentBranchResolver` با ۲٬۰۰۰٬۰۰۰٬۰۰۰ ریال تصمیم می‌گرفت سفارش به
 * درگاه برود، و `WalletDepositRequest` هر مبلغِ بالای ۲۰۰٬۰۰۰٬۰۰۰ را ۴۲۲ می‌کرد.
 * روی یک سفارشِ واقعی دیده شد: سایت «شارژ ۳۸۲٬۸۰۰٬۰۰۰ ریال از درگاه» را پیشنهاد
 * می‌داد و پرداخت ممکن نبود — هر کسریِ بینِ ۲۰ تا ۲۰۰ میلیون تومان روی آن ریل
 * پرداخت‌ناپذیر بود، و همان سفارش‌ها از شاخه‌ی رزرو که واقعاً واجدش بودند هم
 * محروم می‌شدند.
 *
 * هیچ‌کدام از این دو خطا نمی‌داد. تنها راهِ دیدنش مقایسه‌ی دو عدد است، پس همان
 * مقایسه این‌جا قفل شده.
 */
class GatewayCapIsOneNumberTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_deposit_validator_and_the_branch_resolver_read_the_same_cap(): void
    {
        $settings = app(SalesSettings::class);

        $request = WalletDepositRequest::create('/', 'POST', ['payment_method' => 'shaparak']);
        $request->setContainer($this->app);

        $max = $this->maxRuleOf($request);

        $this->assertNotNull($max, 'قاعده‌ی max برای روشِ shaparak اصلاً اعمال نشده است.');
        $this->assertSame(
            (int) $settings->shaparakValue(),
            $max,
            'اعتبارسنجیِ واریز و تصمیم‌گیرِ درگاه دو سقفِ متفاوت دارند — همان باگی که '
            .'مبلغِ پیشنهادیِ سایت را پرداخت‌ناپذیر می‌کرد.'
        );
    }

    /**
     * و مهم‌تر: هر مبلغی که تصمیم‌گیر به درگاه می‌فرستد باید از اعتبارسنجی رد شود.
     *
     * این همان چیزی است که کاربر تجربه می‌کند؛ برابریِ دو عدد بالا فقط وسیله است.
     */
    #[Test]
    public function an_amount_the_resolver_sends_to_the_gateway_passes_validation(): void
    {
        $cap = (int) app(SalesSettings::class)->shaparakValue();

        // درست زیرِ سقف — همان بازه‌ای که قبلاً رد می‌شد.
        $amount = $cap - 1;

        $request = WalletDepositRequest::create('/', 'POST', [
            'payment_method' => 'shaparak',
            'amount' => $amount,
        ]);
        $request->setContainer($this->app);

        $validator = validator($request->all(), $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'مبلغی که تصمیم‌گیر روی ریلِ درگاه می‌گذارد، خودِ اعتبارسنجی ردش می‌کند: '
            .implode(' | ', $validator->errors()->all())
        );
    }

    #[Test]
    public function the_retired_config_key_has_not_come_back(): void
    {
        $this->assertNull(
            config('financial.deposit_max_shaparak'),
            'کلیدِ `financial.deposit_max_shaparak` برگشته است. این همان تعریفِ دومِ '
            .'سقفِ درگاه است که ۱۰ برابر اختلاف داشت؛ سقف فقط از `shaparak_value` خوانده می‌شود.'
        );
    }

    #[Test]
    public function the_branch_resolver_still_reads_the_setting(): void
    {
        // اگر تصمیم‌گیر روزی به یک ثابت برگردد، برابریِ بالا بی‌معنا می‌شود.
        $source = file_get_contents((new \ReflectionClass(PaymentBranchResolver::class))->getFileName());

        $this->assertStringContainsString('shaparakValue()', $source);
        $this->assertStringNotContainsString('deposit_max_shaparak', $source);
    }

    /** مقدارِ عددیِ قاعده‌ی `max:` روی فیلدِ amount. */
    private function maxRuleOf(WalletDepositRequest $request): ?int
    {
        foreach ($request->rules()['amount'] as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4);
            }
        }

        return null;
    }
}
