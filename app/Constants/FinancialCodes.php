<?php

namespace App\Constants;

class FinancialCodes
{
    // =========================================================================
    //  1. انواع کیف پول (Wallet Types)
    //  طبق تصویر image_2e15d2.png
    // =========================================================================
    public const WALLET_OWNER      = 'WO';    // کیف پول خریدار
    public const WALLET_SUPPLIER   = 'WS';    // کیف پول تامین‌کننده
    public const WALLET_SAZE       = 'WSSG';  // کیف پول واسط گروه نرم‌افزاری سازه
    public const WALLET_CONSULTANT = 'WCT';   // کیف پول مشاور فنی
    public const WALLET_VAT        = 'WVAT';  // کیف پول مالیات بر ارزش افزوده


    // =========================================================================
    //  2. وضعیت موجودی (Balance Status)
    //  طبق تصویر image_2e15d2.png
    // =========================================================================
    public const STATUS_HOLD       = 'hold';      // H (بلوکه شده / در انتظار)
    public const STATUS_AVAILABLE  = 'confirmed'; // A (آزاد / قابل برداشت)


    // =========================================================================
    //  3. اسناد و گزارش‌ها (Documents)
    //  طبق لیست کامل در تصویر image_2e0a6c.png
    // =========================================================================

    // --- مرحله ۰: قبل از پرداخت ---
    public const DOC_PRE_INVOICE           = '0'; // پیش‌فاکتور برای خریدار (یک خرید خاص)

    // --- مرحله ۱: خرید مستقیم (Direct Purchase) ---
    public const DOC_RECEIPT_OWNER         = 'A'; // رسید پرداخت برای خریدار
    public const DOC_REPORT_SUPPLIER       = 'B'; // گزارش پرداخت خریدار برای سوپلایر (یکجا/کامل)
    public const DOC_LOADING_ORDER         = 'C'; // دستور بارگیری (مشترک برای سوپلایر و خریدار)
    public const DOC_CONSULTANT_REPORT     = 'D'; // گزارش پرداخت بک‌آفیس به مشاور فنی

    // --- مرحله ۲: شروع رزرو (Reservation Start) ---
    public const DOC_RESERVE_RECEIPT       = 'E'; // رسید پرداخت رزرو برای خریدار
    public const DOC_RESERVE_REPORT        = 'F'; // گزارش رزرو خریدار برای سوپلایر

    // --- مرحله ۳: تکمیل وجه رزرو (Reservation Completion) ---
    public const DOC_FINAL_RECEIPT_OWNER   = 'G'; // رسید تکمیل پرداخت برای خریدار
    public const DOC_FINAL_REPORT_SUPPLIER = 'H'; // گزارش تکمیل پرداخت خریدار برای سوپلایر

    // --- مرحله ۴: لغو و استرداد (Cancellation & Refund) ---
    // نکته: طبق دیاگرام حروف i و j کوچک هستند
    public const DOC_REFUND_RECEIPT        = 'i'; // رسید بازگشت مبلغ رزرو برای خریدار
    public const DOC_CANCEL_REPORT         = 'j'; // گزارش لغو رزرو خریدار برای سوپلایر

    // --- مرحله ۵: بعد از باسکول و حمل (Post Scale & Shipping) ---
    // --- مرحله ۵: بعد از باسکول و حمل (Post Scale & Shipping) ---
    public const DOC_EXIT_PERMIT            = 'I'; // مجوز خروج / حواله
    public const DOC_SUPPLEMENTARY_RECEIPT  = 'J'; // رسید مابه‌التفاوت وزن برای خریدار
    public const DOC_SUPPLEMENTARY_REPORT   = 'K'; // گزارش مابه‌التفاوت وزن برای سوپلایر
    public const DOC_MODIFIED_INVOICE_OWNER    = 'LO'; // فاکتور اولیه اصلاح شده برای خریدار
    public const DOC_MODIFIED_INVOICE_SUPPLIER = 'LS'; // فاکتور اولیه اصلاح شده برای سوپلایر
    public const DOC_REFUND_REPORT_WEIGH    = 'M'; // گزارش استرداد وجه برای سوپلایر
    public const DOC_BILL_OF_LADING         = 'N'; // بارنامه

    // --- مرحله ۶: سامانه مودیان (Taxpayer System) ---
    public const DOC_TAX_INVOICE_SUPPLIER  = 'O'; // فاکتور نهایی روی سامانه مودیان (برای شرکت نرم‌افزاری سازه توسط سوپلایر)
    public const DOC_TAX_INVOICE_BUYER     = 'P'; // فاکتور نهایی روی سامانه مودیان (برای خریدار توسط شرکت نرم‌افزاری سازه)
    public const DOC_TAX_INVOICE_CONSULTANT = 'Q'; // فاکتور نهایی روی سامانه مودیان (برای شرکت نرم‌افزاری سازه توسط مشاور فنی)

    // --- مرحله ۷: الگوریتم‌ها و تسویه نهایی ---
    // این‌ها بیشتر فرآیند هستند تا سند، اما در لیست دیاگرام کد دارند
    public const DOC_FREIGHT_SETTLEMENT    = 'R'; // فرآیند تسویه حساب هزینه حمل بار و رسید تحویل بار
    public const DOC_SURVEY                = 'S'; // نظرسنجی از خریدار (Hold شده)
}
