<?php

return [
    'userType' => 'نوع کاربری با موفقیت تغییر یافت.',
    'usertype_missing' => 'خطای سرور: تعریف نوع کاربری برای :slug در پایگاه داده موجود نیست.',
    'invalid_role_selection' => 'انتخاب نقش نامعتبر است یا مشکلی در پردازش آن رخ داده است.',
    'type_set_successfully' => 'نوع کاربری شما با موفقیت تنظیم شد.',
    'updated_successfully' => 'اطلاعات پروفایل با موفقیت به‌روزرسانی شد.',
    'mobileUpdateSuccessfully' => 'شماره موبایل با موفقیت به‌روزرسانی شد.',
    'message_marked_read' => 'پیام به عنوان خوانده شده علامت‌گذاری شد.',
    'all_messages_marked_read' => 'تمام پیام‌ها به عنوان خوانده شده علامت‌گذاری شدند.',
    'email_send_failed' => 'خطا در ارسال ایمیل تایید. لطفا دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
    'cannot_delete_last_address' => 'امکان حذف آخرین آدرس وجود ندارد.',
    'company_info_received' => 'اطلاعات شرکت با موفقیت دریافت شد.',
    'company_inquiry_failed' => 'سرویس استعلام اطلاعات شرکت در حال حاضر در دسترس نیست. لطفاً مجدداً تلاش کنید.',
    'search_initial_data' => 'اطلاعات اولیه جستجوی پروفایل.',
    'search_saved_successfully' => 'جستجو با موفقیت ثبت شد.',
    'search_history_cleared' => 'تاریخچه جستجو پاک شد.',
    'search_results' => 'نتیجه جستجو.',

    'employer_deleted' => '.اطلاعات کارفرما با موفقیت حذف شد',
    'employer_not_found' => '.پروفایل کارفرما یافت نشد',

    'supplier_deleted'   => 'اطلاعات تأمین‌کننده با موفقیت حذف شد.',
    'supplier_not_found' => 'پروفایل تأمین‌کننده یافت نشد.',

    // Employer SMS invite (expert/advisor only)
    'invite_sent_successfully' => 'دعوت‌نامه با موفقیت ارسال شد.',
    'invite_cap_reached' => 'حداکثر تعداد دعوت‌های مجاز شما به پایان رسیده است.',
    'invite_already_sent' => 'قبلاً به این شماره موبایل دعوت‌نامه ارسال شده است.',
    'remaining_invites' => 'تعداد دعوت‌های باقی‌مانده.',
    'invite_mobile_required' => 'شماره موبایل الزامی است.',
    'invite_mobile_invalid' => 'فرمت شماره موبایل صحیح نیست. باید ۱۱ رقمی و با ۰۹ شروع شود.',
    'invite_mobile_self' => 'امکان دعوت از شماره موبایل خودتان وجود ندارد.',

    'badge_loaded' => 'نشان‌های کاربر دریافت شد.',

    // Avatar
    'avatar_uploaded' => 'تصویر پروفایل با موفقیت بارگذاری شد.',
    'avatar_upload_failed' => 'بارگذاری تصویر پروفایل با خطا مواجه شد.',
    'avatar_required' => 'انتخاب تصویر الزامی است.',
    'avatar_mimes' => 'فرمت فایل قابل قبول نیست. (jpeg، png یا webp)',
    'avatar_max' => 'حداکثر حجم تصویر ۵ مگابایت است.',

    // Roles (StoreUserTypeRequest)
    'roles_required'          => 'انتخاب حداقل یک نقش الزامی است.',
    'roles_invalid'           => 'نقش نامعتبر است. نقش‌های مجاز: employer, supplier, expert.',
    'roles_array'             => 'فیلد roles باید آرایه باشد.',
    'roles_supplier_conflict' => 'نقش سوپلایر نمی‌تواند همزمان با کارفرما یا مشاور فنی انتخاب شود.',

    // Email management
    'email_required' => 'وارد کردن ایمیل الزامی است.',
    'email_invalid' => 'فرمت ایمیل وارد شده صحیح نیست.',
    'email_taken' => 'این ایمیل قبلاً ثبت شده است.',
    'email_resent' => 'لینک تأیید مجدداً به ایمیل شما ارسال شد.',
    'email_deleted' => 'ایمیل با موفقیت حذف شد.',
    'email_none_to_resend' => 'ایمیلی برای ارسال مجدد لینک وجود ندارد.',
    'email_already_verified' => 'ایمیل شما قبلاً تأیید شده است.',
];
