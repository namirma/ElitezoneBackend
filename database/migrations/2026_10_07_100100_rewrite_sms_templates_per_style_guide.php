<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * مدیریت، دورِ ششم، پرسشِ ۷ (۱۴۰۵/۰۷/۱۵): «به‌طورِ کلی برای متنِ همه‌ی پیامک‌ها در الیت‌زون از دستورالعملِ
 * زیر استفاده کن» — docs/specs/SMS_STYLE_GUIDE.md.
 *
 * `SmsTemplate::syncFromLang` متنِ ردیفِ موجود را عوض نمی‌کند (فقط فراداده)، پس بازنویسیِ
 * `lang/fa/sms.php` به هیچ سروری نمی‌رسید. این مهاجرت متنِ یک ردیف را **فقط وقتی** عوض می‌کند که هنوز
 * دقیقاً یکی از متن‌های پیش‌فرضی باشد که این پروژه زمانی شیپ کرده (همه‌ی نسخه‌های تاریخچه‌ی گیتِ
 * `lang/fa/sms.php`) یا متنِ dev که نشستِ تصمیم‌های فنی ۱۴۰۵/۰۷/۱۴ روی ردیف‌های الیت‌یار نوشت. ردیفی که ادمین
 * از پنل ویرایش کرده دست نمی‌خورد و در لاگ گزارش می‌شود — هیچ متنی بی‌صدا رونویسی نمی‌شود.
 *
 * متن‌های تازه و قدیمی همین‌جا منجمدند تا تغییرِ بعدیِ فایلِ lang رفتارِ این مهاجرت را عوض نکند.
 * متنِ قبلیِ هر ردیفِ عوض‌شده در `sms_template_rewrites` می‌ماند و `down()` دقیقاً همان را برمی‌گرداند.
 *
 * همچنین ستونِ `category` (بندِ ۱۸ِ دستورالعمل) اضافه و برای قالب‌های سیستمی پر می‌شود.
 */
return new class extends Migration
{
    /** @var array<string, array{new: string, old: list<string>}> */
    private const REWRITES = [
        'beneficiary_verification' => [
            'new' => ':name می‌خواهد در الیت‌زون به نام شما خرید کند.
کد تأیید: :code
اگر موافق نیستید، این کد را به کسی ندهید.
الیت‌زون',
            'old' => [
                'کاربر :name قصد خرید به نام شما در الیت-زون را دارد. کد تایید: :code',
            ],
        ],
        'buyer_refund_released' => [
            'new' => 'مبلغ :amount ریال بابت کسری باسکول سفارش :order_number به کیف پول شما برگشت داده شد.
الیت‌زون',
            'old' => [
                'کاربر گرامی، مبلغ :amount ریال بابت کسری باسکول سفارش :order_number به کیف پول شما بازگردانده شد.
الیت زون',
            ],
        ],
        'cart_expiring' => [
            'new' => 'سبد خرید شما :minutes دقیقه‌ی دیگر منقضی می‌شود.
الیت‌زون',
            'old' => [
                'سبد خرید شما تا :minutes دقیقه دیگر منقضی می‌شود.
الیت زون',
            ],
        ],
        'cart_payment_link' => [
            'new' => ':first_name عزیز،
:elite_yar_name سفارشی برای شما آماده کرده است.
برای بررسی و پرداخت:
:link
الیت‌زون',
            'old' => [
                'کاربر گرامی، :elite_yar_name یک سفارش برای شما آماده کرده است.
برای بررسی و پرداخت: :link
الیت‌زون',
            ],
        ],
        'cart_prices_updated' => [
            'new' => 'قیمت‌های سبد خرید شما به‌روز شد.
الیت‌زون',
            'old' => [
                'قیمت‌های سبد خرید شما به‌روز شد.
الیت زون',
            ],
        ],
        'coupon_funding_accepted' => [
            'new' => ':funder تأمین :percent٪ از تخفیف کد :code را پذیرفت.
الیت‌زون',
            'old' => [
                ':funder تأمین :percent٪ تخفیف کوپن :code را پذیرفت.
الیت‌زون',
            ],
        ],
        'coupon_funding_declined' => [
            'new' => ':funder تأمین تخفیف کد :code را نپذیرفت و این کد قابل استفاده نیست.
الیت‌زون',
            'old' => [
                ':funder تأمین تخفیف کوپن :code را نپذیرفت؛ این کوپن قابل استفاده نیست.
الیت‌زون',
            ],
        ],
        'coupon_funding_requested' => [
            'new' => ':proposer درخواست کرده :percent٪ از تخفیف کد :code از سهم شما تأمین شود.
برای پذیرش یا رد وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                ':proposer درخواست کرده :percent٪ تخفیف کوپن :code از سهم شما تأمین شود. برای پذیرش یا رد به پنل مراجعه کنید.
الیت‌زون',
            ],
        ],
        'delivery_confirmed' => [
            'new' => 'تحویل سفارش :order_number را کارفرما تأیید کرد.
الیت‌زون',
            'old' => [
                'تحویل بار سفارش :order_number توسط خریدار تأیید شد.
الیت‌زون',
            ],
        ],
        'delivery_expiring' => [
            'new' => 'مهلت تأیید تحویل سفارش :order_number تا :hours ساعت دیگر تمام می‌شود.
برای تأیید تحویل وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'مهلت تأیید تحویل سفارش :order_number تا :hours ساعت دیگر پایان می‌یابد.
الیت‌زون',
            ],
        ],
        'driver_arrived' => [
            'new' => 'راننده‌ی سفارش :order_number به مقصد رسید.
برای تأیید تحویل:
:link
الیت‌زون',
            'old' => [
                'راننده‌ی سفارش :order_number اعلام رسیدن کرد.
برای تأیید تحویل: :link
الیت‌زون',
            ],
        ],
        'driver_assigned' => [
            'new' => 'بار سفارش :order_number به شما سپرده شد.
پس از رسیدن به مقصد، از این لینک اعلام کنید:
:link
الیت‌زون',
            'old' => [
                'بار سفارش :order_number به شما تخصیص یافت.
پس از رسیدن، از این لینک اعلام کنید: :link
الیت‌زون',
            ],
        ],
        'driver_verify' => [
            'new' => 'کد تأیید شماره‌ی شما در الیت‌زون:
:code
این کد را در اختیار دیگران قرار ندهید.',
            'old' => [
                'کد تأیید شماره‌ی شما در سامانه‌ی الیت‌زون: :code
الیت‌زون',
            ],
        ],
        'elite_yar_hold_employer' => [
            'new' => ':first_name عزیز،
همکاری الیت‌یار شما، :elite_yar_name، موقتاً و به دلایل فنی متوقف شده است. برای دریافت تخفیف می‌توانید الیت‌یار جدیدی معرفی کنید. پس از رفع موارد فنی به شما خبر می‌دهیم.
الیت‌زون',
            'old' => [
                'کاربر گرامی، همکاری الیت یار شما (:elite_yar_name) موقتاً و به دلایل فنی متوقف شده و برای دریافت تخفیف می‌توانید الیت یار جدید معرفی کنید. ضمناً در صورت رفع موارد فنی، به شما اطلاع‌رسانی خواهد شد.
الیت زون',
            ],
        ],
        'elite_yar_invitation' => [
            'new' => ':first_name عزیز،
:employer_name شما را به‌عنوان الیت‌یار دعوت کرده است.
برای بررسی دعوت وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'کاربر :employer_name شما را به عنوان الیت یار در الیت-زون دعوت کرده است. لطفاً جهت تایید به پنل کاربری خود مراجعه کنید.',
            ],
        ],
        'elite_yar_restored_employer' => [
            'new' => ':first_name عزیز،
موارد فنی الیت‌یار شما، :elite_yar_name، رفع شد و همکاری او با شما دوباره فعال است. تخفیف خرید با این الیت‌یار از هم‌اکنون برقرار است.
الیت‌زون',
            'old' => [
                'کاربر گرامی، موارد فنی الیت یار شما (:elite_yar_name) رفع شد و همکاری او با شما دوباره فعال است؛ تخفیف خرید با این الیت یار از هم‌اکنون برقرار است.
الیت زون',
            ],
        ],
        'elite_yar_sms_invitation' => [
            'new' => ':employer_name شما را به‌عنوان الیت‌یار به الیت‌زون دعوت کرده است.
برای ثبت‌نام:
:link
الیت‌زون',
            'old' => [
                'کاربر گرامی، :employer_name شما را به عنوان الیت یار در الیت‌زون دعوت کرده است.
برای ثبت‌نام: :link
الیت‌زون',
            ],
        ],
        'elite_yar_unverified' => [
            'new' => ':first_name عزیز،
حساب الیت‌یار شما در الیت‌زون تا تأیید لایسنس نرم‌افزار سازه ۹۰ موقتاً متوقف (هولد) شد.
برای رفع این مورد، لایسنس معتبر سازه ۹۰ را از وب‌سایت saze90.com تهیه کنید.
الیت‌زون',
            'old' => [
                'کاربر گرامی، تأیید الیت یار حساب شما در الیت‌زون برداشته شد و حساب الیت یار شما تا تأیید لایسنس سازه ۹۰ در وضعیت «هولد» است. برای جزئیات به پروفایل ← «تایید الیت یار» مراجعه کنید.
الیت زون',
                'کاربر گرامی، تأیید الیت یار حساب شما در الیت‌زون برداشته شد. برای جزئیات به پروفایل ← «تایید الیت یار» مراجعه کنید.
الیت زون',
                'کاربر گرامی، حساب الیت یار شما در الیت‌زون تا تأیید لایسنس نرم‌افزار سازه ۹۰ موقتاً متوقف (هولد) شد؛ در این مدت خرید برای کارفرما ممکن نیست. برای رفع، به پروفایل خود در سایت مراجعه کنید.
الیت زون',
            ],
        ],
        'elite_yar_verified' => [
            'new' => ':first_name عزیز،
حساب الیت‌یار شما در الیت‌زون تأیید و فعال شد؛ از این پس کارفرمایان می‌توانند شما را به‌عنوان مشاور در سفارش‌ها انتخاب کنند.
الیت‌زون',
            'old' => [
                'کاربر گرامی، حساب شما در الیت‌زون به‌عنوان الیت یارِ تأییدشده شناخته شد. از این پس کارفرمایان می‌توانند شما را برای سفارش‌هایشان انتخاب کنند.
الیت زون',
                'کاربر گرامی، حساب الیت یار شما در الیت‌زون تأیید و فعال شد؛ از این پس کارفرمایان می‌توانند شما را برای سفارش‌ها انتخاب کنند.
الیت زون',
            ],
        ],
        'employer_invitation' => [
            'new' => ':advisor_name شما را به‌عنوان کارفرما به الیت‌زون دعوت کرده است.
برای ثبت‌نام:
:link
الیت‌زون',
            'old' => [
                'کاربر گرامی، :advisor_name شما را به عنوان کارفرما در الیت‌زون دعوت کرده است.
برای ثبت‌نام: :link
الیت‌زون',
            ],
        ],
        'loginMessage' => [
            'new' => 'کد ورود به الیت‌زون:
:code
اعتبار کد: :minutes دقیقه
این کد را در اختیار دیگران قرار ندهید.',
            'old' => [
                'پلتفرم EliteZone
  کد تایید:
  :code',
                'پلتفرم EliteZone
  کد تایید:',
                'پلتفرم EliteZone
  <br />
  کد تایید:
  <br />',
                'پلتفرم EliteZone <br/>کد تایید:<br />',
            ],
        ],
        'newRegisterUser' => [
            'new' => ':first_name عزیز،
ثبت‌نام شما در الیت‌زون انجام شد.
الیت‌زون',
            'old' => [
                'عرض سلام و ادب

   از ثبت نام شما سپاسگزاریم.

    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'عرض سلام و ادب

    از ثبت نام شما سپاسگزاریم.

    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'عرض سلام و ادب

    از ثبت نام شما در پلتفرم EliteZone سپاسگزاریم.

    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'عرض سلام و ادب
    از ثبت نام شما در پلتفرم EliteZone سپاسگزاریم.
    در حال مذاکره با سوپلایر‌های محترم هستیم.
    برای قدم های بعدی به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'عرض سلام و ادب
    از ثبت نام شما در پلتفرم EliteZone سپاسگزاریم.
    در حال مذاکره با سوپلایر‌های محترم برای راه اندازی سیستم خرید مصالح ساختمانی هستیم.
    به محض انجام به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'عرض سلام و ادب
    از ثبت نام شما در پلتفرم EliteZone سپاسکزاریم.
    در حال مذاکره با سوپلایر‌های محترم برای راه اندازی سیستم خرید مصالح ساختمانی هستیم.
    به محض انجام به شما اطلاع رسانی خواهیم کرد.
    تیم EliteZone',
                'خوش آمدید',
            ],
        ],
        'order_cancel_admin_approved' => [
            'new' => 'پشتیبانی با لغو سفارش :order موافقت کرد و سفارش لغو شد.
دلیل: :reason
الیت‌زون',
            'old' => [
                'پشتیبانی با لغو سفارش :order موافقت کرد و سفارش لغو شد. دلیل: :reason
الیت‌زون',
            ],
        ],
        'order_cancel_admin_rejected' => [
            'new' => 'پشتیبانی درخواست لغو سفارش :order را رد کرد و سفارش ادامه دارد.
دلیل: :reason
الیت‌زون',
            'old' => [
                'پشتیبانی درخواست لغو سفارش :order را رد کرد و سفارش ادامه می‌یابد. دلیل: :reason
الیت‌زون',
            ],
        ],
        'order_cancel_escalated' => [
            'new' => 'درخواست لغو سفارش :order در مهلت پاسخ نگرفت و برای تصمیم نهایی به پشتیبانی ارجاع شد.
الیت‌زون',
            'old' => [
                'درخواست لغو سفارش :order در مهلت مقرر پاسخ نگرفت و برای تصمیم نهایی به پشتیبانی ارجاع شد.
الیت‌زون',
            ],
        ],
        'order_cancel_rejected' => [
            'new' => 'درخواست لغو سفارش :order رد شد و برای تصمیم نهایی به پشتیبانی ارجاع شد.
دلیل: :reason
الیت‌زون',
            'old' => [
                'درخواست لغو سفارش :order رد شد و برای تصمیم نهایی به پشتیبانی ارجاع شد. دلیل: :reason
الیت‌زون',
            ],
        ],
        'order_cancel_requested' => [
            'new' => 'برای سفارش :order درخواست لغو ثبت شده است.
تا :until در الیت‌زون موافقت یا مخالفت کنید؛ در غیر این صورت به پشتیبانی ارجاع می‌شود.
الیت‌زون',
            'old' => [
                'برای سفارش :order درخواست لغو ثبت شده است. تا :until در پنل موافقت یا مخالفت کنید؛ در غیر این صورت به پشتیبانی ارجاع می‌شود.
الیت‌زون',
            ],
        ],
        'order_cancelled' => [
            'new' => 'سفارش :order_number لغو شد.
الیت‌زون',
            'old' => [
                'سفارش :order_number لغو شد.

الیت زون',
            ],
        ],
        'order_cancelled_refunded' => [
            'new' => 'سفارش :order_number لغو شد و مبلغ آن به کیف پول شما برگشت داده شد.
الیت‌زون',
            'old' => [
                'سفارش :order_number لغو شد.
مبلغ سفارش به کیف پول شما بازگشت داده شد.
الیت زون',
            ],
        ],
        'order_paid_buyer' => [
            'new' => 'پرداخت سفارش :order_number با موفقیت ثبت شد.
الیت‌زون',
            'old' => [
                'سفارش :order_number با موفقیت ثبت شد.
الیت زون',
                'کاربر گرامی، سفارش شما با شماره :order_number با موفقیت ثبت شد.
الیت زون',
            ],
        ],
        'order_paid_supplier' => [
            'new' => 'سفارش :order_number پرداخت شد؛ منتظر تأیید شما.
الیت‌زون',
            'old' => [
                'سفارش :order_number پرداخت شد؛ منتظر تأیید شما.
الیت زون',
                'سوپلایر گرامی، سفارش :order_number پرداخت شد و در انتظار تأیید و آماده‌سازی شماست.
الیت زون',
            ],
        ],
        'order_placed' => [
            'new' => 'سفارش :order_number رزرو شد.
برای تکمیل خرید، پرداخت را در الیت‌زون انجام دهید.
الیت‌زون',
            'old' => [
                'سفارش شما با شماره :order_number رزرو شد.
جهت تکمیل خرید، لطفاً پرداخت را انجام دهید.
الیت زون',
            ],
        ],
        'overweight_buyer_request' => [
            'new' => 'اختلاف وزن سفارش :order را تأمین‌کننده تأیید کرد؛ مابه‌التفاوت :amount ریال است.
برای پذیرش یا رد وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'اضافه‌بار سفارش :order تأیید شد. مابه‌التفاوت :amount ریال است؛ جهت پذیرش وارد پنل شوید.
الیت‌زون',
            ],
        ],
        'overweight_rejected' => [
            'new' => 'درخواست اختلاف وزن سفارش :order رد شد و بارنامه باطل شد.
الیت‌زون',
            'old' => [
                'درخواست اضافه‌بار سفارش :order رد شد و بارنامه باطل گردید.
الیت‌زون',
            ],
        ],
        'overweight_supplier_request' => [
            'new' => 'اختلاف وزن سفارش :order ثبت شد و تا تأیید شما تسویه‌ای انجام نمی‌شود.
برای بررسی وارد پنل تأمین‌کننده شوید.
الیت‌زون',
            'old' => [
                'اضافه‌بار سفارش :order از حد مجاز گذشت. تا تأیید شما هیچ تسویه‌ای انجام نمی‌شود.
الیت‌زون',
            ],
        ],
        'price_change_expired' => [
            'new' => 'درخواست تغییر قیمت «:variant» در مهلت :hours ساعته بررسی نشد و رد شد؛ کالا با قیمت قبلی در کاتالوگ است.
برای ثبت دوباره وارد پنل تأمین‌کننده شوید.
الیت‌زون',
            'old' => [
                'درخواست تغییر قیمت «:variant» در مهلت :hours ساعته بررسی نشد و رد شد. کالا با قیمت قبلی به کاتالوگ برگشت؛ می‌توانید دوباره ثبت کنید.
الیت‌زون',
            ],
        ],
        'product_change_approved' => [
            'new' => 'درخواست تغییر کاتالوگ برای «:subject» تأیید و روی سایت اعمال شد.
الیت‌زون',
            'old' => [
                'درخواست تغییر کاتالوگ شما برای «:subject» تأیید و روی سایت اعمال شد.
الیت‌زون',
            ],
        ],
        'product_change_rejected' => [
            'new' => 'درخواست تغییر کاتالوگ برای «:subject» رد شد.
دلیل: :reason
الیت‌زون',
            'old' => [
                'درخواست تغییر کاتالوگ شما برای «:subject» رد شد. دلیل: :reason
الیت‌زون',
            ],
        ],
        'reserve_extension_accepted' => [
            'new' => 'کارفرما تمدید رزرو سفارش :order را پذیرفت. مهلت تازه: :until
الیت‌زون',
            'old' => [
                'خریدار تمدید رزرو سفارش :order را پذیرفت؛ مهلت تازه :until است.
الیت‌زون',
            ],
        ],
        'reserve_extension_proposed' => [
            'new' => 'تأمین‌کننده تمدید رزرو سفارش :order را تا :until پیشنهاد داده است.
تا :respond_until در الیت‌زون بپذیرید یا رد کنید؛ اگر پاسخ ندهید، رزرو لغو می‌شود.
الیت‌زون',
            'old' => [
                'تأمین‌کننده تمدید رزرو سفارش :order را تا :until پیشنهاد داده است. تا :respond_until در پنل بپذیرید یا رد کنید؛ بی‌پاسخی یعنی لغو رزرو.
الیت‌زون',
            ],
        ],
        'reserve_extension_rejected' => [
            'new' => 'کارفرما تمدید رزرو سفارش :order را نپذیرفت و رزرو لغو شد.
الیت‌زون',
            'old' => [
                'خریدار تمدید رزرو سفارش :order را نپذیرفت و رزرو لغو شد.
الیت‌زون',
            ],
        ],
        'return_damage_awaiting_buyer' => [
            'new' => 'هزینه‌ی برگشت سفارش :order_number به مبلغ :amount ریال ثبت و تأیید شد.
برای بررسی و تأیید وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'هزینه‌ی عودت سفارش :order_number به مبلغ :amount ریال ثبت و تأیید شد.
جهت بررسی وارد پنل شوید.
الیت‌زون',
            ],
        ],
        'return_damage_cap_reached' => [
            'new' => 'مجموع هزینه‌های برگشت سفارش :order_number به ارزش کالا رسید و سفارش بسته شد.
الیت‌زون',
            'old' => [
                'مجموع هزینه‌های عودت سفارش :order_number به ارزش کالا رسید؛ سفارش خاتمه یافت.
الیت‌زون',
            ],
        ],
        'return_damage_settled' => [
            'new' => 'هزینه‌ی برگشت سفارش :order_number تسویه شد و بار آماده‌ی ارسال دوباره است.
الیت‌زون',
            'old' => [
                'هزینه‌ی عودت سفارش :order_number تسویه شد و بار آماده‌ی حمل مجدد است.
الیت‌زون',
            ],
        ],
        'shipment_returned' => [
            'new' => 'محموله‌ی سفارش :order_number چون تحویل آن تأیید نشد، به مبدأ برگشت داده شد.
الیت‌زون',
            'old' => [
                'محموله‌ی سفارش :order_number به دلیل عدم تأیید تحویل، به مبدأ عودت شد.
الیت‌زون',
            ],
        ],
        'supplier_approved' => [
            'new' => ':first_name عزیز،
درخواست تأمین‌کنندگی شما تأیید شد. با همین شماره وارد پنل تأمین‌کننده شوید:
:panel_url
الیت‌زون',
            'old' => [
                'درخواست تأمین‌کنندگی شما تأیید شد. با همین شماره وارد پنل تأمین‌کننده شوید: :panel_url
الیت‌زون',
            ],
        ],
        'supplier_debt_notification' => [
            'new' => 'بابت کسری باسکول سفارش :order_number، مبلغ :amount ریال از شما دریافت می‌شود.
برای تسویه، کیف پول خود را در الیت‌زون شارژ کنید.
الیت‌زون',
            'old' => [
                'سوپلایر گرامی، بابت کسری باسکول سفارش :order_number مبلغ :amount ریال از شما دریافت خواهد شد. لطفاً کیف پول خود را شارژ کنید.
الیت زون',
            ],
        ],
        'supplier_needs_correction' => [
            'new' => ':first_name عزیز،
درخواست تأمین‌کنندگی شما نیاز به اصلاح دارد: :reason
پس از اصلاح در پروفایل، دوباره ارسال کنید.
الیت‌زون',
            'old' => [
                'درخواست تأمین‌کنندگی شما نیاز به اصلاح دارد: :reason
پس از اصلاح در پروفایل سایت، دوباره ارسال کنید.
الیت‌زون',
            ],
        ],
        'supplier_rejected' => [
            'new' => ':first_name عزیز،
درخواست تأمین‌کنندگی شما رد شد.
دلیل: :reason
الیت‌زون',
            'old' => [
                'درخواست تأمین‌کنندگی شما رد شد. دلیل: :reason
الیت‌زون',
            ],
        ],
        'ticket_admin_replied' => [
            'new' => 'تیکت :code پاسخ داده شد.
برای مشاهده‌ی پاسخ وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'تیکت شما با کد :code پاسخ داده شد. لطفاً جهت مشاهده پاسخ وارد پنل کاربری شوید.
الیت زون',
            ],
        ],
        'ticket_closed' => [
            'new' => 'تیکت :code توسط پشتیبانی بسته شد.
الیت‌زون',
            'old' => [
                'تیکت شما با کد :code توسط تیم پشتیبانی بسته شد.
الیت زون',
            ],
        ],
        'ticket_resolved' => [
            'new' => 'تیکت :code حل شد.
اگر مشکل همچنان باقی است، در همان تیکت پاسخ دهید.
الیت‌زون',
            'old' => [
                'تیکت شما با کد :code حل شد. اگر مشکل برطرف نشده، از همان تیکت پاسخ دهید.
الیت زون',
            ],
        ],
        'transaction_confirmed' => [
            'new' => 'درخواست :type شما به مبلغ :amount ریال تأیید شد.
الیت‌زون',
            'old' => [
                'کاربر گرامی، درخواست :type مبلغ :amount .ریال شما تایید شد',
            ],
        ],
        'transaction_rejected' => [
            'new' => 'درخواست :type شما به مبلغ :amount ریال رد شد.
برای جزئیات وارد الیت‌زون شوید.
الیت‌زون',
            'old' => [
                'کاربر گرامی، درخواست :type مبلغ :amount .ریال شما رد شد',
            ],
        ],
        'wallet_deposited_podium' => [
            'new' => 'مبلغ :amount تومان از واریز مستقیم بانکی به کیف پول شما اضافه شد.
الیت‌زون',
            'old' => [
                'کاربر گرامی، مبلغ :amount تومان از طریق واریز مستقیم بانکی به کیف‌پول شما اضافه شد.
الیت‌زون',
            ],
        ],
        'wallet_funded' => [
            'new' => 'کیف پول شما به مبلغ :amount ریال شارژ شد.
:tracking_lineالیت‌زون',
            'old' => [
                'کیف پول شما مبلغ :amount ریال شارژ شد.
:tracking_lineالیت زون',
            ],
        ],
    ];

    /** @var array<string, string> */
    private const CATEGORIES = [
        'loginMessage' => 'AUTH',
        'beneficiary_verification' => 'AUTH',
        'driver_verify' => 'AUTH',
        'newRegisterUser' => 'TRANSACTION',
        'wallet_funded' => 'TRANSACTION',
        'wallet_deposited_podium' => 'TRANSACTION',
        'order_paid_buyer' => 'TRANSACTION',
        'order_paid_supplier' => 'TRANSACTION',
        'buyer_refund_released' => 'TRANSACTION',
        'order_cancelled_refunded' => 'TRANSACTION',
        'elite_yar_verified' => 'STATUS',
        'elite_yar_hold_employer' => 'STATUS',
        'elite_yar_restored_employer' => 'STATUS',
        'cart_payment_link_cancelled' => 'STATUS',
        'transaction_confirmed' => 'STATUS',
        'transaction_rejected' => 'STATUS',
        'order_cancelled' => 'STATUS',
        'cart_prices_updated' => 'STATUS',
        'overweight_rejected' => 'STATUS',
        'delivery_confirmed' => 'STATUS',
        'shipment_returned' => 'STATUS',
        'return_damage_settled' => 'STATUS',
        'return_damage_cap_reached' => 'STATUS',
        'reserve_extension_accepted' => 'STATUS',
        'reserve_extension_rejected' => 'STATUS',
        'order_cancel_accepted' => 'STATUS',
        'order_cancel_rejected' => 'STATUS',
        'order_cancel_escalated' => 'STATUS',
        'order_cancel_admin_approved' => 'STATUS',
        'order_cancel_admin_rejected' => 'STATUS',
        'price_change_expired' => 'STATUS',
        'product_change_approved' => 'STATUS',
        'product_change_rejected' => 'STATUS',
        'supplier_approved' => 'STATUS',
        'supplier_rejected' => 'STATUS',
        'coupon_funding_accepted' => 'STATUS',
        'coupon_funding_declined' => 'STATUS',
        'elite_yar_unverified' => 'ACTION_REQUIRED',
        'elite_yar_invitation' => 'ACTION_REQUIRED',
        'elite_yar_sms_invitation' => 'ACTION_REQUIRED',
        'employer_invitation' => 'ACTION_REQUIRED',
        'cart_payment_link' => 'ACTION_REQUIRED',
        'coupon_wallet_low_admin' => 'ACTION_REQUIRED',
        'order_placed' => 'ACTION_REQUIRED',
        'supplier_debt_notification' => 'ACTION_REQUIRED',
        'overweight_supplier_request' => 'ACTION_REQUIRED',
        'overweight_buyer_request' => 'ACTION_REQUIRED',
        'driver_assigned' => 'ACTION_REQUIRED',
        'driver_arrived' => 'ACTION_REQUIRED',
        'return_damage_awaiting_buyer' => 'ACTION_REQUIRED',
        'reserve_extension_proposed' => 'ACTION_REQUIRED',
        'order_cancel_requested' => 'ACTION_REQUIRED',
        'supplier_needs_correction' => 'ACTION_REQUIRED',
        'coupon_funding_requested' => 'ACTION_REQUIRED',
        'cart_expiring' => 'REMINDER',
        'delivery_expiring' => 'REMINDER',
        'ticket_admin_replied' => 'SUPPORT',
        'ticket_resolved' => 'SUPPORT',
        'ticket_closed' => 'SUPPORT',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('sms_templates', 'category')) {
            Schema::table('sms_templates', function (Blueprint $table) {
                $table->string('category', 32)->nullable()->after('audience');
            });
        }

        foreach (self::CATEGORIES as $key => $category) {
            DB::table('sms_templates')->where('key', $key)->whereNull('category')->update(['category' => $category]);
        }

        Schema::create('sms_template_rewrites', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->text('previous_body');
            $table->text('new_body');
            $table->timestamp('created_at')->nullable();
        });

        $skipped = [];

        foreach (self::REWRITES as $key => $texts) {
            $row = DB::table('sms_templates')->where('key', $key)->first();

            if ($row === null) {
                continue;
            }

            $current = trim(str_replace("\r\n", "\n", (string) $row->body));

            if ($current === $texts['new']) {
                continue;
            }

            if (! in_array($current, $texts['old'], true)) {
                $skipped[] = $key;

                continue;
            }

            DB::table('sms_template_rewrites')->insert([
                'key' => $key,
                'previous_body' => (string) $row->body,
                'new_body' => $texts['new'],
                'created_at' => now(),
            ]);

            DB::table('sms_templates')->where('id', $row->id)->update(['body' => $texts['new'], 'updated_at' => now()]);
        }

        if ($skipped !== []) {
            Log::warning('SMS style-guide rewrite: templates edited by an admin were left untouched', ['keys' => $skipped]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('sms_template_rewrites')->orderByDesc('id')->get() as $rewrite) {
            DB::table('sms_templates')
                ->where('key', $rewrite->key)
                ->where('body', $rewrite->new_body)
                ->update(['body' => $rewrite->previous_body]);
        }

        Schema::dropIfExists('sms_template_rewrites');

        if (Schema::hasColumn('sms_templates', 'category')) {
            Schema::table('sms_templates', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
