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
];
