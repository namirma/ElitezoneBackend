> 📎 **کپی برای تیمِ بک‌اند (۱۴۰۵/۰۶/۲۹) — مرجع و نسخه‌ی زنده: `EliteZoneMaster/docs/specs/SUPPLIER_CONSOLIDATION_2026-09-19.md`** (ریپوی `alirewzak/EliteZoneMaster`). این کپی به‌روز نمی‌شود؛ به درخواستِ نوید اضافه شد تا نشستِ بک‌اند بتواند تصمیمِ پشتِ کد را بخواند.

# یکپارچه‌سازیِ تأمین‌کننده — سایت = دروازه‌ی صلاحیت، پنل = محلِ کار

> تصمیمِ مدیریت، ابلاغِ علی ۱۴۰۵/۰۶/۲۸ (۲۰۲۶-۰۹-۱۹). اجرا در یک نشستِ جدا و موازی.
> **قانون صفر و قانون یک ریشه برقرارند** (چک‌پوینت پیش از کارِ ریسکی؛ چیزی پاک نشود، فقط
> در `docs/session/CLEANUP_CANDIDATES.md` ثبت شود).

## ۰. اگر این نشست در worktree باز شده

پوشه‌های `BackEnd/`, `FrontEnd/`, `BackOffice/`, `SupplierPanel/` در `.gitignore` ریشه‌اند، پس یک
worktree از ریشه **فقط `docs/` را دارد**. کدِ واقعی همیشه در مسیرِ مطلق است:
`C:\Projects\EliteZone\EliteZoneMaster\{BackEnd\EliteZone_BackEnd_Phase2, FrontEnd\EliteZone_FrontEnd_Phase2, BackOffice, SupplierPanel}`
— همان‌جا کار کن، همان‌جا کامیت کن. (حافظه: `subagent-worktrees-cannot-reach-subrepos`.)

## ۱. مسئله

نقشِ تأمین‌کننده امروز از **دو جا** مدیریت می‌شود:

| کجا | چه چیزی |
|---|---|
| سایتِ اصلی › پروفایل (`personaConfig.js` › `SUPPLIER`) | `dashboard, supplier_info, orders, finance, support, messages, my_items, addresses, account_info, account_settings, agreements` |
| پنلِ تأمین‌کننده (`SupplierPanel/`) | `/products, /prices, /stocks, /factories, /factory-locations, /shipping-rates, /orders(+pending-debts, pending-moadian), /earnings, /requests, /coupons, /drivers, /documents, /reserve-time, /status` |

دوگانگی یعنی یک تأمین‌کننده نمی‌داند کجا چه کاری را انجام دهد، و دو UI روی یک داده می‌نشیند.

## ۲. تصمیم

1. **سایتِ اصلی برای تأمین‌کننده فقط یک «دروازه‌ی صلاحیت» است.** تأمین‌کننده‌ای که تازه
   اسمِ الیت‌زون را شنیده وارد سایت می‌شود، نقشِ «تأمین‌کننده» را انتخاب می‌کند، همه‌جای سایت
   را می‌بیند، ولی **پروفایلی به شکلِ امروز ندارد**. تنها چیزی که در پروفایل دارد:
   - «درخواستِ احرازِ صلاحیت» — من کی هستم، چه تولید می‌کنم، اطلاعات/مدارکِ شرکت، فهرستِ
     محصولات (آپلود)، و هر چیزی که برای یک شخصِ حقوقی/کارخانه‌ی ایرانی لازم است (تصمیم با
     نشستِ مجری — بخش ۴).
   - وضعیتِ همان درخواست (در انتظار / نیاز به اصلاح / رد / تأیید).
   - آنچه به پنل **نمی‌شود** منتقل کرد چون به هویتِ کاربر تعلق دارد نه به کسب‌وکار:
     `account_info`, `account_settings`, `agreements`, `support`, `messages` (تصمیمِ نهایی با
     مجری؛ اصل: «اگر برای اداره‌ی کسب‌وکارِ تأمین‌کننده است → پنل؛ اگر برای هویتِ کاربرِ
     الیت‌زون است → سایت»).
2. **درخواست به بک‌آفیس می‌رود**؛ ادمین تأیید یا رد می‌کند (`admin/supplier-requests`
   و `admin/suppliers/{user}/approve` از قبل هستند — بازبینی و تکمیل شوند، نه دوباره‌سازی).
3. **پس از تأیید، دسترسی به پنلِ تأمین‌کننده داده می‌شود.** روشِ دسترسی را مجری با
   بررسیِ الگوی بازارهای مشابه (دیجی‌کالا سلر سنتر، ترب، باسلام، Amazon Seller Central)
   تصمیم بگیرد و در همین فایل بنویسد. راهنمای اولیه: همان شماره‌ی موبایل + OTP/رمزِ فعلی،
   با گیتِ `role.supplier` که فقط بعد از approve ست می‌شود (همین الان
   `admin/suppliers/{user}/approve` «تنها مسیرِ اتصالِ نقش» است). پیامکِ «تأییدِ صلاحیت»
   با لینکِ پنل از ماژولِ پیامک‌ها (`sms_templates`، یک رویدادِ جدید). کلیدِ جدا/دسترسیِ
   جدا **ساخته نشود** مگر دلیلِ امنیتی ثبت شود.
4. **هر چیزی که می‌شود به پنل منتقل شود، منتقل می‌شود**: `supplier_info` (مشخصات شرکت،
   مدارک، دسته‌ی فعالیت)، `orders`ِ تأمین‌کننده، `finance`/earnings، `my_items`ِ مربوط به
   محصولاتِ خودش، `addresses`ِ کارخانه (= `factory-locations`)، داشبورد.

## ۳. مرزِ قطعیِ کار (چه چیزی حذف نمی‌شود)

- خریدار/کارفرما/الیت‌یار/مهندس: **هیچ تغییری**. فقط شاخه‌ی `SUPPLIER` در `personaConfig`
  و کامپوننت‌های `profile/ui/pages/SupplierInfo/**` لمس می‌شوند.
- قانون یک: تب‌ها و کامپوننت‌هایی که از سایت برداشته می‌شوند **پاک نمی‌شوند** — از روت/تب
  خارج می‌شوند و ردیفِ CLEANUP می‌گیرند.
- بک‌اند مشترک است؛ اندپوینت‌های `api/profile/supplier/**` می‌مانند (پنل همان‌ها را
  می‌خواند). اگر اندپوینتِ جدید لازم شد: FormRequest (422 نه 500) + Resource + تست +
  `api.json` از دیتابیسِ تازه (`DB_DATABASE=laravel_testing_x php artisan db:wipe --force
  && migrate && php -d memory_limit=1G artisan scramble:export`).

## ۴. تصمیم‌های مجری (پرشده ۱۴۰۵/۰۶/۲۸ — علی «بله» گفت؛ ✅ اجرا شد، کامیت‌ها در بخش ۷)

> یافته‌ی مهمِ نقشه‌برداری: در بک‌اند **دو صفِ جدا** به اسمِ «درخواست» هست.
> `admin/supplier-requests` صفِ **عملیاتی** است (لغو سفارش / افزودن به کاتالوگ / تغییر
> قیمت — `AdminSupplierRequestController`) و ربطی به صلاحیت ندارد. صلاحیت از
> `admin/suppliers/{user}` (`AdminSupplierController@approve/reject`) تصمیم می‌گیرد و
> بک‌آفیس آن را در «سوپلایرها › جزئیات» دارد. پس صفِ صلاحیت روی همان `suppliers`
> ساخته می‌شود، نه روی `supplier-requests`؛ نوارِ `partial`ِ آن صفحه‌ی عملیاتی صادق
> است (پذیرش سفارش را لغو نمی‌کند) و می‌ماند — فقط نامش در منو رفعِ ابهام می‌شود.

### ۴-۱ فیلدهای «درخواستِ احرازِ صلاحیت»

زیرساختِ موجود نگه داشته می‌شود: `supplier_profiles` (+ `status`/`rejection_reason`)
و `supplier_documents` (دیسکِ private، ۵MB، ۱۰ فایل). یک مهاجرت ستون‌های حقوقی را
اضافه می‌کند، `StoreSupplierProfileRequest` سخت می‌شود (۴۲۲ نه ۵۰۰)، و
`supplier_documents` ستونِ `kind` می‌گیرد.

| فیلد | ستون | قاعده | الزام |
|---|---|---|---|
| نوعِ شخص | `entity_type` | `company` یا `individual` | اجباری |
| نامِ ثبتی / نامِ مجموعه | `company` (موجود) | string 2..64 | اجباری |
| شناسه‌ی ملیِ شخصِ حقوقی | `national_id` | ۱۱ رقم | اجباری اگر `company` |
| شماره‌ی ثبت | `registration_number` | ۱..۲۰ | اجباری اگر `company` |
| کدِ اقتصادی | `economic_code` | ۱۲ رقم | اختیاری |
| نماینده‌ی قانونی — نام | `representative_name` | 2..100 | اجباری |
| نماینده‌ی قانونی — کدِ ملی | `representative_national_code` | ۱۰ رقم + checksum | اجباری |
| تلفنِ ثابت | `landline` | ۱۱ رقم با ۰ | اختیاری |
| استان / شهرِ کارخانه | `province_id`, `city_id` | `exists` | اجباری |
| نشانیِ کارخانه | `factory_address` | 10..500 | اجباری |
| کدپستی | `postal_code` | ۱۰ رقم | اجباری |
| شبای شرکت | `iban` | `IR` + ۲۴ رقم | اختیاری (تسویه بعداً از پنل › مالی) |
| دسته‌های فعالیت | `supplier_activities` (موجود) | ≥۱ | اجباری |
| توضیحِ فعالیت، برندها، استانداردها، وب‌سایت، سابقه، میزانِ تولید، استان‌های فعالیت | موجود | همان + `max` | اختیاری |
| مدارک | `supplier_documents.kind` | `gazette` (آگهی تأسیس/آخرین تغییرات) · `license` (پروانه‌ی بهره‌برداری) · `iso` · `representative_letter` (معرفی‌نامه) · `product_list` (xlsx/csv/pdf) · `other` | برای `company`: `gazette` اجباری؛ بقیه اختیاری |

~~«ارسالِ درخواست» یک اکشنِ جدا است~~ → **در اجرا ساده‌تر شد:** همان `POST profile/supplier`
برای پروفایلِ `draft`/`rejected`/`needs_correction` یعنی ارسال (`status=pending`,
`submitted_at=now`، شرطِ آگهی تأسیس برای شخصِ حقوقی همان‌جا) و برای `approved` یعنی ویرایشِ
مشخصات بدونِ ازدست‌دادنِ تأیید. `draft` را `SyncUserRolesAction` هنگامِ انتخابِ نقش می‌سازد
(پیش‌فرضِ ستون `pending` ماند تا تست‌ها و داده‌ی قدیمی معنایشان را حفظ کنند).

### ۴-۲ نگاشتِ تب‌های `SUPPLIER` در سایت

| تبِ امروز | مقصد | دلیل |
|---|---|---|
| `dashboard` | پنل `/` (هست) — از سایت خارج | داشبوردِ کسب‌وکار |
| `supplier_info` | **سایت** = فرمِ درخواست + وضعیت (تا تأیید)؛ **پنل** `/company` (جدید، همان `GET/POST profile/supplier`) بعد از تأیید | قبل از تأیید پنل بسته است؛ بعد از تأیید ویرایشِ مشخصاتِ شرکت کارِ کسب‌وکار است. تبِ سایت بعد از تأیید فقط وضعیت + دکمه‌ی «ورود به پنل» نشان می‌دهد |
| `orders` | پنل `/orders` (هست) — خارج | سفارش‌های فروش |
| `finance` | پنل `/finance` (**جدید**: کیف پول، حساب‌های بانکی، برداشت، رسیدها — همان `profile/financial/**`) + `/earnings` (هست) — خارج | مالیِ کسب‌وکار |
| `my_items` | خارج (علاقه‌مندی/اشتراکِ موجودی ابزارِ خریدار است؛ کالاهای خودش در پنل `/products`) | — |
| `addresses` | پنل `/factory-locations` (هست) — خارج | نشانیِ کارخانه |
| `support` `messages` `account_info` `account_settings` `agreements` | **سایت** می‌مانند | هویتِ کاربرِ الیت‌زون (تیکت، اعلان، احرازِ هویت، رمز/2FA، قراردادها) |

پیامد: `personaConfig.SUPPLIER = [supplier_info, support, messages, account_info,
account_settings, agreements]`؛ ایندکسِ `/profile` برای این پرسونا به `supplier_info`
هدایت می‌شود. کامپوننت‌های خارج‌شده حذف نمی‌شوند (قانون یک، ردیفِ CLEANUP).
✅ **تصمیمِ علی (۱۴۰۵/۰۶/۲۸):** تأمین‌کننده مثلِ شخصِ حقیقی از سایت خرید **نمی‌کند**؛ برای
خرید حسابِ جداگانه می‌سازد. پس گیتِ سرور `not.supplier` (`EnsureUserIsNotSupplier`) روی
`cart/items`، `checkout/pay`، `checkout/reserve` و `checkout/pay-for` نشسته — هم نقشِ `supplier`
هم نوعِ `supplier` (متقاضی) — با ۴۰۳ و پیامِ `auth.policy.supplier_cannot_buy`. سایت همان
پیام را در توست نشان می‌دهد (`cart.slice` پیامِ سرور را عیناً برمی‌گرداند).

### ۴-۳ اعطای دسترسی به پنل + پیامک

الگوی دیجی‌کالا سلر سنتر / باسلام / Amazon Seller Central یکی است: **همان هویت،
دامنه‌ی جدا، نقش دروازه است.** همین کار می‌شود — کلید یا رمزِ جدا ساخته نمی‌شود:
- ورودِ پنل همان `check-user` + OTP/رمز؛ `device_name` مخصوصِ پنل (هست).
- دروازه = نقشِ `supplier` که فقط `admin/suppliers/{user}/approve` ست می‌کند (هست).
- سه رویدادِ پیامکِ جدید در ماژولِ پیامک: `supplier_approved` (با `:panel_url`)،
  `supplier_rejected` (با `:reason`)، `supplier_needs_correction` (با `:reason`).
  لینکِ پنل از `config('services.supplier_panel.url')` (`SUPPLIER_PANEL_URL` در env).
- سایت روی وضعیتِ «تأیید شده» دکمه‌ی «ورود به پنل تأمین‌کننده» (`VITE_SUPPLIER_PANEL_URL`).
- دلیلِ امنیتیِ نساختنِ دسترسیِ جدا: توکنِ Sanctum per-device و نقشِ سرور-ساید کافی‌اند؛
  کلیدِ دوم فقط سطحِ حمله و بارِ پشتیبانی اضافه می‌کند.

### ۴-۴ رفتارِ تأمین‌کننده‌ی تأییدنشده

| کجا | چه می‌بیند |
|---|---|
| سایت › پروفایل | فرمِ درخواست (draft/needs_correction قابلِ ویرایش) + کارتِ وضعیت با دلیلِ ادمین |
| پنل | `/status` (هست) + حالتِ `needs_correction` با دکمه‌ی «اصلاحِ درخواست در سایت» |
| سبد/خرید | بدونِ تغییر |

وضعیت‌ها: `draft → pending → approved | rejected | needs_correction (→ pending)`.

### ۴-۵ بک‌آفیس

- «سوپلایرها» (`manage-users`) صفِ صلاحیت است: فیلترِ پیش‌فرض `pending`، ستونِ «مدارک»،
  چیپِ وضعیتِ چهارحالته.
- جزئیات: بخشِ «اطلاعاتِ حقوقی»، فهرستِ مدارک با دانلودِ احرازشده (اندپوینتِ ادمینِ
  جدید `GET admin/suppliers/{user}/documents/{document}/file`)، سه دکمه: تأیید / نیاز به
  اصلاح (دلیل ≥۱۰) / رد (دلیل ≥۱۰). `POST admin/suppliers/{user}/request-correction` جدید.
- نوارِ `FrontendStatusNotice tone="live"` روی این صفحه (و ردیفِ جدیدِ ممیزی ۵.۱).
  صفحه‌ی عملیاتیِ `supplier-requests` در منو «درخواست‌های عملیاتیِ تأمین‌کننده» نام می‌گیرد.

## ۵. روشِ اجرا

1. `نشست جدید` → `NEXT_SESSION_NOTES.md` و `BACKOFFICE_ROADMAP.md` (فقط بخشِ آخر).
2. `anthropic-skills:elitezone-orientation` روی مسیرِ تأمین‌کننده در هر سه ریپو + پنل.
3. `superpowers:brainstorming` برای بخش ۴ → همین فایل را پر کن → **قبل از کدنویسی به علی
   نشان بده** (یک پیامِ کوتاه با فهرستِ شماره‌دار).
4. پیاده‌سازی به ترتیب: بک‌اند (درخواستِ صلاحیت + approve → نقش + پیامک) → بک‌آفیس (صف)
   → پنل (تب‌های منتقل‌شده) → سایت (پروفایلِ تأمین‌کننده = فرمِ درخواست + وضعیت).
5. هر مرحله: تستِ سبز، لینتِ صفر، بیلدِ سبز، بررسیِ زنده در مرورگر با کاربرِ واقعی، کامیت،
   پوش. در پایان: `anthropic-skills:elitezone-session-log`.

## ۶. حساب‌های تست

ادمین `09100000009` / `Admin@1234` (`AdminSeeder`). کاربرانِ `DevSeeder` (رمز
`password123`) در دیتابیسِ dev فعلی **وجود ندارند** — با tinker توکن بساز
(`User::find(id)->createToken('qa')->plainTextToken`) و بعد پاکش کن.

## ۷. آنچه اجرا شد (۱۴۰۵/۰۶/۲۸)

| ریپو | کامیت | چه |
|---|---|---|
| بک‌اند `redesign/order-flow-l13` | `81d0d48` + `aeda2e8` (api.json) | مهاجرت `2026_09_19_100000` (۱۴ ستونِ حقوقی + `supplier_documents.kind`)، `StoreSupplierProfileRequest` سخت، `draft`/`needs_correction`، `POST admin/suppliers/{user}/request-correction`، `GET admin/suppliers/{user}/documents/{document}/file`، سه پیامک (`supplier_approved` با `:panel_url`، `supplier_needs_correction`، `supplier_rejected`)، `config('services.supplier_panel.url')` ← env `SUPPLIER_PANEL_URL`، میدل‌ورِ `not.supplier`. ۱۱ تستِ `SupplierQualificationTest` + trait `BuildsSupplierQualification`. سوییت ۱۵۰۲/۱۵۰۲، PHPStan ۰، Pint سالم |
| بک‌آفیس `master` | `bb163de` | «سوپلایرها» = صفِ صلاحیت (پیش‌فرض `pending`، چیپِ پنج‌حالته)، کارتِ «درخواست احراز صلاحیت» + مدارک با دانلودِ احرازشده، دکمه‌ی «نیاز به اصلاح»، نوارِ `live`؛ منو: «درخواست‌های عملیاتی تأمین‌کننده» برای صفِ دیگر |
| پنل `main` | `084da37` + `8dd8fec` (ارقامِ لاتین در شبا/کارت) | `/company` (ویرایشِ مشخصات)، `/finance` (کیف پول، برداشت با Idempotency-Key، حساب‌های بانکی CRUD، تراکنش‌ها)، `kind` روی مدارک، `/status` با دو حالتِ `draft` و `needs_correction` |
| سایت `refactor/consultant-to-elite-yar` | `1a9747c` | `personaConfig.SUPPLIER` = شش تبِ هویتی؛ `/profile` → `supplier_info`؛ فرمِ درخواست با فیلدهای حقوقی + بخشِ مدارک؛ کارت با دلیلِ ادمین و «ورود به پنل» (`VITE_SUPPLIER_PANEL_URL`) |

**اثباتِ زنده:** اسکریپتِ HTTP روی بک‌اندِ واقعی (ارسال بدونِ آگهی تأسیس → ۴۲۲؛ پیوست؛ ارسال → pending؛
افزودن به سبد → ۴۰۳؛ صفِ ادمین؛ نیاز به اصلاح → دلیل در پروفایل؛ ارسالِ دوباره → pending؛ تأیید →
نقش + داشبوردِ پنل ۲۰۰) + کلیکِ واقعی در سه رابط (بک‌آفیس: دیالوگِ نیاز به اصلاح → ۲۰۰ و چیپ؛
پنل: `/status`، `/company` ذخیره ۲۰۰، `/finance`؛ سایت: needs_correction → ویرایش → ارسال → pending).
پیامک‌ها روی صف رفتند و به شماره‌ی آزمایشی ۰۹۱۲۰۰۰۰۷۷۱ فرستاده **نشدند** (allowlist).

**برای دیپلوی (نوید):** `pull` + `migrate` + `db:seed --class=SmsTemplateSeeder` + env `SUPPLIER_PANEL_URL`;
سایت: env `VITE_SUPPLIER_PANEL_URL`.
