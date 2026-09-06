<?php

use App\Constants\FinancialCodes;
use App\Models\Order;

/*
| برچسبِ وضعیتِ چرخهٔ عمرِ سفارش — **یک** تعریف، دو نامِ صادراتی.
|
| هر دو تیم مستقلاً همان باگ را پیدا کردند (آرایهٔ `status` هم‌زمان وضعیتِ سفارش،
| وضعیتِ پرداخت و وضعیتِ پرداختِ سهم را نگه می‌داشت و چون `pending`/`paid`/`failed`
| در بیش از یک خانواده تکرار می‌شوند، کلیدها در PHP روی هم می‌افتادند: ۱۶ اعلام،
| ۱۳ resolve). ما `status` را به سه آرایهٔ مستقل شکستیم، تیم بک‌اند آرایهٔ جدیدِ
| `order_status` را اضافه کرد. هر دو درست‌اند و کدِ هر دو طرف زنده است، پس هر دو
| کلید باید کار کند — ولی **دو آرایهٔ هم‌محتوا دیر یا زود از هم واگرا می‌شوند**.
| این متغیر تنها منبع است؛ هیچ‌کدام از دو کلید را جداگانه ویرایش نکنید.
*/
$orderStatusLabels = [
    Order::STATUS_RESERVED => 'رزرو شده',
    Order::STATUS_PROCESSING => 'در حال آماده‌سازی',
    Order::STATUS_AWAITING_WEIGHT_CORRECTION => 'در انتظار اصلاح وزن',
    Order::STATUS_SHIPPED => 'در حال حمل',
    Order::STATUS_PARTIALLY_DELIVERED => 'تحویل جزئی',
    Order::STATUS_DELIVERED => 'تحویل شده',
    Order::STATUS_RETURNED_TO_ORIGIN => 'عودت به مبدأ',
    Order::STATUS_CANCELLED => 'لغو شده',
];

return [
    'multiple_origins_not_supported' => 'هر سفارش فقط از یک مبدأ ارسال می‌شود. کالاهای هر انبار را در سفارش جداگانه ثبت کنید.',
    'multiple_destinations_not_supported' => 'هر سفارش فقط به یک مقصد ارسال می‌شود. کالاهای هر مقصد را در سفارش جداگانه ثبت کنید.',
    'blocked_by_debt' => 'به دلیل بدهی معوق امکان ثبت سفارش جدید وجود ندارد. لطفاً ابتدا بدهی خود را تسویه کنید.',

    'cannot_cancel' => 'این سفارش در وضعیت قابل لغو نیست.',
    'cancelled_successfully' => 'سفارش با موفقیت لغو شد.',
    'invalid_invoice_type' => 'نوع فاکتور درخواستی نامعتبر است.',
    'invoice_not_found' => 'فاکتور مورد نظر یافت نشد یا هنوز صادر نشده است.',
    'invoice_download_link' => 'لینک دانلود فاکتور آماده شد.',
    'espadana_command_issued' => 'دستور سفارش اسپادانا (به صورت آزمایشی) ثبت شد.',
    'espadana_already_issued' => 'دستور سفارش اسپادانا قبلاً برای این سفارش ثبت شده است.',
    'cannot_issue_espadana' => 'امکان ثبت دستور اسپادانا برای این سفارش در وضعیت فعلی وجود ندارد.',
    'receipt_attached' => 'رسید پرداخت (به صورت آزمایشی) ضمیمه شد.',
    'cannot_attach_receipt_unpaid' => 'تا پیش از پرداخت سفارش، امکان ضمیمه کردن رسید وجود ندارد.',
    'cannot_pay_order' => 'امکان پرداخت این سفارش در وضعیت فعلی وجود ندارد.',
    'payment_successful_awaiting_confirmation' => 'پرداخت شما با موفقیت انجام شد و سفارش در انتظار تایید تامین‌کننده است.',
    'already_paid' => 'این سفارش قبلاً پرداخت شده است.',
    'receipt_uploaded_success' => 'فیش پرداخت با موفقیت ثبت شد و در انتظار بررسی است.',
    'completion_successful' => 'تکمیل وجه با موفقیت انجام شد.',
    'full_payment_successful' => 'پرداخت کامل با موفقیت انجام شد.',
    'reservation_successful' => 'سفارش با موفقیت رزرو شد.',
    'unknown_processing_error' => 'خطای ناشناخته در پردازش سفارش.',
    'payment_processing_error' => 'خطا در پردازش پرداخت. لطفاً دوباره تلاش کنید.',
    'cannot_finalize_invoice' => 'سفارش در وضعیت مناسب برای ثبت فاکتور نهایی نیست.',
    'finalize_invoice_success' => 'فاکتور نهایی با موفقیت ثبت شد.',
    /*
    | مهلت رزرو (بند ۳.۴ + الحاقیه‌ی ۱).
    */
    'reserve_expired_cannot_complete' => 'مهلت رزرو و بازه‌ی ارفاق این سفارش به پایان رسیده و امکان تکمیل وجه وجود ندارد.',
    'reserve_expired_cancelled' => 'مهلت رزرو و بازه‌ی ارفاق به پایان رسید و سفارش لغو شد.',
    'reserve_deadline' => [
        'cannot_shorten' => 'مهلت رزرو را فقط می‌توان تمدید کرد، نه کوتاه.',
        'extended' => 'مهلت رزرو با موفقیت تمدید شد.',
        'required' => 'اعلام مهلت جدید الزامی است.',
        'invalid' => 'قالب مهلت جدید معتبر نیست.',
        'must_be_future' => 'مهلت جدید باید در آینده باشد.',
        // پنجره‌ی لغو خریدار فقط بازه‌ای است که تأمین‌کننده اضافه کرده.
        'cancel_window_closed' => 'در حال حاضر امکان لغو این رزرو وجود ندارد؛ لغو تنها در بازه‌ی تمدیدشده توسط تأمین‌کننده ممکن است.',
    ],

    /*
    | هشدارِ رزرو دیرهنگام (الحاقیه‌ی ۱، تغییر ۲).
    |
    | متن باید **هر دو** نکته را بگوید: وقت کم برای تکمیل پرداخت، و اینکه تمدید
    | تنها با پذیرش تأمین‌کننده ممکن است — نه خودکار و نه با پرداخت هزینه.
    */
    'reserve_warning' => [
        'short_window' => 'اعتبار قیمت‌های این تأمین‌کننده تا :minutes دقیقه دیگر به پایان می‌رسد. اگر رزرو کنید، برای تکمیل پرداخت همین اندازه فرصت دارید و تمدید مهلت تنها با پذیرش تأمین‌کننده ممکن است.',
        'confirmation_required' => 'برای رزرو در این بازه‌ی کوتاه، باید تأیید کنید که از محدودیت زمانی آگاه هستید.',
    ],

    'fee_hold_description' => 'سهم رزرو شده برای سفارش :order_number',

    // ── Doc Q: EliteYAR Moadian invoice ──────────────────────────────────
    'elite_yar_moadian_submitted' => 'فاکتور مودیان الیت یار ثبت شد و کمیسیون آزاد شد.',
    'elite_yar_moadian_already_done' => 'فاکتور مودیان الیت یار این سفارش قبلاً ثبت شده است.',

    // ── Doc O: Supplier Moadian invoice ────────────────────────────────────
    'supplier_moadian_submitted' => 'فاکتور مودیان سوپلایر ثبت شد و مالیات آزاد شد.',
    'supplier_moadian_already_done' => 'فاکتور مودیان سوپلایر این سفارش قبلاً ثبت شده است.',

    // ── Doc P: System (Saze) Moadian invoice ───────────────────────────────
    'system_moadian_submitted' => 'فاکتور مودیان سیستم ثبت شد و مالیات پلتفرم آزاد شد.',
    'system_moadian_already_done' => 'فاکتور مودیان سیستم این سفارش قبلاً ثبت شده است.',

    // ── Weighbridge (SubmitWeighbridgeRequest) ─────────────────────────────
    'weighbridge_weight_required' => 'وارد کردن وزن نهایی باسکول الزامی است.',
    'weighbridge_weight_numeric' => 'وزن باسکول باید یک عدد معتبر باشد.',
    'weighbridge_weight_min' => 'وزن باسکول نمی‌تواند کمتر از ۱ کیلوگرم باشد.',

    // ── Survey ─────────────────────────────────────────────────────────────
    'survey_not_allowed_for_status' => 'ثبت نظرسنجی فقط برای سفارش‌های تحویل‌داده‌شده امکان‌پذیر است.',
    'survey_already_submitted' => 'نظرسنجی این سفارش قبلاً ثبت شده است.',
    'survey_submitted_successfully' => 'نظرسنجی شما با موفقیت ثبت شد.',

    /*
    | نامِ خوانای اسنادِ مالی. کدها در App\Constants\FinancialCodes تعریف
    | شده‌اند و در ستون `invoices.document_code` ذخیره می‌شوند؛ «سند LO» به‌تنهایی
    | برای کسی که سندِ طراحی را ندیده معنایی ندارد.
    */
    'document_codes' => [
        FinancialCodes::DOC_PRE_INVOICE => 'پیش‌فاکتور خریدار',
        FinancialCodes::DOC_RECEIPT_OWNER => 'رسید پرداخت خریدار',
        FinancialCodes::DOC_REPORT_SUPPLIER => 'گزارش پرداخت برای تأمین‌کننده',
        FinancialCodes::DOC_LOADING_ORDER => 'دستور بارگیری',
        FinancialCodes::DOC_ELITE_YAR_REPORT => 'گزارش پرداخت به الیت یار',
        FinancialCodes::DOC_RESERVE_RECEIPT => 'رسید پرداخت رزرو',
        FinancialCodes::DOC_RESERVE_REPORT => 'گزارش رزرو برای تأمین‌کننده',
        FinancialCodes::DOC_FINAL_RECEIPT_OWNER => 'رسید تکمیل پرداخت خریدار',
        FinancialCodes::DOC_FINAL_REPORT_SUPPLIER => 'گزارش تکمیل پرداخت برای تأمین‌کننده',
        FinancialCodes::DOC_REFUND_RECEIPT => 'رسید بازگشت مبلغ رزرو',
        FinancialCodes::DOC_CANCEL_REPORT => 'گزارش لغو رزرو',
        FinancialCodes::DOC_BILL_OF_LADING => 'بارنامه / مجوز خروج',
        FinancialCodes::DOC_MODIFIED_INVOICE_OWNER => 'فاکتور اصلاح‌شده خریدار (وزن بارنامه)',
        FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER => 'فاکتور اصلاح‌شده تأمین‌کننده (وزن بارنامه)',
        FinancialCodes::DOC_TAX_INVOICE_SUPPLIER => 'فاکتور مودیان: تأمین‌کننده ← سازه',
        FinancialCodes::DOC_TAX_INVOICE_BUYER => 'فاکتور مودیان: سازه ← خریدار',
        FinancialCodes::DOC_TAX_INVOICE_ELITE_YAR => 'فاکتور مودیان: الیت یار ← سازه',
        FinancialCodes::DOC_SURVEY => 'نظرسنجی خریدار',
        FinancialCodes::DOC_SURVEY_ELITE_YAR => 'نظرسنجی الیت یار',
    ],

    /*

    | ⚠️ این آرایه هنوز سه واژگان را با هم نگه می‌دارد (وضعیت سفارش + وضعیت
    | پرداخت + وضعیت پرداخت سهم) و چون `pending`/`paid` در بیش از یک خانواده
    | تکرار می‌شوند، ردیف‌های بعدی روی قبلی‌ها می‌افتند. شاخهٔ L10 این را به سه
    | آرایهٔ مستقل شکسته؛ اینجا عمداً دست‌نخورده مانده تا این کامیت فقط کارِ
    | فاز ۰ (مجموعهٔ وضعیت‌ها) را انجام دهد. جداسازی، پورتِ جداگانهٔ خودش را دارد.
    |
    | برچسب‌های وضعیت سفارش از `$orderStatusLabels` بالای همین فایل می‌آیند تا
    | همان تک‌منبع دو شاخه را هم‌راستا نگه دارد.
    |
    | `array_merge` است و نه `+`، چون تقدم روی کلیدهای مشترک باید دقیقاً همان
    | چیزی بماند که بود: `OrderResource` برچسبِ `payment_status` را هم از همین
    | آرایه می‌خواند، پس روی `pending` باید ردیفِ پرداخت برنده شود. با `+` طرفِ
    | چپ برنده می‌شد و «در انتظار پرداخت» بی‌صدا به «در انتظار تایید» تغییر می‌کرد.
    */
    /*
    | برچسبِ وضعیتِ *سفارش* — فقط و فقط وضعیت‌های چرخهٔ سفارش.
    |
    | جدا از آرایهٔ `status` پایین است، چون آن یکی هم‌زمان وضعیتِ سفارش، وضعیتِ
    | پرداخت و وضعیتِ پرداختِ سهم را در یک فضای نام نگه می‌دارد و این سه کلیدهای
    | مشترک دارند (`pending`، `paid`، `failed`). چون در آرایهٔ PHP کلیدِ بعدی روی
    | قبلی می‌نشیند، `orders.status.pending` عملاً «در انتظار پرداخت سهم» می‌شود
    | نه «در انتظار تایید». هر جا فقط وضعیتِ سفارش لازم است باید از این آرایه
    | خوانده شود — `OrderTimelineService` همین کار را می‌کند.
    |
    | همان `$orderStatusLabels` است، نه یک کپیِ دوم: شاخهٔ L10 اینجا هشت وضعیتِ
    | خودش را دوباره فهرست می‌کند و بعضی از آن‌ها (`preparing`،
    | `weighbridge_done`، `reserved_extended`) روی این شاخه اصلاً وجود ندارند.
    */
    'order_status' => $orderStatusLabels,

    'status' => array_merge($orderStatusLabels, [
        Order::PAYMENT_PENDING => 'در انتظار پرداخت',
        Order::PAYMENT_PAID => 'پرداخت شده',
        Order::FEE_PAYMENT_PENDING => 'در انتظار پرداخت سهم',
        Order::FEE_PAYMENT_PAID => 'سهم پرداخت شده',
        'unknown' => 'نامشخص',
    ]),

    'receipt_required' => 'آپلود فایل رسید الزامی است.',
    'receipt_mimes' => 'فرمت فایل باید jpg، jpeg، png یا pdf باشد.',
    'receipt_max' => 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.',
    'waybill_required' => 'وارد کردن شماره بارنامه الزامی است.',
    'gateway_required' => 'انتخاب درگاه پرداخت الزامی است.',
    'gateway_invalid' => 'درگاه انتخاب شده معتبر نیست.',
    'payment_type_required' => 'نوع پرداخت الزامی است.',
    'payment_type_invalid' => 'نوع پرداخت انتخاب‌شده معتبر نیست. مقادیر مجاز: pay یا reserve.',
    'wallet_balance_low' => 'موجودی کیف پول کافی نیست.',
    'payment_redirect' => 'در حال انتقال به درگاه پرداخت...',
    'payment_success_wallet' => 'پرداخت با موفقیت از کیف پول انجام شد.',

    // StoreOrderRequest validation messages
    'store_address_required' => 'انتخاب آدرس گیرنده برای ثبت سفارش الزامی است.',
    'store_address_integer' => 'فرمت آدرس گیرنده نامعتبر است.',
    'store_address_exists' => 'آدرس انتخاب شده در سیستم یافت نشد یا متعلق به شما نیست.',
    'store_shipping_required' => 'انتخاب روش ارسال برای ثبت سفارش الزامی است.',
    'store_shipping_integer' => 'فرمت روش ارسال نامعتبر است.',
    'store_shipping_exists' => 'روش ارسال انتخاب شده در سیستم معتبر نیست.',
    'store_beneficiary_integer' => 'فرمت کاربر ذینفع نامعتبر است.',
    'store_beneficiary_exists' => 'کاربر ذینفع در سیستم یافت نشد.',
    // beneficiary validation (مسیر الیت یار)
    'store_beneficiary_not_approved_elite_yar' => 'کاربر انتخاب‌شده الیت یار تأییدشدهٔ شما نیست.',
    'store_beneficiary_prohibited_for_employer' => 'کارفرما نمی‌تواند فیلد گیرنده سفارش را مستقیم ارسال کند.',
    'store_beneficiary_required_for_elite_yar' => 'الیت یار باید کارفرمای گیرنده سفارش را مشخص کند.',
    'store_beneficiary_not_approved_employer' => 'کاربر انتخاب‌شده کارفرمای تأییدشدهٔ شما نیست.',

    // elite_yar_user_id validation (مسیر کارفرما)
    'store_elite_yar_integer' => 'فرمت الیت یار نامعتبر است.',
    'store_elite_yar_exists' => 'الیت یار در سیستم یافت نشد.',
    'store_elite_yar_not_approved' => 'الیت یار انتخاب‌شده در لیست الیت یاران تأییدشده شما نیست.',
    'store_elite_yar_not_found' => 'الیت یار انتخاب‌شده در سیستم یافت نشد.',
    'store_elite_yar_not_verified' => 'الیت یار انتخاب‌شده هنوز مدارک تأییدشده ندارد.',
    'store_elite_yar_prohibited_for_elite_yar' => 'الیت یار نمی‌تواند در هنگام ثبت سفارش الیت یار دیگری انتخاب کند.',

    'store_description_max' => 'توضیحات سفارش نمی‌تواند بیشتر از 1000 کاراکتر باشد.',

    /*
    |------------------------------------------------------------------------
    | عناوین مراحل نوار پیشرفت — سه مخاطب، سه فهرست
    |------------------------------------------------------------------------
    | جایگزینِ آرایهٔ عددیِ قدیمیِ `steps` که به هیچ‌جا وصل نبود و عنوان‌هایش
    | («ثبت نهایی بعد از باسکول»، «تکمیل شده») از مدلِ پیش از بازطراحی مانده بود.
    |
    | کلیدها با `$stepsBlueprint[...]['key']` در HasOrderProgress یکی‌اند و
    | **منطقِ کد روی همان کلید شاخه می‌زند، نه روی عنوان** — تا عوض‌کردنِ یک
    | برچسبِ فارسی هرگز یک `if` را خاموش نکند.
    */
    'progress' => [
        'buyer' => [
            'reserved' => 'سفارش رزرو شد',
            'placed' => 'سفارش ثبت شد',
            'shipped' => 'بارنامه صادر و وزن تأیید شد',
            'delivered' => 'تحویل شد',
            'moadian' => 'فاکتور سامانه مودیان صادر شد',
        ],
        'supplier' => [
            'reserved' => 'رزرو سفارش ثبت شد',
            'received' => 'سفارش دریافت شد',
            'shipped' => 'بارنامه صادر و کالا ارسال شد',
            'settled' => 'تحویل و تسویه شد',
            'moadian' => 'فاکتور مودیان ثبت شد',
        ],
        'elite_yar' => [
            'purchased' => 'کارفرما خرید کرد',
            'commission_booked' => 'کارمزد الیت یار منظور شد',
            'invoice_submitted' => 'فاکتور خدمات ثبت شد',
            'commission_available' => 'کارمزد الیت یار در دسترس است',
        ],
    ],

    // ----------------------------------------------------
    // ترجمه دکمه‌ها و لینک‌ها
    // ----------------------------------------------------
    'actions' => [
        'pay_invoice' => 'پرداخت فاکتور',
        'get_receipt' => 'دریافت رسید',
        'download_initial_invoice' => 'دانلود فاکتور اولیه',
        // سندِ «فاکتور» برای هر طرفِ معامله فرق می‌کند؛ برچسبش هم باید فرق کند.
        'download_supplier_report' => 'دانلود گزارش تأمین‌کننده',
        'download_elite_yar_report' => 'دانلود گزارش الیت یار',
        'view_details' => 'مشاهده جزئیات',
        'final_invoice_settlement' => 'فاکتور نهایی (تسویه)',
        'get_final_invoice' => 'دریافت فاکتور نهایی',
        'view_waybill' => 'مشاهده بارنامه',
        'view_shipping_details' => 'مشاهده جزئیات حمل',
        'get_tax_invoice' => 'دریافت فاکتور رسمی',

        // ── دکمه‌های نوار پیشرفت (بازطراحی، بند ۱۱) ──────────────────────
        'buy_again' => 'خرید مجدد',
        'paid_initial_invoice' => 'فاکتور اولیه پرداخت شده',
        'final_invoice' => 'فاکتور نهایی',
        'waybill_and_driver' => 'بارنامه و راننده',
        'confirm_delivery' => 'تأیید تحویل',
        'download_moadian_invoice' => 'دانلود فاکتور مودیان',
        'download_reserve_report' => 'دانلود گزارش رزرو',
        'download_loading_order' => 'دانلود دستور بارگیری',
        'download_exit_permit' => 'دانلود مجوز خروج',
        'submit_moadian_invoice' => 'ثبت فاکتور مودیان',
        'view_wallet' => 'مشاهده کیف پول',
        'download_purchased_invoice' => 'دانلود فاکتور کالای خریداری شده',
        'submit_service_invoice' => 'فاکتور خدمات خود را ثبت کنید',
    ],

    // ── Internal action exceptions (user-visible via exception handler) ────
    'internal_order_not_found' => 'خطای داخلی: سفارش در فرآیند پردازش یافت نشد.',
    'items_missing_for_payment' => 'اقلام سفارش برای پردازش مالی تعریف نشده‌اند.',
    'items_missing_for_completion' => 'اقلام سفارش برای تکمیل رزرو یافت نشد.',
    'elite_yar_not_found_internal' => 'خطای داخلی: الیت یار این سفارش یافت نشد.',
    'reserved_cannot_cancel_before_expiry' => 'سفارشات رزرو شده تا پیش از پایان زمان انقضا قابل لغو نیستند.',
    'reserved_expired_auto_cancel' => 'سفارشات رزرو شده منقضی، تنها توسط سیستم به صورت خودکار لغو می‌شوند.',
    'invalid_financial_calculation' => 'محاسبه مالی سفارش معتبر نیست.',
    'invalid_weight_snapshot' => 'وزن ثبت‌شده سفارش معتبر نیست؛ عملیات مالی متوقف شد. با پشتیبانی تماس بگیرید.',
    // ‏`blocked_by_debt`، `multiple_origins_not_supported` و
    // `multiple_destinations_not_supported` روی این شاخه بالای همین فایل
    // هستند؛ تکرارشان اینجا یک کلیدِ دوباره در آرایه می‌ساخت.
    'multiple_suppliers_not_supported' => 'سبد خرید شما شامل کالاهایی از چند تأمین‌کننده است. در حال حاضر هر سفارش باید از یک تأمین‌کننده باشد؛ لطفاً کالاهای هر تأمین‌کننده را در سفارش جداگانه ثبت کنید.',
    'invalid_official_price' => 'قیمت ثبت‌شده سفارش معتبر نیست؛ عملیات مالی متوقف شد. با پشتیبانی تماس بگیرید.',
    'debt_nothing_to_repay' => 'بدهی جاری برای تسویه ندارید.',
    'debt_repay_amount_min' => 'مبلغ بازپرداخت باید بیشتر از صفر باشد.',
    'debt_repay_description' => 'بازپرداخت بدهی از کیف پول',
    'debt_repaid' => 'بدهی شما با موفقیت تسویه شد.',

    // ── تأیید دومرحله‌ای اضافه‌بار (بند ۳.۷) ──────────────────────────────
    'overweight_no_open_request' => 'درخواست تأیید اضافه‌باری برای این سفارش باز نیست.',
    'overweight_wrong_status' => 'این سفارش در وضعیت انتظار اصلاح وزن نیست.',
    'overweight_already_supplier_approved' => 'تأمین‌کننده پیش‌تر این اضافه‌بار را تأیید کرده است.',
    'overweight_supplier_first' => 'تا پیش از تأیید تأمین‌کننده، امکان پذیرش اضافه‌بار وجود ندارد.',
    'overweight_missing_weight' => 'وزن باسکول روی این سفارش ثبت نشده؛ تعدیل قابل ادامه نیست.',
    'overweight_supplier_approved' => 'اضافه‌بار توسط تأمین‌کننده تأیید شد و برای خریدار ارسال گردید.',
    'overweight_buyer_approved' => 'اضافه‌بار پذیرفته و تعدیل مالی اعمال شد.',
    'overweight_rejected' => 'درخواست اضافه‌بار رد شد؛ بارنامه باطل و سفارش به آماده‌سازی بازگشت.',
    'overweight_no_shortfall' => 'این سفارش بدهی اضافه‌بارِ در انتظار پرداخت ندارد.',
    'overweight_shortfall_settled' => 'بدهی اضافه‌بار تسویه شد و بار آزاد گردید.',

    'invalid_transaction_status' => 'وضعیت جدید تراکنش نامعتبر است.',
    'debit_amount_must_be_positive' => 'مبلغ برداشت باید بیشتر از صفر باشد.',
    'reserve_only_on_reserved_orders' => 'تنها سفارش‌های رزرو شده قابل تمدید هستند.',

    // ── Errors (merged — single block) ─────────────────────────────────────
    'errors' => [
        // Financial / validation
        'invalid_amount' => 'مبلغ سفارش معتبر نیست.',
        'stock_unavailable' => 'موجودی محصول «:product» کافی نیست.',
        'system_error' => 'خطای سیستمی رخ داده است (تامین‌کننده یافت نشد).',
        'not_payable' => 'این سفارش با وضعیت «:status» قابل پرداخت نیست.',
        'already_paid' => 'این سفارش قبلاً پرداخت شده است.',
        'gateway_not_implemented' => 'درگاه پرداخت انتخاب شده هنوز فعال نشده است.',
        'not_owner' => 'شما اجازه پرداخت این سفارش را ندارید.',
        'cart_empty' => 'سبد خرید شما خالی است.',
        // Moadian / post-weighbridge
        'weighbridge_not_completed' => 'نتیجه باسکول برای این سفارش هنوز ثبت نشده است.',
        'no_elite_yar' => 'این سفارش الیت یار ندارد.',
        'moadian_ref_required' => 'شماره فاکتور مودیان الزامی است.',
    ],

    'messages' => [
        'cannot_cancel' => 'این سفارش در وضعیت فعلی قابل لغو نیست.',
    ],
];
