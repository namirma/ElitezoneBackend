<?php

namespace App\Support;

use App\Constants\FinancialCodes;
use Tests\Feature\Order\OrderDocumentAccessTest;

/**
 * تنها مرجعِ «کدام سندِ سفارش، با چه اسلاگی، مالِ کدام طرفِ معامله است».
 *
 * چرا این کلاس وجود دارد: پیش از این، همین دانش در **دو جای مستقل** تکرار شده بود —
 * نقشه‌ی مخاطبان داخلِ `OrderInvoiceController` و یک whitelist دستی روی خودِ روت
 * (`->whereIn('type', ['initial', 'final', 'tax'])`). آن دو با هم واگرا شدند و
 * اشتراکشان برای سوپلایر و الیت‌یار **تهی** شد:
 *
 *   - هشت اسلاگِ سوپلایر (از جمله Doc K «بارنامه/مجوز خروج» و Doc C «دستور بارگیری»)
 *     اصلاً با قیدِ روت نمی‌خواندند → ۴۰۴، پیش از آنکه کنترلر اجرا شود.
 *   - سه اسلاگی که قیدِ روت اجازه می‌داد، طبق نقشه‌ی مخاطبان buyer-only بودند → ۴۰۳.
 *
 * یعنی نقشه‌ی مخاطبان — که خودش برای بستنِ یک نشتِ واقعی نوشته شده بود و درست کار
 * می‌کرد — بی‌صدا غیرقابل‌دسترس شده بود. هر دو مصرف‌کننده حالا از همین کلاس
 * می‌خوانند، پس دیگر چیزی برای واگرا شدن نمانده.
 *
 * @see OrderDocumentAccessTest
 */
final class OrderDocumentCatalog
{
    public const AUDIENCE_BUYER = 'buyer';

    public const AUDIENCE_SUPPLIER = 'supplier';

    public const AUDIENCE_ELITE_YAR = 'elite_yar';

    /**
     * اسلاگِ URL → کدِ سند. هیچ fallback ای وجود ندارد: اسلاگِ ناشناخته ۴۲۲ می‌گیرد،
     * نه یک سندِ نامرتبطِ دیگر.
     *
     * @var array<string, string>
     */
    private const CODES = [
        // Stage 0
        'initial' => FinancialCodes::DOC_PRE_INVOICE,
        // Stage 1 — direct purchase
        'receipt' => FinancialCodes::DOC_RECEIPT_OWNER,
        'supplier_report' => FinancialCodes::DOC_REPORT_SUPPLIER,
        'loading' => FinancialCodes::DOC_LOADING_ORDER,
        'elite_yar_report' => FinancialCodes::DOC_ELITE_YAR_REPORT,
        // Stage 2 — reservation
        'reserve_receipt' => FinancialCodes::DOC_RESERVE_RECEIPT,
        'reserve_report' => FinancialCodes::DOC_RESERVE_REPORT,
        // Stage 3 — reservation completion
        'final' => FinancialCodes::DOC_FINAL_RECEIPT_OWNER,
        'final_supplier' => FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
        // Stage 4 — cancellation
        'refund' => FinancialCodes::DOC_REFUND_RECEIPT,
        'cancel_report' => FinancialCodes::DOC_CANCEL_REPORT,
        // Stage 5 — post-weighbridge (K=بارنامه/مجوز خروج، LO/LS=فاکتور اصلاح‌شده)
        'bill_of_lading' => FinancialCodes::DOC_BILL_OF_LADING,
        'modified_owner' => FinancialCodes::DOC_MODIFIED_INVOICE_OWNER,
        'modified_supplier' => FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER,
        // Stage 6 — Moadian taxpayer system
        'tax' => FinancialCodes::DOC_TAX_INVOICE_BUYER,
        'tax_supplier' => FinancialCodes::DOC_TAX_INVOICE_SUPPLIER,
        'tax_elite_yar' => FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR,
        // Stage 7 — surveys (خارج از whitelist روت — به `downloadableSlugs()` نگاه کن)
        'survey_buyer' => FinancialCodes::DOC_SURVEY,
        'survey_elite_yar' => FinancialCodes::DOC_SURVEY_ELITE_YAR,
    ];

    /**
     * هر سند مالِ کدام طرف است. سندی که اینجا نیامده باشد برای **همه** ممنوع است
     * (fail-closed) — پس افزودنِ کدِ تازه بدون مخاطب، دسترسی باز نمی‌کند.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        'initial' => [self::AUDIENCE_BUYER],
        'receipt' => [self::AUDIENCE_BUYER],
        'reserve_receipt' => [self::AUDIENCE_BUYER],
        'final' => [self::AUDIENCE_BUYER],
        'refund' => [self::AUDIENCE_BUYER],
        'modified_owner' => [self::AUDIENCE_BUYER],
        'tax' => [self::AUDIENCE_BUYER],
        'survey_buyer' => [self::AUDIENCE_BUYER],
        'bill_of_lading' => [self::AUDIENCE_BUYER, self::AUDIENCE_SUPPLIER],
        'loading' => [self::AUDIENCE_BUYER, self::AUDIENCE_SUPPLIER],
        'supplier_report' => [self::AUDIENCE_SUPPLIER],
        'reserve_report' => [self::AUDIENCE_SUPPLIER],
        'final_supplier' => [self::AUDIENCE_SUPPLIER],
        'cancel_report' => [self::AUDIENCE_SUPPLIER],
        'modified_supplier' => [self::AUDIENCE_SUPPLIER],
        'tax_supplier' => [self::AUDIENCE_SUPPLIER],
        'elite_yar_report' => [self::AUDIENCE_ELITE_YAR],
        'tax_elite_yar' => [self::AUDIENCE_ELITE_YAR],
        'survey_elite_yar' => [self::AUDIENCE_ELITE_YAR],
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
    private const OUT_OF_PRODUCT_SCOPE = ['survey_buyer', 'survey_elite_yar'];

    /** «فاکتور» برای هر طرف سندِ متفاوتی است — لینکِ خریدار برای سوپلایر ۴۰۳ می‌دهد. */
    private const PRIMARY_SLUG = [
        self::AUDIENCE_BUYER => 'initial',
        self::AUDIENCE_SUPPLIER => 'supplier_report',
        self::AUDIENCE_ELITE_YAR => 'elite_yar_report',
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
     * مخاطبانِ مجازِ یک سند. اسلاگِ نگاشته‌نشده آرایه‌ی خالی می‌دهد یعنی «هیچ‌کس».
     *
     * @return list<string>
     */
    public static function audiencesFor(string $slug): array
    {
        return self::AUDIENCES[$slug] ?? [];
    }

    /**
     * whitelist روت — هر سندی که کدی دارد، منهای آن‌هایی که خارج از دامنه‌ی محصول‌اند.
     *
     * مجوزِ واقعی اینجا نیست؛ `audiencesFor()` است. این فهرست فقط تعیین می‌کند که
     * درخواست به کنترلر برسد (۴۲۲/۴۰۳ بگیرد) یا اصلاً روت پیدا نشود (۴۰۴).
     *
     * @return list<string>
     */
    /**
     * اسنادی که روی سفارشِ **لغوشده** هنوز قابلِ دانلودند — پاسخِ مدیریت به پرسشِ ۱۶
     * (۱۴۰۵/۰۷/۱۳، گزینه‌ی الف): «در سفارشِ لغوشده فقط رسیدِ بازگشتِ وجه دانلود می‌شود»؛
     * گزارشِ لغو هم همان خانواده است. فاکتورهای خرید بسته می‌شوند.
     *
     * @return list<string>
     */
    public static function slugsAllowedOnCancelledOrder(): array
    {
        return ['refund', 'cancel_report'];
    }

    public static function downloadableSlugs(): array
    {
        return array_values(array_diff(array_keys(self::CODES), self::OUT_OF_PRODUCT_SCOPE));
    }

    /** سندِ «فاکتورِ» پیش‌فرضِ هر نقش، برای دکمه‌ی دانلود در پاسخِ سفارش. */
    public static function primarySlugFor(string $audience): ?string
    {
        return self::PRIMARY_SLUG[$audience] ?? null;
    }
}
