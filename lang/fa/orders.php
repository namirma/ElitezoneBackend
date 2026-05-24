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
    'hold_fee_successful' => 'هزینه تمدید با موفقیت پرداخت شد.',
    'cannot_finalize_invoice' => 'سفارش در وضعیت مناسب برای ثبت فاکتور نهایی نیست.',
    'finalize_invoice_success' => 'فاکتور نهایی با موفقیت ثبت شد.',

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
    'wallet_balance_low' => 'موجودی کیف پول کافی نیست.',
    'payment_redirect' => 'در حال انتقال به درگاه پرداخت...',
    'payment_success_wallet' => 'پرداخت با موفقیت از کیف پول انجام شد.',

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

    'errors' => [
        'cart_empty' => 'سبد خرید شما خالی است.',
        'stock_unavailable' => 'موجودی محصول «:product» کافی نیست.',
        'system_error' => 'خطای سیستمی رخ داده است (تامین‌کننده یافت نشد).',
        'not_payable' => 'این سفارش با وضعیت «:status» قابل پرداخت نیست.',
        'already_paid' => 'این سفارش قبلاً پرداخت شده است.',
        'gateway_not_implemented' => 'درگاه پرداخت انتخاب شده هنوز فعال نشده است.',
        'not_owner' => 'شما اجازه پرداخت این سفارش را ندارید.',
        'invalid_amount' => 'مبلغ سفارش معتبر نیست.',
    ],

    'messages' => [
        'cannot_cancel' => 'این سفارش در وضعیت فعلی قابل لغو نیست.',
    ],
];
