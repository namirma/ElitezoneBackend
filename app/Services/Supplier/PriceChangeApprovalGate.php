<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Models\ProductPrice;
use App\Models\SupplierRequest;
use App\Services\Sales\SalesSettings;

/**
 * دروازه‌ی «تغییرِ قیمتِ بزرگ تأییدِ ادمین می‌خواهد» (تصمیم مدیریت، پرسش ۱).
 *
 * ⚠️ **این کلاس وجود دارد چون دو مسیرِ نوشتن هست، نه یکی.** تأمین‌کننده هم از
 * `SupplierProductPriceController::update()` قیمت را عوض می‌کند و هم از
 * `bulkStore()` — و دومی روی ردیفِ موجود `update()` می‌زند. اگر دروازه فقط در
 * مسیرِ اول می‌نشست، ارسالِ همان تغییر به‌صورت یک ردیفِ bulk کاملاً دورش می‌زد.
 * پس منطق این‌جاست و هر دو کنترلر از همین رد می‌شوند.
 *
 * قاعده‌ی درصد: نسبت به **آخرین قیمتِ تأییدشده** سنجیده می‌شود و قدرِمطلق است — گران‌شدن و
 * ارزان‌شدن هر دو تأیید می‌خواهند. یک کاهشِ ۸۰٪ هم به‌اندازه‌ی افزایشش نیازمندِ
 * چشمِ ادمین است (قیمتِ اشتباهِ پایین یعنی فروش زیرِ قیمت، نه یک لطف).
 */
final class PriceChangeApprovalGate
{
    /** ستون‌هایی که تأمین‌کننده می‌تواند عوض کند و مشمولِ آستانه‌اند. */
    private const GUARDED = [
        ProductPrice::GENERAL_PRICE,
        ProductPrice::SPECIAL_PRICE,
        ProductPrice::GOLDEN_FIGURE,
    ];

    public function __construct(private readonly SalesSettings $settings) {}

    /**
     * آیا این تغییر باید به صف برود؟
     *
     * `null` یعنی «فوراً اعمال کن». آرایه یعنی «به صف برود» و همان آرایه‌ی
     * `payload` است — عمداً **تخت** (نه تودرتو) چون صفحه‌ی بک‌آفیس مقدارِ
     * غیرِاسکالر را رندر نمی‌کند.
     *
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>|null
     */
    public function evaluate(ProductPrice $existing, array $proposed): ?array
    {
        $threshold = $this->settings->priceChangeApprovalPercent();

        $payload = [];
        $maxPercent = 0.0;

        foreach (self::GUARDED as $field) {
            if (! array_key_exists($field, $proposed)) {
                continue;
            }

            $current = (float) $existing->{$field};
            $next = (float) $proposed[$field];

            if ($this->sameMoney($current, $next)) {
                continue;
            }

            /*
             * ⚠️ درصد نسبت به **آخرین قیمتِ تأییدشده** سنجیده می‌شود، نه قیمتِ
             * فعلی (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹؛ گزینه‌ی «قیمت فعلی» دیده و رد شد).
             *
             * چون تغییرِ زیرِ آستانه بدونِ تأیید اعمال می‌شود، با مبنای «قیمت
             * فعلی» تأمین‌کننده می‌توانست ۱۴٪ + ۱۴٪ + ۱۴٪ بزند و قیمت را ۴۸٪
             * جابه‌جا کند بی‌آنکه یک بار به صف بخورد. هر قدم قانونی است، جمعشان
             * نیست.
             *
             * ولی `current_*` در payload همچنان **قیمتِ فعلی** است، نه مبنا —
             * آن مقدار کارِ دیگری می‌کند: گاردِ «پایه تکان خورده» موقعِ تأیید با
             * همان مقایسه می‌کند تا قیمتِ تازه‌تر بی‌صدا بازنویسی نشود.
             */
            $baseline = $existing->approvedBaselineFor($field);

            $percent = $this->percentChange($baseline, $next);
            $maxPercent = max($maxPercent, $percent);

            $payload['current_'.$field] = (string) $current;
            $payload['proposed_'.$field] = (string) $next;
            $payload['baseline_'.$field] = (string) $baseline;
        }

        if ($payload === [] || $maxPercent <= $threshold) {
            return null;
        }

        return [
            'product_price_id' => $existing->getKey(),
            'change_percent' => round($maxPercent, 2),
            ...$payload,
        ];
    }

    /**
     * ثبتِ درخواست در همان صفی که دو نوعِ دیگر از آن رد می‌شوند.
     *
     * مهلتِ بررسی (تصمیم مدیریت: ۴۸ ساعت) با `forceFill` نوشته می‌شود چون
     * `REVIEW_DEADLINE` عمداً fillable نیست — مهلت را سامانه تعیین می‌کند، نه
     * بدنه‌ی درخواست؛ وگرنه فرستنده می‌توانست مهلتی در گذشته بنویسد.
     */
    public function queue(int $supplierUserId, array $payload, string $reason): SupplierRequest
    {
        $request = SupplierRequest::create([
            SupplierRequest::SUPPLIER_USER_ID => $supplierUserId,
            SupplierRequest::TYPE => SupplierRequest::TYPE_PRICE_CHANGE,
            // ستونِ واقعی کنارِ payload: کاتالوگِ عمومی با همین، واریانت را تا
            // تصمیمِ ادمین پنهان می‌کند (`ProductVariant::scopeCatalogVisible`).
            SupplierRequest::PRODUCT_PRICE_ID => $payload['product_price_id'] ?? null,
            SupplierRequest::REASON => $reason,
            SupplierRequest::PAYLOAD => $payload,
        ]);

        $request->forceFill([
            SupplierRequest::REVIEW_DEADLINE => now()->addHours($this->settings->priceChangeReviewHours()),
        ])->save();

        return $request;
    }

    /**
     * درصدِ تغییر نسبت به مقدارِ فعلی.
     *
     * ⚠️ قیمتِ فعلیِ صفر حالتِ واقعی است (ردیفی که هنوز قیمت‌گذاری نشده) و
     * تقسیم بر صفر می‌دهد. آن را «تغییرِ بی‌نهایت» می‌گیریم نه صفر: رفتن از
     * هیچ به یک قیمتِ واقعی دقیقاً همان چیزی است که باید دیده شود، و گرفتنِ
     * صفر آن را بی‌صدا از دروازه رد می‌کرد.
     */
    private function percentChange(float $current, float $next): float
    {
        if ($current <= 0.0) {
            return INF;
        }

        return abs($next - $current) / $current * 100.0;
    }

    /** ستون‌های decimal رشته می‌آیند؛ مقایسه‌ی float مستقیم نویزِ گِردکردن دارد. */
    private function sameMoney(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}
