<?php

return [
    'received_successfully' => 'اطلاعات با موفقیت دریافت شد.',
    // ۴۰۳های عمومی — قبلاً این دو کلید وجود نداشتند و چون __() کلیدِ پیدانشده را
    // عیناً برمی‌گرداند، بدنه‌ی پاسخ واقعاً «global.forbidden» بود.
    'forbidden' => 'شما به این بخش دسترسی ندارید.',
    'unauthorized' => 'شما مجاز به انجام این عملیات نیستید.',
    'information_received_successfully' => 'اطلاعات با موفقیت دریافت شد.',
    'verifyEmailSubject' => 'تایید پست الکترونیکی',
    'verificationEmailSentSuccessfully' => 'ایمیل تایید با موفقیت ارسال شد.',
    'emailVerifiedSuccessfully' => 'پست الکترونیکی با موفقیت تایید شد.',
    'notFound' => 'موردی یافت نشد.',
    'variant_not_found' => 'واریانت مورد نظر یافت نشد.',
    'factory_not_found' => 'کارخانه یافت نشد.',
    'location_not_found' => 'محل تحویل یافت نشد.',
    'newUserNotificationSubject' => 'به پلتفرم EliteZone خوش آمدید!',
    'newUserNotificationBody' => '   از ثبت نام شما سپاسگزاریم.
    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
    'listopherSavedSuccessfully' => 'اطلاعات لیستوفر با موفقیت ذخیره شد.',
    'created_successfully' => 'لیست علاقه‌مندی جدید با موفقیت ساخته شد.',
    'updated_successfully' => 'لیست علاقه‌مندی با موفقیت به‌روز شد.',
    'deleted_successfully' => 'لیست علاقه‌مندی با موفقیت حذف شد.',
    'cannot_update_default' => 'امکان تغییر نام لیست پیش‌فرض وجود ندارد.',
    'cannot_delete_default' => 'امکان حذف لیست پیش‌فرض وجود ندارد.',
    'variant_added_successfully' => 'محصول با موفقیت به لیست اضافه شد.',
    'variant_removed_successfully' => 'محصول با موفقیت از لیست حذف شد.',
    'toggle_successfully' => 'وضعیت محصول در علاقه‌مندی‌ها با موفقیت تغییر کرد.',
    'invalid_verification_token' => 'توکن تایید نامعتبر است یا قبلا استفاده شده است.',
    'model_not_found' => 'درخواستی یافت نشد.',
    'eliteYARInvitationSubject' => 'درخواست همکاری جدید',
    'eliteYARVerifiedSubject' => 'تأیید الیت یار',
    'eliteYARUnverifiedSubject' => 'برداشته‌شدن تأیید الیت یار',
    'eliteYARHoldEmployerSubject' => 'توقف موقت همکاری الیت یار',
    'eliteYARRestoredEmployerSubject' => 'ازسرگیری همکاری الیت یار',
    'system_busy_try_again' => 'سیستم در حال پردازش درخواست دیگری است. لطفاً لحظاتی دیگر مجدداً تلاش کنید.',
    'idempotency_key_reused' => 'این کلید یکتا (Idempotency-Key) قبلاً برای درخواست دیگری استفاده شده است. برای هر عملیات یک کلید تازه ارسال کنید.',
    'too_many_requests' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً لحظاتی صبر کنید و دوباره تلاش کنید.',
    'operation_successful' => 'عملیات با موفقیت انجام شد.',
    'system_error' => 'خطای سیستمی رخ داده است.',

    'address' => [
        'postal_address' => [
            'required' => 'وارد کردن آدرس پستی الزامی است.',
        ],
        'receiver_name' => [
            'required' => 'وارد کردن نام گیرنده الزامی است.',
        ],
        'receiver_mobile' => [
            'required' => 'وارد کردن شماره موبایل گیرنده الزامی است.',
            'regex' => 'فرمت شماره موبایل صحیح نیست.',
        ],
    ],

    // پیام‌های عمومی app/Exceptions/Handler.php — متن‌ها عیناً همان چیزی است که
    // قبلاً هاردکد بود؛ این کلیدها فقط جای منبع پیام را عوض می‌کنند.
    'errors' => [
        'unauthenticated' => 'احراز هویت نشده‌اید. لطفاً وارد حساب کاربری شوید.',
        'route_not_found' => 'مسیر مورد نظر یافت نشد.',
        'unauthorized_role' => 'شما نقش یا مجوز لازم برای دسترسی به این بخش را ندارید.',
        'item_not_found' => 'مسیر یا آیتم مورد نظر یافت نشد.',
        'record_not_found' => 'رکوردی با مشخصات وارد شده یافت نشد.',
        'unauthorized_action' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.',
        'forbidden_short' => 'دسترسی غیرمجاز',
        'not_found_short' => 'یافت نشد',
        'server_error_short' => 'خطای سرور',
        'generic_request_error' => 'خطای درخواست',
        'validation_failed' => 'اطلاعات ارسالی معتبر نیست.',
        'database_error' => 'خطای پایگاه داده رخ داده است.',
        'internal_server_error' => 'خطای داخلی سرور رخ داده است.',
    ],

    'elite_yar' => 'الیت‌یار شما',
];
