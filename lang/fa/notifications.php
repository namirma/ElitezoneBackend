<?php

/*
| عنوانِ اعلان‌های درون‌برنامه‌ای (و در نتیجه، عنوانِ نوتیفیکیشنِ پوش).
|
| **متنِ** اعلان همان متنِ پیامک است و از `lang/fa/sms.php` می‌آید — عمداً
| تکرارش نکردیم، چون دو نسخه از یک جمله دیر یا زود از هم واگرا می‌شوند.
| فقط «عنوان» اینجاست، که پیامک اصلاً ندارد.
|
| کوتاه بمانند: عنوانِ نوتیفیکیشن روی گوشی در یک خط بریده می‌شود.
*/
return [
    'order_placed_subject' => 'ثبت سفارش',
    'order_paid_subject' => 'پرداخت سفارش',
    'order_cancelled_subject' => 'لغو سفارش',
    'supplier_new_order_subject' => 'سفارش جدید',
    'wallet_funded_subject' => 'شارژ کیف پول',
    // [flow1] انقضای درخواستِ تغییرِ قیمت — به تأمین‌کننده
    'price_change_expired_subject' => 'انقضای درخواست تغییر قیمت',
    'transaction_subject' => 'نتیجهٔ تراکنش',

    // ── [flow2] تمدیدِ رزرو و لغوِ دوطرفه (پرسش ۳ و ۴ مدیریت) ────────────────
    'reserve_extension_proposed_subject' => 'پیشنهاد تمدید رزرو',
    'reserve_extension_accepted_subject' => 'تمدید رزرو پذیرفته شد',
    'reserve_extension_rejected_subject' => 'تمدید رزرو رد شد',
    'order_cancel_requested_subject' => 'درخواست لغو سفارش',
    'order_cancel_accepted_subject' => 'موافقت با لغو سفارش',
    'order_cancel_rejected_subject' => 'مخالفت با لغو سفارش',
    'order_cancel_escalated_subject' => 'ارجاع درخواست لغو به پشتیبانی',
    'order_cancel_admin_approved_subject' => 'تصمیم پشتیبانی: لغو سفارش',
    'order_cancel_admin_rejected_subject' => 'تصمیم پشتیبانی: ادامه‌ی سفارش',

    // درخواستِ تغییرِ کاتالوگ (محصول/واریانت) — به تأمین‌کننده
    'product_change_approved_subject' => 'تأیید تغییر کاتالوگ',
    'product_change_rejected_subject' => 'رد تغییر کاتالوگ',

    // تأمینِ تخفیفِ کوپن — به تأمین‌کننده/الیت‌یارِ پیشنهادشده و به سازنده‌ی کوپن
    'coupon_funding_requested_subject' => 'درخواست تأمین تخفیف',
    'coupon_funding_accepted_subject' => 'پذیرش تأمین تخفیف',
    'coupon_funding_declined_subject' => 'رد تأمین تخفیف',
];
