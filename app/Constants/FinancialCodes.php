<?php

namespace App\Constants;

class FinancialCodes
{
    // =========================================================================
    //  1. انواع کیف پول (Wallet Types)
    // =========================================================================
    public const WALLET_OWNER = 'WO';       // کیف پول خریدار (Wallet of Owner)
    public const WALLET_SUPPLIER = 'WS';    // کیف پول تامین‌کننده (Wallet of Supplier)
    public const WALLET_SAZE = 'WSSG';      // کیف پول واسط شرکت سازه (Wallet of Saze)
    public const WALLET_CONSULTANT = 'WCT'; // کیف پول مشاور فنی (Wallet of Consultant)
    public const WALLET_VAT = 'WVAT';       // کیف پول ارزش افزوده (Wallet of VAT)


    // =========================================================================
    //  2. وضعیت موجودی (Balance Status)
    // =========================================================================
    public const STATUS_HOLD = 'H';        // موجودی بلوکه/امانت (Hold)
    public const STATUS_AVAILABLE = 'A';   // موجودی آزاد/قابل برداشت (Available)


    // =========================================================================
    //  3. اسناد (Documents)
    // =========================================================================

    // --- الف) سناریوی خرید مستقیم (Direct Purchase) ---
    public const DOC_RECEIPT_OWNER = 'A';        // رسید پرداخت خریدار
    public const DOC_REPORT_SUPPLIER = 'B';      // گزارش پرداخت برای سوپلایر
    public const DOC_LOADING_ORDER = 'C';        // دستور بارگیری (مشترک برای همه حالات موفق)

    // --- ب) سناریوی رزرو: مرحله اول (Reservation Start) ---
    public const DOC_RESERVE_RECEIPT = 'E';      // رسید پرداخت بیعانه رزرو (برای خریدار)
    public const DOC_RESERVE_REPORT = 'F';       // گزارش ثبت رزرو (برای سوپلایر)

    // --- ج) سناریوی رزرو: مرحله دوم/تکمیل وجه (Reservation Completion) ---
    // 
    public const DOC_FINAL_RECEIPT_OWNER = 'G';   // رسید نهایی و تسویه حساب (برای خریدار)
    public const DOC_FINAL_REPORT_SUPPLIER = 'H'; // گزارش نهایی و تسویه حساب (برای سوپلایر)

    // --- د) سناریوی لغو و استرداد (Cancellation) ---
    public const DOC_REFUND_RECEIPT = 'i';     // رسید بازگشت وجه (برای خریدار)
    public const DOC_CANCEL_REPORT = 'j';      // گزارش لغو سفارش (برای سوپلایر)
}
