<?php

return [
    'loginMessage' => 'پلتفرم EliteZone
  کد تایید:
  :code',

    'newRegisterUser' => '
    عرض سلام و ادب

   از ثبت نام شما سپاسگزاریم.

    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
    'beneficiary_verification' => 'کاربر :name قصد خرید به نام شما در الیت-زون را دارد. کد تایید: :code',
    'elite_yar_invitation' => 'کاربر :employer_name شما را به عنوان الیت یار در الیت-زون دعوت کرده است. لطفاً جهت تایید به پنل کاربری خود مراجعه کنید.',

    'transaction_confirmed' => 'کاربر گرامی، درخواست :type مبلغ :amount .ریال شما تایید شد',
    'transaction_rejected' => 'کاربر گرامی، درخواست :type مبلغ :amount .ریال شما رد شد',

    'order_placed' => "سفارش شما با شماره :order_number رزرو شد.\nجهت تکمیل خرید، لطفاً پرداخت را انجام دهید.\nالیت زون",

    // Supplier debt notification (sent when deficit-weighbridge finds insufficient balance)
    'supplier_debt_notification' => "سوپلایر گرامی، بابت کسری باسکول سفارش :order_number مبلغ :amount ریال از شما دریافت خواهد شد. لطفاً کیف پول خود را شارژ کنید.\nالیت زون",

    // Buyer refund released (CheckSupplierBalancesJob — supplier topped up, parked refund freed)
    'buyer_refund_released' => "کاربر گرامی، مبلغ :amount ریال بابت کسری باسکول سفارش :order_number به کیف پول شما بازگردانده شد.\nالیت زون",

    // Ticket notifications — sent after admin actions
    'ticket_admin_replied' => "تیکت شما با کد :code پاسخ داده شد. لطفاً جهت مشاهده پاسخ وارد پنل کاربری شوید.\nالیت زون",
    'ticket_closed' => "تیکت شما با کد :code توسط تیم پشتیبانی بسته شد.\nالیت زون",

    // واریز مستقیم بانکی از طریق شناسه واریز پادیوم
    'wallet_deposited_podium' => "کاربر گرامی، مبلغ :amount تومان از طریق واریز مستقیم بانکی به کیف‌پول شما اضافه شد.\nالیت‌زون",

    // ── سبدِ مهلت‌دار (بند ۳.۱ / ۱۰.۲) ──────────────────────────────────
    // هر دو زیر ۷۰ کاراکترند تا یک سگمنت بمانند — شمارهٔ سفارش ندارند، پس
    // بودجه راحت است، ولی پیش از تغییرِ متن دوباره بشمارید.
    'cart_expiring' => 'سبد خرید شما تا :minutes دقیقه دیگر منقضی می‌شود.
الیت زون',
    'cart_prices_updated' => 'قیمت‌های سبد خرید شما به‌روز شد.
الیت زون',

    // دعوت‌نامه الیت یار توسط کارفرما (شماره‌ی هنوز ثبت‌نام‌نکرده)
    'elite_yar_sms_invitation' => 'کاربر گرامی، :employer_name شما را به عنوان الیت یار در الیت‌زون دعوت کرده است.
برای ثبت‌نام: :link
الیت‌زون',

    // دعوت‌نامه کارفرما توسط الیت یار
    'employer_invitation' => "کاربر گرامی، :advisor_name شما را به عنوان کارفرما در الیت‌زون دعوت کرده است.\nبرای ثبت‌نام: :link\nالیت‌زون",
];
