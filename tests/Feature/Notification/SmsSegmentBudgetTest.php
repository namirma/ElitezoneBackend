<?php

namespace Tests\Feature\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پیامک‌های پرتکرار باید در **یک سگمنت** جا شوند.
 *
 * متنِ فارسی با UCS-2 کدگذاری می‌شود: ۷۰ کاراکتر در یک پیامکِ تکی، و ۶۷ در هر
 * تکه وقتی چندتکه شود. یک کاراکتر بیشتر از ۷۰ یعنی **دو برابر هزینه**، روی هر
 * پیامکی که فرستاده می‌شود.
 *
 * این تست فقط قالب‌هایی را می‌گیرد که به‌ازای هر سفارش/تراکنش می‌روند — نه
 * قالب‌های کم‌تکرار مثل خوش‌آمدگویی. دو قالبِ پرداخت در نسخهٔ اولشان ۲ سگمنت
 * بودند و کسی متوجه نشد، چون هیچ چیز طول را اندازه نمی‌گرفت.
 *
 * شمارهٔ سفارش خودش ۲۵ کاراکتر است، پس بودجه واقعاً تنگ است. اگر متنی را عوض
 * می‌کنید و این تست قرمز شد، **متن را کوتاه کنید؛ سقف را بالا نبرید.**
 */
class SmsSegmentBudgetTest extends TestCase
{
    /** نمونهٔ واقعیِ خروجی DocumentSequenceService — بلندترین حالتِ ممکن. */
    private const SAMPLE_ORDER_NUMBER = 'EZ-ORD-RB-140502-70300100';

    private const SINGLE_SEGMENT = 70;

    private const MULTIPART_SEGMENT = 67;

    /**
     * بودجهٔ سگمنتِ هر قالب — عددی که **امروز** واقعاً هزینه می‌شود.
     *
     * دو قالبِ باسکول ۳۸ کاراکتر داده حمل می‌کنند (شمارهٔ سفارش + مبلغ)، پس یک
     * سگمنت برایشان دست‌نیافتنی است و ۲ کفِ واقعی است — فارسی را برای رسیدن به
     * عددی که ممکن نیست خراب نمی‌کنیم. عددها اینجا ثبت شده‌اند تا رشدِ تصادفی
     * گرفته شود، نه اینکه وضع موجود تأیید شود.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: int}>
     */
    public static function highVolumeTemplates(): array
    {
        $order = ['order_number' => self::SAMPLE_ORDER_NUMBER];
        $money = $order + ['amount' => '3,100,000,000'];

        return [
            // این دو در همین دور به یک سگمنت رسیدند (هر کدام ۲ بود).
            'order paid — buyer' => ['sms.order_paid_buyer', $order, 1],
            'order paid — supplier' => ['sms.order_paid_supplier', $order, 1],

            // موجود؛ کوتاه‌کردنشان سگمنت کم نمی‌کند، پس دست نخورده‌اند.
            'order placed' => ['sms.order_placed', $order, 2],
            'buyer refund released' => ['sms.buyer_refund_released', $money, 2],

            // ⚠️ سه سگمنت — گران‌ترین قالبِ سیستم. با کوتاه‌کردن به ۲ می‌رسد؛
            // چون متنِ رو به مشتری است، تغییرش تصمیم محصول است نه مهندسی.
            'supplier debt' => ['sms.supplier_debt_notification', $money, 3],
        ];
    }

    #[Test]
    #[DataProvider('highVolumeTemplates')]
    public function a_high_volume_template_stays_within_its_segment_budget(string $key, array $replace, int $budget): void
    {
        $rendered = __($key, $replace);

        $this->assertNotSame($key, $rendered, "کلید ترجمهٔ {$key} وجود ندارد.");

        $length = mb_strlen($rendered);
        $segments = $length <= self::SINGLE_SEGMENT
            ? 1
            : (int) ceil($length / self::MULTIPART_SEGMENT);

        $this->assertLessThanOrEqual(
            $budget,
            $segments,
            sprintf(
                "%s حالا %d کاراکتر و %d پیامک است (بودجه %d) — هزینه بالا رفت.\nمتن را کوتاه کنید، بودجه را بالا نبرید.\n%s",
                $key,
                $length,
                $segments,
                $budget,
                str_replace("\n", ' ⏎ ', $rendered),
            )
        );
    }

    #[Test]
    public function the_order_number_placeholder_is_actually_substituted(): void
    {
        // اگر placeholder غلط تایپ شود، متن با «:order_number» خام می‌رود و طولش
        // هم کوتاه‌تر می‌شود — یعنی تستِ بالا سبز می‌ماند و باگ رد می‌شود.
        foreach (['sms.order_paid_buyer', 'sms.order_paid_supplier'] as $key) {
            $this->assertStringContainsString(
                self::SAMPLE_ORDER_NUMBER,
                __($key, ['order_number' => self::SAMPLE_ORDER_NUMBER]),
                "{$key} شمارهٔ سفارش را جایگذاری نمی‌کند."
            );
        }
    }
}
