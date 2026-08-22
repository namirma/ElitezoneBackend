<?php

/**
 * پارامترهای مسیر فروش — پیش‌فرض‌ها.
 *
 * این‌ها **فالبک** هستند، نه منبع حقیقت. منبع حقیقت جدول `settings` است تا
 * بک‌آفیس بتواند بدون دیپلوی عوضشان کند؛ هر شش کلید در
 * `config/settings.php → allowed_keys` ثبت شده‌اند. وقتی کلیدی در جدول نباشد،
 * `SalesSettings` به مقدار همین فایل برمی‌گردد.
 *
 * ⚠️ هرگز مستقیم `config('sales.*')` را در دامنه نخوانید — همیشه از
 * `SalesSettings` که تنظیمِ ادمین را هم می‌بیند. خواندنِ مستقیمِ config یعنی
 * نادیده‌گرفتنِ هر تغییری که ادمین داده.
 *
 * مبالغ به **ریال** است (نه تومان) — قاعدهٔ سراسری پروژه.
 */
return [

    /*
    | سقف یک تراکنش درگاه؛ سقف شارژ کیف پول هم همین است.
    |
    | ۲,۰۰۰,۰۰۰,۰۰۰ ریال = ۲۰۰ میلیون تومان — همان سقفِ per-payment درگاه که
    | `financial.reservation_threshold` هم به آن گره خورده بود. سند بازطراحی
    | عدد ۲۰ میلیارد ریال نوشته بود که یک صفرِ اضافه است؛ با تأیید تیم اصلاح شد.
    */
    'shaparak_value' => (float) env('SALES_SHAPARAK_VALUE_RIALS', 2_000_000_000),

    /*
    | مبلغ بیعانه. اعتبارسنجی: ≤ shaparak_value — هم در فرمِ ادمین و هم در
    | لحظهٔ خواندن (SalesSettings::reserveValue) clamp می‌شود.
    */
    'reserve_value' => (float) env('SALES_RESERVE_VALUE_RIALS', 2_000_000_000),

    /* مهلت نگهداری سبد، به دقیقه. */
    'park_time_minutes' => (int) env('SALES_PARK_TIME_MINUTES', 120),

    /* ارفاق پس از انقضای رزرو، پیش از لغو — به ساعت. */
    'reserve_grace_hours' => (int) env('SALES_RESERVE_GRACE_HOURS', 5),

    /* حداکثر اضافه‌بار مجاز نسبت به **کل** سفارش، به درصد. */
    /*
     | مهلت تأیید تحویل توسط خریدار، بر حسب ساعت (بند ۳.۱۰ / ۴.۵).
     |
     | per-shipment روی `shipments.delivery_deadline_hours` اسنپ‌شات می‌شود؛ این
     | فقط مقدارِ پیش‌فرض است. با پایانِ مهلت، محموله به مبدأ عودت می‌شود.
     */
    'delivery_deadline_hours' => (int) env('SALES_DELIVERY_DEADLINE_HOURS', 5),

    'max_overweight_percent' => (float) env('SALES_MAX_OVERWEIGHT_PERCENT', 20),

    /*
    | مهلت پاسخ به درخواست تأیید اضافه‌بار، به ساعت (بند ۳.۷).
    |
    | با گذشتن مهلت، محموله باطل و سفارش به `processing` بازمی‌گردد. هیچ
    | تسویه‌ای برنمی‌گردد چون در این شاخه اصلاً لِگ مالی‌ای اجرا نشده.
    */
    'overweight_response_hours' => (int) env('SALES_OVERWEIGHT_RESPONSE_HOURS', 48),

    /*
    | آستانه‌ی هشدارِ «رزرو دیرهنگام» (الحاقیه‌ی ۱، تغییر ۱).
    |
    | اگر فاصله تا `reserve_time` تأمین‌کننده از این کمتر باشد، پیش از تأیید
    | رزرو به خریدار هشدار داده می‌شود. **کاملاً مستقل از**
    | `reserve_auto_extend_minutes` است: این فقط تعیین می‌کند کِی پیام داده شود
    | و هیچ اثری روی خودِ مهلت ندارد.
    */
    'reserve_warning_threshold_minutes' => (int) env('SALES_RESERVE_WARNING_THRESHOLD_MINUTES', 180),

    /*
    | نمایش/اعمال کوپن: all | none | selected
    | در حالت `selected` معیار ستون `users.coupon_enabled` است.
    */
    'coupon_visibility' => env('SALES_COUPON_VISIBILITY', 'all'),

];
