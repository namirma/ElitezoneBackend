<?php

use App\Models\Order;

return [
    'cannot_cancel' => 'این سفارش در وضعیت قابل لغو نیست.',
    'cancelled_successfully' => 'سفارش با موفقیت لغو شد.',
    'invalid_invoice_type' => 'نوع فاکتور درخواستی نامعتبر است.',
    'invoice_not_found' => 'فاکتور مورد نظر یافت نشد یا هنوز صادر نشده است.',
    'invoice_download_link' => 'لینک دانلود فاکتور آماده شد.',
    'marked_as_prepared' => 'سفارش به عنوان «در حال آماده‌سازی» علامت‌گذاری شد.',
    'cannot_prepare_in_current_state' => 'امکان تایید سفارش (آماده‌سازی) در وضعیت فعلی وجود ندارد.',
    'supplier_rejected' => 'سفارش توسط تأمین‌کننده رد شد و مبلغ به خریدار بازگردانده شد.',
    'cannot_reject_in_current_state' => 'امکان رد سفارش در وضعیت فعلی وجود ندارد (فقط پیش از آماده‌سازی).',
    'waybill_added' => 'اطلاعات بارنامه با موفقیت ثبت شد و وضعیت سفارش به‌روز شد.',
    'cannot_add_waybill' => 'امکان ثبت بارنامه برای این سفارش در وضعیت فعلی وجود ندارد.',
    'espadana_command_issued' => 'دستور سفارش اسپادانا (به صورت آزمایشی) ثبت شد.',
    'receipt_attached' => 'رسید پرداخت (به صورت آزمایشی) ضمیمه شد.',
    'cannot_extend_reservation' => 'امکان تمدید رزرو برای این سفارش وجود ندارد.',
    'reservation_extended' => 'رزرو سفارش شما با موفقیت به مدت ۲۴ ساعت تمدید شد.',
    'cannot_pay_order' => 'امکان پرداخت این سفارش در وضعیت فعلی وجود ندارد.',
    'payment_successful_awaiting_confirmation' => 'پرداخت شما با موفقیت انجام شد و سفارش در انتظار تایید تامین‌کننده است.',
    'cannot_extend_reservation_status' => 'امکان تمدید رزرو برای این سفارش در وضعیت فعلی وجود ندارد.',
    'cannot_extend_reservation_expired' => 'زمان رزرو اولیه (۲ ساعته) این سفارش به پایان رسیده و امکان تمدید آن وجود ندارد.',
    'already_paid' => 'این سفارش قبلاً پرداخت شده است.',
    'receipt_uploaded_success' => 'فیش پرداخت با موفقیت ثبت شد و در انتظار بررسی است.',
    'completion_successful' => 'تکمیل وجه با موفقیت انجام شد.',
    'full_payment_successful' => 'پرداخت کامل با موفقیت انجام شد.',
    'reservation_successful' => 'سفارش با موفقیت رزرو شد.',
    'unknown_processing_error' => 'خطای ناشناخته در پردازش سفارش.',
    'payment_processing_error' => 'خطا در پردازش پرداخت. لطفاً دوباره تلاش کنید.',
    'hold_fee_successful' => 'هزینه تمدید با موفقیت پرداخت شد.',
    'cannot_finalize_invoice' => 'سفارش در وضعیت مناسب برای ثبت فاکتور نهایی نیست.',
    'finalize_invoice_success' => 'فاکتور نهایی با موفقیت ثبت شد.',
    'fee_hold_description' => 'سهم رزرو شده برای سفارش :order_number',

    // ── Doc Q: Consultant Moadian invoice ──────────────────────────────────
    'consultant_moadian_submitted' => 'فاکتور مودیان الیت یار ثبت شد و کمیسیون آزاد شد.',
    'consultant_moadian_already_done' => 'فاکتور مودیان الیت یار این سفارش قبلاً ثبت شده است.',

    // ── Doc O: Supplier Moadian invoice ────────────────────────────────────
    'supplier_moadian_submitted' => 'فاکتور مودیان سوپلایر ثبت شد و مالیات آزاد شد.',
    'supplier_moadian_already_done' => 'فاکتور مودیان سوپلایر این سفارش قبلاً ثبت شده است.',

    // ── Doc P: System (Saze) Moadian invoice ───────────────────────────────
    'system_moadian_submitted' => 'فاکتور مودیان سیستم ثبت شد و مالیات پلتفرم آزاد شد.',
    'system_moadian_already_done' => 'فاکتور مودیان سیستم این سفارش قبلاً ثبت شده است.',

    // ── Weighbridge (SubmitWeighbridgeRequest) ─────────────────────────────
    'weighbridge_weight_required' => 'وارد کردن وزن نهایی باسکول الزامی است.',
    'weighbridge_weight_numeric'  => 'وزن باسکول باید یک عدد معتبر باشد.',
    'weighbridge_weight_min'      => 'وزن باسکول نمی‌تواند کمتر از ۱ کیلوگرم باشد.',

    // ── PayFullOrder (PayFullOrderRequest) ─────────────────────────────────
    'payment_type_required' => 'نوع پرداخت الزامی است.',
    'payment_type_invalid'  => 'نوع پرداخت انتخاب‌شده معتبر نیست.',

    // ── Survey ─────────────────────────────────────────────────────────────
    'survey_not_allowed_for_status' => 'ثبت نظرسنجی فقط برای سفارش‌های تحویل‌داده‌شده امکان‌پذیر است.',
    'survey_already_submitted' => 'نظرسنجی این سفارش قبلاً ثبت شده است.',
    'survey_submitted_successfully' => 'نظرسنجی شما با موفقیت ثبت شد.',

    'status' => [
        Order::STATUS_PENDING => 'در انتظار تایید (رزرو شده)',
        Order::STATUS_RESERVED_EXTENDED => 'رزرو تمدید شده',
        Order::STATUS_PROCESSING => 'در حال پردازش (ثبت اولیه)',
        Order::STATUS_PREPARING => 'در حال آماده‌سازی',
        Order::STATUS_WEIGHBRIDGE_DONE => 'باسکول انجام شد (ثبت نهایی)',
        Order::STATUS_WAYBILL_ISSUED => 'بارنامه صادر شد',
        Order::STATUS_SHIPPED => 'در حال حمل',
        Order::STATUS_DELIVERED => 'تحویل شده',
        Order::STATUS_CANCELLED => 'لغو شده',
        Order::STATUS_FAILED => 'ناموفق',
        Order::PAYMENT_PENDING => 'در انتظار پرداخت',
        Order::PAYMENT_PAID => 'پرداخت شده',
        Order::FEE_PAYMENT_PENDING => 'در انتظار پرداخت سهم',
        Order::FEE_PAYMENT_PAID => 'سهم پرداخت شده',
        'reserved' => 'رزرو شده',
        'unknown' => 'نامشخص',
    ],

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
    'store_address_required'  => 'انتخاب آدرس گیرنده برای ثبت سفارش الزامی است.',
    'store_address_integer' => 'فرمت آدرس گیرنده نامعتبر است.',
    'store_address_exists' => 'آدرس انتخاب شده در سیستم یافت نشد یا متعلق به شما نیست.',
    'store_shipping_required' => 'انتخاب روش ارسال برای ثبت سفارش الزامی است.',
    'store_shipping_integer' => 'فرمت روش ارسال نامعتبر است.',
    'store_shipping_exists' => 'روش ارسال انتخاب شده در سیستم معتبر نیست.',
    'store_beneficiary_integer' => 'فرمت کاربر ذینفع نامعتبر است.',
    'store_beneficiary_exists' => 'کاربر ذینفع در سیستم یافت نشد.',
    // beneficiary validation (مسیر الیت یار)
    'store_beneficiary_not_approved_consultant'  => 'کاربر انتخاب‌شده الیت یار تأییدشدهٔ شما نیست.',
    'store_beneficiary_prohibited_for_employer'  => 'کارفرما نمی‌تواند فیلد گیرنده سفارش را مستقیم ارسال کند.',
    'store_beneficiary_required_for_consultant'  => 'الیت یار باید کارفرمای گیرنده سفارش را مشخص کند.',
    'store_beneficiary_not_approved_employer'    => 'کاربر انتخاب‌شده کارفرمای تأییدشدهٔ شما نیست.',

    // consultant_user_id validation (مسیر کارفرما)
    'store_consultant_integer'              => 'فرمت الیت یار نامعتبر است.',
    'store_consultant_exists'              => 'الیت یار در سیستم یافت نشد.',
    'store_consultant_not_approved'        => 'الیت یار انتخاب‌شده در لیست الیت یارهای تأییدشده شما نیست.',
    'store_consultant_not_found'           => 'الیت یار انتخاب‌شده در سیستم یافت نشد.',
    'store_consultant_not_verified'        => 'الیت یار انتخاب‌شده هنوز مدارک تأییدشده ندارد.',
    'store_consultant_prohibited_for_consultant' => 'الیت یار نمی‌تواند در هنگام ثبت سفارش الیت یار دیگری انتخاب کند.',

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
        'view_details' => 'مشاهده جزئیات',
        'final_invoice_settlement' => 'فاکتور نهایی (تسویه)',
        'get_final_invoice' => 'دریافت فاکتور نهایی',
        'view_waybill' => 'مشاهده بارنامه',
        'view_shipping_details' => 'مشاهده جزئیات حمل',
        'get_tax_invoice' => 'دریافت فاکتور رسمی',
    ],

    // ── Internal action exceptions (user-visible via exception handler) ────
    'internal_order_not_found'        => 'خطای داخلی: سفارش در فرآیند پردازش یافت نشد.',
    'items_missing_for_payment'       => 'اقلام سفارش برای پردازش مالی تعریف نشده‌اند.',
    'items_missing_for_completion'    => 'اقلام سفارش برای تکمیل رزرو یافت نشد.',
    'consultant_not_found_internal'   => 'خطای داخلی: الیت یار این سفارش یافت نشد.',
    'reserved_cannot_cancel_before_expiry' => 'سفارشات رزرو شده تا پیش از پایان زمان انقضا قابل لغو نیستند.',
    'reserved_expired_auto_cancel'    => 'سفارشات رزرو شده منقضی، تنها توسط سیستم به صورت خودکار لغو می‌شوند.',
    'invalid_financial_calculation'   => 'محاسبه مالی سفارش معتبر نیست.',
    'invalid_transaction_status'      => 'وضعیت جدید تراکنش نامعتبر است.',
    'debit_amount_must_be_positive'   => 'مبلغ برداشت باید بیشتر از صفر باشد.',
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
        'no_consultant' => 'این سفارش الیت یار ندارد.',
        'moadian_ref_required' => 'شماره فاکتور مودیان الزامی است.',
    ],

    'messages' => [
        'cannot_cancel' => 'این سفارش در وضعیت فعلی قابل لغو نیست.',
    ],
];
