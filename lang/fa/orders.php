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
    Order::STATUS_PENDING => 'در انتظار تایید (رزرو شده)',
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

    // ── PayFullOrder (PayFullOrderRequest) ─────────────────────────────────
    'payment_type_required' => 'نوع پرداخت الزامی است.',
    'payment_type_invalid' => 'نوع پرداخت انتخاب‌شده معتبر نیست.',

    // ── Survey ─────────────────────────────────────────────────────────────
    'survey_not_allowed_for_status' => 'ثبت نظرسنجی فقط برای سفارش‌های تحویل‌داده‌شده امکان‌پذیر است.',
    'survey_already_submitted' => 'نظرسنجی این سفارش قبلاً ثبت شده است.',
    'survey_submitted_successfully' => 'نظرسنجی شما با موفقیت ثبت شد.',

    /*
    | نامِ خوانای اسنادِ مالی. کدها در App\Constants\FinancialCodes تعریف شده‌اند و
    | در ستون `invoices.document_code` ذخیره می‌شوند؛ «سند LO» به‌تنهایی برای کسی
    | که سندِ طراحی را ندیده معنایی ندارد.
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
    |--------------------------------------------------------------------------
    | وضعیت‌ها — سه واژگانِ مستقل، سه آرایه‌ی مستقل
    |--------------------------------------------------------------------------
    |
    | `order_status` و `status` هر دو از `$orderStatusLabels` بالای همین فایل
    | می‌آیند (تعریفِ واحد؛ توضیحِ کامل همان‌جاست). `status` علاوه بر آن یک
    | فالبکِ `unknown` دارد چون مصرف‌کننده‌های قدیمی‌تر روی مقدارِ ناشناخته به آن
    | تکیه می‌کنند.
    |
    | هر آرایه فقط مقادیرِ ستونِ خودش را دارد. کلیدها را با هم قاطی نکنید.
    */
    'order_status' => $orderStatusLabels,

    // ستون `orders.status` — چرخه‌ی عمر سفارش
    'status' => $orderStatusLabels + ['unknown' => 'نامشخص'],

    // ستون `orders.payment_status`
    // (سه مقدارِ failed/refund_pending/refunded تا امروز هیچ برچسبی نداشتند و
    //  خودِ کلیدِ انگلیسی به کاربر نشان داده می‌شد.)
    'payment_status' => [
        Order::PAYMENT_PENDING => 'در انتظار پرداخت',
        Order::PAYMENT_PAID => 'پرداخت شده',
        Order::PAYMENT_FAILED => 'پرداخت ناموفق',
        Order::PAYMENT_REFUND_PENDING => 'در انتظار بازگشت وجه',
        Order::PAYMENT_REFUNDED => 'وجه بازگردانده شد',
        'unknown' => 'نامشخص',
    ],

    // ستون‌های `expert_/engineer_/contractor_fee_payment_status` (نام ستون، تغییر نکرده) — سهم کمیسیون
    'fee_payment_status' => [
        Order::FEE_PAYMENT_PENDING => 'در انتظار پرداخت سهم',
        Order::FEE_PAYMENT_PAID => 'سهم پرداخت شده',
        'unknown' => 'نامشخص',
    ],

    // فونت‌های فارسیِ تولید PDF نصب نیستند (فایل‌های storage/fonts در gitignore هستند)
    'invoice_fonts_missing' => 'صدور سند در حال حاضر امکان‌پذیر نیست. لطفاً چند دقیقه بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',

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

    // ----------------------------------------------------
    // ترجمه مراحل نوار پیشرفت
    // ----------------------------------------------------
    'steps' => [
        1 => 'رزرو سفارش',
        2 => 'ثبت اولیه پس از پرداخت',
        3 => 'ثبت نهایی بعد از باسکول',
        4 => 'در حال حمل',
        5 => 'تکمیل شده',
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
    'invalid_official_price' => 'قیمت ثبت‌شده سفارش معتبر نیست؛ عملیات مالی متوقف شد. با پشتیبانی تماس بگیرید.',
    'multiple_suppliers_not_supported' => 'سبد خرید شما شامل کالاهایی از چند تأمین‌کننده است. در حال حاضر هر سفارش باید از یک تأمین‌کننده باشد؛ لطفاً کالاهای هر تأمین‌کننده را در سفارش جداگانه ثبت کنید.',
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
