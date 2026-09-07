<?php

namespace App\Support;

use App\Constants\FinancialCodes;
use InvalidArgumentException;

/**
 * تنها مرجعِ «کدام سندِ سفارش، با چه اسلاگی، مالِ کدام طرفِ معامله است».
 *
 * چرا این کلاس وجود دارد: پیش از این، همین دانش در **دو جای مستقل** تکرار شده بود —
 * نقشهٔ مخاطبان داخلِ `OrderInvoiceController` و یک whitelist دستی روی خودِ روت
 * (`->whereIn('type', ['initial', 'final', 'tax'])`). آن دو با هم واگرا شدند و
 * اشتراکشان برای سوپلایر و الیت‌یار **تهی** شد:
 *
 *   - هشت اسلاگِ سوپلایر (از جمله Doc K «بارنامه/مجوز خروج» و Doc C «دستور بارگیری»)
 *     اصلاً با قیدِ روت نمی‌خواندند → ۴۰۴، پیش از آنکه کنترلر اجرا شود.
 *   - سه اسلاگی که قیدِ روت اجازه می‌داد، طبق نقشهٔ مخاطبان buyer-only بودند → ۴۰۳.
 *
 * یعنی نقشهٔ مخاطبان — که خودش برای بستنِ یک نشتِ واقعی نوشته شده بود و درست کار
 * می‌کرد — بی‌صدا غیرقابل‌دسترس شده بود. هر دو مصرف‌کننده حالا از همین کلاس
 * می‌خوانند، پس دیگر چیزی برای واگرا شدن نمانده.
 *
 * @see \Tests\Feature\Order\OrderDocumentAccessTest
 */
final class OrderDocumentCatalog
{
    public const AUDIENCE_BUYER = 'buyer';

    public const AUDIENCE_SUPPLIER = 'supplier';

    public const AUDIENCE_ELITE_YAR = 'elite_yar';

    /**
     * اسلاگ‌ها ثابتِ کلاس‌اند، نه رشته‌ی خام — همان قاعده‌ای که برای نامِ ستون‌ها
     * (`Order::STATUS`) رعایت می‌شود، و به همان دلیل.
     *
     * ⚠️ **هیچ‌جای دیگری اجازه ندارد اسلاگِ سند را دوباره تایپ کند.** تایم‌لاینِ
     * سفارش این کار را کرده بود و سه اسلاگ ساخته بود که اصلاً وجود نداشتند —
     * `tax_buyer`، `exit_permit`، `loading_order` — یعنی سه لینکِ ۴۰۴ روی سندهایی
     * که بدونشان بار از کارخانه خارج نمی‌شود. با ثابت، همان اشتباه خطای PHP/PHPStan
     * می‌شود نه یک ۴۰۴ خاموش در مرورگرِ کاربر.
     */
    public const SLUG_INITIAL = 'initial';

    public const SLUG_RECEIPT = 'receipt';

    public const SLUG_SUPPLIER_REPORT = 'supplier_report';

    public const SLUG_LOADING = 'loading';

    public const SLUG_ELITE_YAR_REPORT = 'elite_yar_report';

    public const SLUG_RESERVE_RECEIPT = 'reserve_receipt';

    public const SLUG_RESERVE_REPORT = 'reserve_report';

    public const SLUG_FINAL = 'final';

    public const SLUG_FINAL_SUPPLIER = 'final_supplier';

    public const SLUG_REFUND = 'refund';

    public const SLUG_CANCEL_REPORT = 'cancel_report';

    /** Doc K — بارنامه / مجوز خروج. «مجوز خروج» سندِ جدایی نیست، همین است. */
    public const SLUG_BILL_OF_LADING = 'bill_of_lading';

    public const SLUG_MODIFIED_OWNER = 'modified_owner';

    public const SLUG_MODIFIED_SUPPLIER = 'modified_supplier';

    /** Doc P — فاکتور مودیانِ سیستم به نامِ خریدار. */
    public const SLUG_TAX = 'tax';

    public const SLUG_TAX_SUPPLIER = 'tax_supplier';

    public const SLUG_TAX_ELITE_YAR = 'tax_elite_yar';

    public const SLUG_SURVEY_BUYER = 'survey_buyer';

    public const SLUG_SURVEY_ELITE_YAR = 'survey_elite_yar';

    /**
     * اسلاگِ URL → کدِ سند. هیچ fallback ای وجود ندارد: اسلاگِ ناشناخته ۴۲۲ می‌گیرد،
     * نه یک سندِ نامرتبطِ دیگر.
     *
     * @var array<string, string>
     */
    private const CODES = [
        // Stage 0
        self::SLUG_INITIAL => FinancialCodes::DOC_PRE_INVOICE,
        // Stage 1 — direct purchase
        self::SLUG_RECEIPT => FinancialCodes::DOC_RECEIPT_OWNER,
        self::SLUG_SUPPLIER_REPORT => FinancialCodes::DOC_REPORT_SUPPLIER,
        self::SLUG_LOADING => FinancialCodes::DOC_LOADING_ORDER,
        self::SLUG_ELITE_YAR_REPORT => FinancialCodes::DOC_ELITE_YAR_REPORT,
        // Stage 2 — reservation
        self::SLUG_RESERVE_RECEIPT => FinancialCodes::DOC_RESERVE_RECEIPT,
        self::SLUG_RESERVE_REPORT => FinancialCodes::DOC_RESERVE_REPORT,
        // Stage 3 — reservation completion
        self::SLUG_FINAL => FinancialCodes::DOC_FINAL_RECEIPT_OWNER,
        self::SLUG_FINAL_SUPPLIER => FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
        // Stage 4 — cancellation
        self::SLUG_REFUND => FinancialCodes::DOC_REFUND_RECEIPT,
        self::SLUG_CANCEL_REPORT => FinancialCodes::DOC_CANCEL_REPORT,
        // Stage 5 — post-weighbridge (K=بارنامه/مجوز خروج، LO/LS=فاکتور اصلاح‌شده)
        self::SLUG_BILL_OF_LADING => FinancialCodes::DOC_BILL_OF_LADING,
        self::SLUG_MODIFIED_OWNER => FinancialCodes::DOC_MODIFIED_INVOICE_OWNER,
        self::SLUG_MODIFIED_SUPPLIER => FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER,
        // Stage 6 — Moadian taxpayer system
        self::SLUG_TAX => FinancialCodes::DOC_TAX_INVOICE_BUYER,
        self::SLUG_TAX_SUPPLIER => FinancialCodes::DOC_TAX_INVOICE_SUPPLIER,
        self::SLUG_TAX_ELITE_YAR => FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR,
        // Stage 7 — surveys (خارج از whitelist روت — به `downloadableSlugs()` نگاه کن)
        self::SLUG_SURVEY_BUYER => FinancialCodes::DOC_SURVEY,
        self::SLUG_SURVEY_ELITE_YAR => FinancialCodes::DOC_SURVEY_ELITE_YAR,
    ];

    /**
     * هر سند مالِ کدام طرف است. سندی که اینجا نیامده باشد برای **همه** ممنوع است
     * (fail-closed) — پس افزودنِ کدِ تازه بدون مخاطب، دسترسی باز نمی‌کند.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        self::SLUG_INITIAL => [self::AUDIENCE_BUYER],
        self::SLUG_RECEIPT => [self::AUDIENCE_BUYER],
        self::SLUG_RESERVE_RECEIPT => [self::AUDIENCE_BUYER],
        self::SLUG_FINAL => [self::AUDIENCE_BUYER],
        self::SLUG_REFUND => [self::AUDIENCE_BUYER],
        self::SLUG_MODIFIED_OWNER => [self::AUDIENCE_BUYER],
        self::SLUG_TAX => [self::AUDIENCE_BUYER],
        self::SLUG_SURVEY_BUYER => [self::AUDIENCE_BUYER],
        self::SLUG_BILL_OF_LADING => [self::AUDIENCE_BUYER, self::AUDIENCE_SUPPLIER],
        self::SLUG_LOADING => [self::AUDIENCE_BUYER, self::AUDIENCE_SUPPLIER],
        self::SLUG_SUPPLIER_REPORT => [self::AUDIENCE_SUPPLIER],
        self::SLUG_RESERVE_REPORT => [self::AUDIENCE_SUPPLIER],
        self::SLUG_FINAL_SUPPLIER => [self::AUDIENCE_SUPPLIER],
        self::SLUG_CANCEL_REPORT => [self::AUDIENCE_SUPPLIER],
        self::SLUG_MODIFIED_SUPPLIER => [self::AUDIENCE_SUPPLIER],
        self::SLUG_TAX_SUPPLIER => [self::AUDIENCE_SUPPLIER],
        self::SLUG_ELITE_YAR_REPORT => [self::AUDIENCE_ELITE_YAR],
        self::SLUG_TAX_ELITE_YAR => [self::AUDIENCE_ELITE_YAR],
        self::SLUG_SURVEY_ELITE_YAR => [self::AUDIENCE_ELITE_YAR],
    ];

    /**
     * سندهایی که عمداً از روتِ کاربر بیرون‌اند.
     *
     * نظرسنجی (Doc S / Doc T) در محصولِ فعلی وجود ندارد — همان تصمیمِ ۱۴۰۵/۰۵/۰۳ که
     * `features.order_surveys` را هم خاموش کرد. ماژول دست‌نخورده نگه داشته شده تا
     * برگرداندنش یک خط باشد، ولی نباید از این روت قابلِ دانلود باشد.
     *
     * @var list<string>
     */
    private const OUT_OF_PRODUCT_SCOPE = [self::SLUG_SURVEY_BUYER, self::SLUG_SURVEY_ELITE_YAR];

    /** «فاکتور» برای هر طرف سندِ متفاوتی است — لینکِ خریدار برای سوپلایر ۴۰۳ می‌دهد. */
    private const PRIMARY_SLUG = [
        self::AUDIENCE_BUYER => self::SLUG_INITIAL,
        self::AUDIENCE_SUPPLIER => self::SLUG_SUPPLIER_REPORT,
        self::AUDIENCE_ELITE_YAR => self::SLUG_ELITE_YAR_REPORT,
    ];

    /** آیا این اسلاگ اصلاً سندی می‌شناسد؟ */
    public static function knows(string $slug): bool
    {
        return array_key_exists($slug, self::CODES);
    }

    /** کدِ سندِ متناظر با اسلاگ، یا null برای اسلاگِ ناشناخته. */
    public static function codeFor(string $slug): ?string
    {
        return self::CODES[$slug] ?? null;
    }

    /**
     * مخاطبانِ مجازِ یک سند. اسلاگِ نگاشته‌نشده آرایهٔ خالی می‌دهد یعنی «هیچ‌کس».
     *
     * @return list<string>
     */
    public static function audiencesFor(string $slug): array
    {
        return self::AUDIENCES[$slug] ?? [];
    }

    /**
     * whitelist روت — هر سندی که کدی دارد، منهای آن‌هایی که خارج از دامنهٔ محصول‌اند.
     *
     * مجوزِ واقعی اینجا نیست؛ `audiencesFor()` است. این فهرست فقط تعیین می‌کند که
     * درخواست به کنترلر برسد (۴۲۲/۴۰۳ بگیرد) یا اصلاً روت پیدا نشود (۴۰۴).
     *
     * @return list<string>
     */
    public static function downloadableSlugs(): array
    {
        return array_values(array_diff(array_keys(self::CODES), self::OUT_OF_PRODUCT_SCOPE));
    }

    /**
     * مسیرِ دانلودِ یک سند برای یک سفارش — **تنها** راهِ ساختنِ این لینک.
     *
     * از خودِ روت ساخته می‌شود (`route(..., false)` → مسیرِ بدون دامنه)، پس شکلِ URL
     * هم فقط یک جا تعریف شده است. اسلاگی که روت سرو نمی‌کند اینجا استثنا می‌اندازد
     * به‌جای اینکه لینکِ ۴۰۴ تحویلِ کاربر شود؛ همان قاعده‌ی
     * `InvoiceGenerator::getViewName()` که برای کدِ ناشناخته throw می‌کند.
     */
    public static function downloadPath(int $orderId, string $slug): string
    {
        if (! in_array($slug, self::downloadableSlugs(), true)) {
            throw new InvalidArgumentException("Order document slug [{$slug}] is not downloadable.");
        }

        return route('api.user.orders.invoice', ['order' => $orderId, 'type' => $slug], false);
    }

    /** سندِ «فاکتورِ» پیش‌فرضِ هر نقش، برای دکمهٔ دانلود در پاسخِ سفارش. */
    public static function primarySlugFor(string $audience): ?string
    {
        return self::PRIMARY_SLUG[$audience] ?? null;
    }
}
