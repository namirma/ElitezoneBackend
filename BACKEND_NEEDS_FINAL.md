> ⚠️ **این نسخه از ۱۴۰۵/۰۶/۲۳ منجمد است و به‌روز نمی‌شود؛ مرجع: `../../docs/specs/BACKEND_NEEDS_FINAL.md`** (تصمیمِ مدیریت، پاسخِ ۳-۳ سندِ «موارد باز و ابهامات»؛ حذف نشد به‌خاطرِ قانونِ یک).

# 📋 Backend Needs — Final Handoff to Backend Developer

> ✅ **وضعیت (2026-07-19): تمام ماژول‌های این سند در بک‌اند پیاده شد** (روت‌ها در `routes/api/admin.php` بخش «Content & BackOffice modules»).
> قرارداد نهایی پیاده‌شده در چند مورد با پیشنهاد اولیه این سند فرق دارد.
> **مرجع قرارداد نهایی: [بخش ۰](#۰-قرارداد-واقعی--استخراجشده-از-کد-2026-07-27) در همین سند.**
> فرانت بک‌آفیس با قرارداد نهایی هم‌تراز و با لاگین واقعی ادمین وریفای شده است. بخش ۱۱ (status سوپلایر) نیز رفع شد.
> **2026-07-21:** تمام گپ‌های بخش ۱۲ (۱۲.۱ تا ۱۲.۴ و ۱۲.۷) رفع شدند؛ ۱۲.۶ صرفاً پیشنهاد UX است (اقدام فوری لازم ندارد).
>
> ---
>
> ### 🔄 به‌روزرسانی 2026-07-27
>
> این سند بین ۱۹ تا ۲۷ تیر از کد عقب افتاد. سه اصلاح ساختاری انجام شد:
>
> 1. **ارجاع به `BACKOFFICE_POLISH_PLAN.md` حذف شد** — آن فایل هرگز در repo وجود نداشت
>    (کل درخت جست‌وجو شد)، پس سند به یک مرجعِ گم‌شده اشاره می‌کرد. جایش **بخش ۰** آمد که
>    مستقیماً از کد استخراج شده است.
> 2. **سه بندِ بخش ۱۲ تصحیح شدند** (۱۲.۱، ۱۲.۴، ۱۲.۵). ادعای بند ۱۲.۵ دربارهٔ
>    `national_code = ''` **دو بار باگ واقعی تولید کرد** — تصحیحش را حتماً بخوانید.
> 3. **بخش ۹ (نظرات) عملاً وجود ندارد** — پشت فیچرفلگِ خاموش است و ۴۰۴ می‌دهد. تیم محصول
>    در ۱۴۰۵/۰۵/۰۳ تأیید کرد این فیچر در محصول نیست.
>
> عدد «۳۶۹ تست» در نسخهٔ قبلی این سند کهنه است؛ سوییت فعلی **۶۰۸ تست سبز** است.

> این سند شامل تمام نیازمندی‌هایی است که فرانت‌اند بک‌آفیس برای اتصال کامل به بک‌اند نیاز دارد.
> هر بخش شامل مسیر API، متد HTTP، پارامترهای ورودی و فرمت پاسخ مورد انتظار است.

---

## ۰. قرارداد واقعی — استخراج‌شده از کد (2026-07-27)

> **این بخش جایگزینِ `BACKOFFICE_POLISH_PLAN.md` است.** خط ۴ همین سند آن فایل را «مرجع
> قرارداد نهایی» معرفی می‌کند، ولی **آن فایل در repo وجود ندارد** (کلِ درخت جست‌وجو شد).
> پس مرجع، خودِ کد است و جدول زیر مستقیماً از `routes/api/admin.php`، FormRequestها،
> `config/settings.php` و مدل‌ها استخراج شده — نه از حافظه.
>
> **هرجا بخش‌های ۱ تا ۱۰ پایین با این جدول اختلاف داشتند، این جدول درست است.**
> آن بخش‌ها پیشنهادِ اولیهٔ فرانت‌اند بودند و پیاده‌سازی عمداً در چند مورد از آن‌ها فاصله گرفت.

| # | ماژول | فیلدهای واقعی (create) | اختلاف با متنِ سند |
|---|---|---|---|
| ۱ | Banners | `title*`, `image*` (png/jpg/jpeg/webp ≤4MB), `link`, `position`, `sort_order`, `is_active` | `position` **enum نیست** — رشتهٔ آزاد `max:100`. واژگان home_main/home_banners/category_banner هیچ‌جا اعتبارسنجی نمی‌شود. `sort_order` اضافه شده |
| ۲ | Articles | `title*`, `slug*`, `body*`, `cover`, `excerpt`, `published_at`, `is_active` | نام فیلد فایل **`cover`** است نه `image`/`cover_image`. `excerpt` و `published_at` اضافه شده‌اند |
| ۳ | FAQ | `question*`, `answer*`, `faq_category_id`, `category`, `sort_order`, `is_active` | `faq_category_id` (FK به جدول `faq_categories`) اضافه شد؛ `category`ِ رشته‌ای فقط برای BC مانده |
| ۴ | Settings | `PUT { "settings": { key: value } }` — **۱۹ کلید whitelist** | بدنه **wrap** دارد. کلید ناشناس → **۴۲۲**. از ۵ کلیدِ سند فقط `site_title` و `site_description` وجود دارند؛ `contact_phone`→`support_phone`، `social_instagram`→`instagram_url`، `social_telegram`→`telegram_url` |
| ۵ | Notifications | `subject*`, `body*`, `all` (bool) **یا** `user_ids[]*` | `title`→**`subject`**. `target` (enum) و `link` **وجود ندارند**. پاسخ `{queued, target_count}` است (صف‌دار) |
| ۶ | Reports | `?from=Y-m-d&to=Y-m-d` | `date_from`/`date_to`→**`from`/`to`**. `status` و `format` **وجود ندارند** (همیشه CSV). بالای `reports.stream_max_rows` پاسخ **۲۰۲** است نه فایل؛ دانلود از `GET /admin/reports/downloads/{filename}` |
| ۷ | Admins | `mobile*`, `password*`, `national_code*` (۱۰ رقم، یکتا), `birth_date*` (شمسی `YYYYMMDD`), `name`, `family`, `email`, `permissions[]` | **`role` وجود ندارد** — جایش `permissions[]`. `name`/`family` جدا. دو فیلد هویتی **الزامی** شدند (بند ۱۲.۵ را ببینید) |
| ۸ | Coupons | `code*`, `type*` (`percent`\|`fixed`), `amount*`, `user_id`, `expires_at`, `reason`, `is_active` | `percentage`→**`percent`**، `value`→**`amount`**. `max_uses`، `min_order_amount`، `max_discount_amount`، `used_count` **هیچ‌کدام وجود ندارند** — کوپن **شخصی و یک‌بارمصرف** است (`user_id` + `used_at`) |
| ۹ | Reviews | — | **هیچ روتی ثبت نمی‌شود.** پشت `features.product_reviews` (پیش‌فرض `false`) است، پس پاسخ **۴۰۴** است نه ۴۰۳ |
| ۱۰ | Pages | `title*`, `slug*`, `body*`, `is_active` | `slug` **الزامی** است؛ ساخت خودکار از `title` پیاده نشد و نخواهد شد (`Str::slug` اسلاگ فارسی را خراب می‌کند — قاعده در `CLAUDE.md`) |

`*` = الزامی

### چیزهایی که این سند اصلاً نمی‌شناسد

| مورد | توضیح |
|---|---|
| `admin.permission:{perm}` | هر گروه روت پشت مجوز granular است — «نکات مشترک»ِ پایین فقط `auth:sanctum + role.admin` را می‌گوید |
| `admin.ip` | allowlist مبتنی بر IP روی کلِ `/admin` و `/v1/podium` (خالی = غیرفعال) |
| Soft delete | `banners`/`articles`/`faq`/`pages` قابل بازیابی‌اند: `?trashed=1` برای سطل بازیافت و `POST /{id}/restore` |
| `GET /admin/tickets/agents` | فهرست کارشناسان برای دراپ‌داون ارجاع |
| `GET /admin/wallet/transactions/{id}` | صفحهٔ جزئیات تراکنش |
| `GET /admin/system-health` | صف + پشتیبان + دفتر مالی + سفارش‌های گیرکرده در یک پاسخ |
| `GET /admin/orders/{order}/timeline` | روایت زمانی سفارش (چرخهٔ عمر + وضعیت + پول + اسناد) |
| Order units | `/admin/order-units` (CRUD) و `POST /admin/products/{product}/order-units` |
| FAQ categories / Menu | `/admin/faq-categories` و `/admin/menu-items` + عمومی‌های `GET /faq-categories` و `GET /menu?location=header\|footer` |

### وضعیت بند ۱۱

فیلد `status` روی `SupplierProfileResource` **اضافه شد** — این بند بسته است.

---

## ۱. مدیریت بنرها (Banners) — `api/admin/banners`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/banners?q=&is_active=&page=` | لیست بنرها با صفحه‌بندی |
| POST | `/api/admin/banners` | ایجاد بنر — `multipart/form-data` شامل: `title`, `link`, `position` (enum: home_main, home_banners, category_banner), `is_active`, `image` (file) |
| PUT | `/api/admin/banners/{id}` | آپدیت JSON فیلدهای متنی |
| POST | `/api/admin/banners/{id}` | آپدیت با فایل جدید (multipart — PHP روی PUT فایل نمی‌گیرد) |
| POST | `/api/admin/banners/{id}/toggle` | تغییر وضعیت فعال/غیرفعال |
| DELETE | `/api/admin/banners/{id}` | حذف بنر |

**مایگریشن پیشنهادی:**
```
banners: id, title, link, position (enum), image_path, is_active (bool, default true), timestamps
```

---

## ۲. مقالات وبلاگ (Articles) — `api/admin/articles`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/articles?page=` | لیست مقالات با صفحه‌بندی |
| POST | `/api/admin/articles` | ایجاد مقاله — `multipart/form-data` شامل: `title`, `slug`, `body` (text), `is_active`, `image` (file → cover_image) |
| PUT | `/api/admin/articles/{id}` | آپدیت JSON |
| POST | `/api/admin/articles/{id}` | آپدیت با فایل جدید |
| POST | `/api/admin/articles/{id}/toggle` | تغییر وضعیت انتشار |
| DELETE | `/api/admin/articles/{id}` | حذف مقاله |

**مایگریشن پیشنهادی:**
```
articles: id, title, slug (unique), body (longText), cover_image (nullable), is_active (bool, default false), timestamps
```

**Resource خروجی:** `{ id, title, slug, body, cover_image, is_active, created_at }`

---

## ۳. سوالات متداول (FAQ) — `api/admin/faq`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/faq?page=` | لیست سوالات با صفحه‌بندی |
| POST | `/api/admin/faq` | ایجاد — فیلدها: `category`, `question`, `answer`, `sort_order` (int, default 0), `is_active` |
| PUT | `/api/admin/faq/{id}` | آپدیت |
| POST | `/api/admin/faq/{id}/toggle` | تغییر وضعیت |
| DELETE | `/api/admin/faq/{id}` | حذف |

**مایگریشن پیشنهادی:**
```
faqs: id, category (varchar), question (text), answer (text), sort_order (int, default 0), is_active (bool, default true), timestamps
```

---

## ۴. تنظیمات عمومی (Settings) — `api/admin/settings`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/settings` | دریافت تنظیمات — پاسخ: `{ data: { site_title, site_description, contact_phone, social_instagram, social_telegram } }` |
| PUT | `/api/admin/settings` | آپدیت تنظیمات — بدنه: همان کلیدهای GET به صورت JSON |

**مایگریشن پیشنهادی:**
```
settings: id, key (varchar unique), value (text nullable), timestamps
// یا استفاده از پکیج spatie/laravel-settings
```

---

## ۵. اعلان‌های سیستم (Notifications) — `api/admin/notifications`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/notifications?type=&page=` | لیست اعلان‌های ارسال‌شده با صفحه‌بندی |
| POST | `/api/admin/notifications/send` | ارسال اعلان — فیلدها: `title`, `body`, `target` (enum: all, users, suppliers, elite_yars), `link` (nullable) |
| GET | `/api/admin/notifications/stats` | آمار اعلان‌ها: `{ total_sent, today_sent, unread_count }` |

**مایگریشن پیشنهادی:**
```
admin_notifications: id, title, body, target (enum: all/users/suppliers/elite_yars), link (nullable), admin_id (FK), sent_at (timestamp), timestamps
```

---

## ۶. خروجی‌های CSV گزارش‌ها (Reports) — `api/admin/reports`

> ⚠️ به‌روزرسانی: آمار تحلیلی زنده از `GET api/data/analytics/dashboard` (موجود) خوانده می‌شود؛
> فقط خروجی‌های CSV زیر لازم است — نسخه JSON این گزارش‌ها دیگر نیاز نیست.

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/reports/orders?date_from=&date_to=&status=&format=csv` | خروجی CSV سفارش‌ها (پاسخ: فایل) |
| GET | `/api/admin/reports/financial?date_from=&date_to=&format=csv` | خروجی CSV مالی |
| GET | `/api/admin/reports/users?format=csv` | خروجی CSV کاربران |

**فرمت پاسخ CSV:** فایل با `Content-Type: text/csv` و `Content-Disposition: attachment`

---

## ۷. مدیران سیستم (Admins) — `api/admin/admins`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/admins?q=&is_active=&page=` | لیست مدیران (جست‌وجو در نام/موبایل) |
| POST | `/api/admin/admins` | ایجاد — فیلدها: `name`, `mobile`, `email` (nullable), `password`, `role` (enum: admin, support, operator), `is_active` |
| PUT | `/api/admin/admins/{id}` | آپدیت — اگر `password` ارسال نشود، رمز فعلی حفظ شود |
| POST | `/api/admin/admins/{id}/toggle` | تغییر وضعیت فعال/غیرفعال |
| DELETE | `/api/admin/admins/{id}` | حذف مدیر |

**Resource خروجی:** `{ id, name, mobile, email, role, is_active, created_at }`

---

## ۸. کدهای تخفیف (Coupons) — `api/admin/coupons`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/coupons?q=&is_active=&page=` | لیست کدها (جست‌وجو در code) |
| POST | `/api/admin/coupons` | ایجاد — فیلدها: `code` (unique), `type` (enum: percentage, fixed), `value`, `max_uses` (nullable), `min_order_amount` (nullable), `max_discount_amount` (nullable — فقط درصدی), `expires_at` (nullable, میلادی `Y-m-d`), `is_active` |
| PUT | `/api/admin/coupons/{id}` | آپدیت |
| POST | `/api/admin/coupons/{id}/toggle` | تغییر وضعیت |
| DELETE | `/api/admin/coupons/{id}` | حذف — کد استفاده‌شده در سفارش‌ها 422 برگردد |

**Resource خروجی:** `{ id, code, type, value, max_uses, used_count, min_order_amount, max_discount_amount, expires_at, is_active }`

---

## ۹. نظرات کاربران (Reviews) — `api/admin/reviews`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/reviews?q=&status=&page=` | لیست نظرات — status: pending / approved / rejected (پیش‌فرض: همه) |
| POST | `/api/admin/reviews/{id}/approve` | تأیید نظر |
| POST | `/api/admin/reviews/{id}/reject` | رد نظر — بدنه: `rejection_reason` (الزامی، ≤1000) |

**Resource خروجی:** `{ id, rating, body, status, created_at, user: { id, name }, product: { id, name } }`
(الگوی approve/reject همان صف‌های تأیید الیت یاران است — بعد از تصمیم، فرانت لیست را رفچ می‌کند.)

---

## ۱۰. صفحات استاتیک (Pages) — `api/admin/pages`

| متد | مسیر | توضیح |
|-----|------|-------|
| GET | `/api/admin/pages?q=&page=` | لیست صفحات |
| POST | `/api/admin/pages` | ایجاد — فیلدها: `title`, `slug` (unique — اگر خالی بود از title ساخته شود), `body` (longText), `is_active` |
| PUT | `/api/admin/pages/{id}` | آپدیت |
| POST | `/api/admin/pages/{id}/toggle` | تغییر وضعیت انتشار |
| DELETE | `/api/admin/pages/{id}` | حذف |

**مایگریشن پیشنهادی:**
```
pages: id, title, slug (unique), body (longText), is_active (bool, default true), timestamps
```

---

## ۱۱. گپ کوچک در کد موجود — `SupplierProfileResource` فیلد `status` ندارد

پنل ادمین برای «صف تأیید درخواست‌های سوپلایری» به وضعیت درخواست نیاز دارد.
روت‌های approve/reject و فیلتر `status` در `AdminSupplierController` **موجودند**، ولی
`app/Http/Resources/Supplier/SupplierProfileResource.php` فیلد `status` را serialize نمی‌کند —
بنابراین فرانت نمی‌تواند وضعیت فعلی (pending/approved/rejected) را نمایش دهد.

**فیکس یک‌خطی:** به آرایه `toArray` اضافه شود:
```php
SupplierProfile::STATUS => $this->status,
```
فرانت بک‌آفیس از قبل آماده است و به محض افزودن فیلد، چیپ وضعیت در لیست و جزئیات سوپلایر خودکار ظاهر می‌شود.

---

## ۱۲. راند ۲ — گپ‌های کشف‌شده حین هم‌ترازی فرانت (2026-07-20)

> فرانت بک‌آفیس با تمام تغییرات `FRONTEND_CHANGES_ROUND2.md` هم‌تراز شد
> (تنظیمات whitelist ۱۵کلیدی، CSV صف‌دار 202 + دانلود، اعلان queued/target_count،
> فرم سطح دسترسی permissions[]، مخفی‌سازی منو بر اساس 403، SLA از sla_due_at).
> موارد زیر گپ‌های سمت بک‌اند هستند که در این هم‌ترازی پیدا شدند:

### ۱۲.۱ — ✅ رفع‌شده (2026-07-21) — سریالایز `permissions` در Resource ها

دو جای مختلف، دو نیاز:

| کجا | چرا |
|---|---|
| `GET api/profile` (UserResource — فقط برای دارنده نقش admin) | پنل بتواند منوهای بخش‌های غیرمجاز را **از همان ابتدا** مخفی کند. الان مخفی‌سازی واکنشی است: بعد از اولین 403 هر بخش. |
| `GET api/admin/admins` + `/{user}` | فرم ویرایش مدیر بتواند مجوزهای فعلی را pre-fill کند. الان فرم «سه‌حالته» است (پیش‌فرض: بدون تغییر) چون مجوز فعلی معلوم نیست. |

**پیشنهاد:** در UserResource چیزی مثل
`'permissions' => $this->when($this->hasRole('admin'), fn () => $this->permissions->pluck('name'))`.
فرانت از قبل آماده است: با آمدن این فیلد فقط prefill/فیلتر منو فعال می‌شود.

**نکته قرارداد:** «ادمین بدون هیچ مجوز ثبت‌شده = دسترسی کامل» — پس خروجی `[]` باید از
«ثبت‌نشده» قابل تفکیک باشد (مثلاً ادمین full-access قدیمی همه ۶ مجوز را دارد چون سیدر داده).

> ### ⛔ تصحیح (2026-07-27) — قاعدهٔ «صفر مجوز = دسترسی کامل» **حذف شد**
>
> `EnsureAdminHasPermission` از دور پنجم **fail-closed** است: نبودِ مجوز یعنی ۴۰۳، تمام.
> قاعدهٔ قبلی دقیقاً نیتِ فرم را وارونه می‌کرد — وقتی سوپرادمین برای *محدود کردن* یک ادمین
> `permissions: []` می‌فرستاد، آن ادمین به‌جای از دست دادن دسترسی **دسترسی کامل** می‌گرفت،
> از جمله به تسویهٔ بانکی پادیوم و شارژ دستی کیف‌پول.
>
> سازگاری با ادمین‌های قدیمی یک بار و **به‌صورت داده** حل شد، نه به‌صورت قاعده:
> مایگریشن `2026_07_25_100000_backfill_admin_permissions` به هر ادمینی که صفر مجوز داشت
> همهٔ مجوزها را داد.
>
> **پیامد برای فرانت:** خروجی `[]` دیگر «دسترسی کامل» نیست، یعنی «هیچ دسترسی». تفکیکِ
> «ثبت‌نشده» از «خالی» هم دیگر لازم نیست — هر دو یک معنا دارند. اگر جایی در پنل `[]` را
> full-access تفسیر می‌کنید، همان‌جا باگ است.

**فیکس اعمال‌شده:** `UserResource` حالا `'permissions' => $this->when($canSeeMobile &&
$this->hasRole('admin'), fn () => $this->permissions->pluck('name')->all())` را برمی‌گرداند
(همان گیت حریم‌خصوصی مثل mobile — فقط خودِ کاربر یا ادمین می‌بینند، نه هر مصرف‌کننده‌ی دیگر
UserResource مثل تأمین‌کننده روی آیتم سفارش). `AdminManagerController::index/show` هم رابطه‌ی
`permissions` را eager-load می‌کنند (بدون این، چون هر ردیفِ این لیست حتماً ادمین است، شرط
`hasRole('admin')` همیشه true می‌شد و یک کوئری جدا برای هر ردیف می‌زد — N+1). ۳۶۹ تست بک‌اند
سبز.

### ۱۲.۲ — ✅ رفع‌شده (2026-07-21) — `TicketResource` فیلد `escalated_at` را serialize نمی‌کند

`EscalateOverdueTicketsJob` ستون `tickets.escalated_at` را پر می‌کند و سند راند ۲ می‌گوید
«در لیست ادمین قابل نمایش»، ولی `TicketResource` این فیلد را خروجی نمی‌دهد.

**فیکس یک‌خطی** (کنار بقیه فیلدهای SLA & lifecycle):
```php
'escalated_at' => $this->escalated_at?->toDateTimeString(),
```
فرانت از قبل آماده است — چیپ «اسکاله‌شده» در لیست و جزئیات تیکت به محض آمدن فیلد ظاهر می‌شود.

**فیکس اعمال‌شده:** خط بالا عیناً اضافه شد؛ ضمناً معلوم شد `Ticket` مدل اصلاً `escalated_at`
را cast نمی‌کرد (نه ثابت `ESCALATED_AT`، نه در `$casts`) — یعنی اگر فقط خطِ Resource اضافه
می‌شد، هر تیکتِ واقعاً اسکاله‌شده روی `?->toDateTimeString()` کرش می‌کرد (چون مقدار رشته
خام بود نه Carbon). هر دو اضافه شدند.

### ۱۲.۳ — ✅ رفع‌شده (2026-07-21) — `coupon_code` در پاسخ سفارش عملاً هرگز نمی‌آید (فرانت اصلی)

`OrderResource` دارد: `'coupon_code' => $this->whenLoaded('coupon', …)` — ولی
`Order::resourceRelations()` (که همه کنترلرهای سفارش استفاده می‌کنند، از جمله
`CheckoutController::placeOrder`) relation `coupon` را eager-load **نمی‌کند**.
نتیجه: فیلد در همه پاسخ‌ها silently غایب است. `coupon_discount_amount` مشکلی ندارد (ستون snapshot).

**فیکس:** افزودن `'coupon'` به `resourceRelations()` (یا load صریح در مسیرهای checkout/orders).

**فیکس اعمال‌شده:** عیناً همین — `'coupon'` به آرایه‌ی `resourceRelations()` اضافه شد (یک
belongsTo سبک، batch eager-load می‌شود، N+1 ایجاد نمی‌کند).

### ۱۲.۴ — ✅ رفع‌شده (2026-07-21) — روت‌های `v1/podium` پشت `admin.permission:manage-financial` نیستند (نکته امنیتی)

سطح‌بندی راند ۲، بخش‌های مالی (`financial/wallet/podium-deposits/reports`) را گِیت کرده،
ولی سرویس‌های Podium (`/v1/podium/*` — مانده‌ها، تسویه تکی PAYA/SATNA، استعلام هویت) که
**پول جابه‌جا می‌کنند** فقط `role.admin` دارند. ادمینِ بدون `manage-financial` از UI پنل
(منوی Podium زیر مالی مخفی می‌شود) دستش نمی‌رسد ولی با API مستقیم می‌تواند تسویه بزند.

**پیشنهاد:** `->middleware('admin.permission:manage-financial')` روی گروه v1/podium.

**فیکس اعمال‌شده:** عیناً همین میدل‌ور به آرایه‌ی گروه `v1/podium` اضافه شد. تأیید شد
`EnsureAdminHasPermission` قانون BC (صفر مجوز ثبت‌شده = دسترسی کامل) را رعایت می‌کند، پس
ادمین‌های قدیمی/full-access قفل نمی‌شوند.

> ### ⛔ تصحیح (2026-07-27) — جملهٔ دوم دیگر برقرار نیست
>
> میدل‌ور روی `v1/podium` سرِ جایش هست و درست است. ولی قاعدهٔ BC که این بند به آن تکیه
> می‌کند **حذف شده** — جزئیات در تصحیحِ بند ۱۲.۱. ادمین‌های قدیمی حالا با مایگریشنِ
> backfill محافظت می‌شوند، نه با یک استثنا در میدل‌ور.
>
> ضمناً کلِ گروه `/admin` و `/v1/podium` از دور پنجم `admin.ip` هم دارند
> (allowlist مبتنی بر `ADMIN_IP_WHITELIST`؛ فهرست خالی = غیرفعال).

### ۱۲.۵ — ✅ رفع‌شده (2026-07-20) — باگ بحرانی: ایجاد مدیر جدید همیشه ۵۰۰ می‌گرفت

`POST /admin/admins` روی هر تلاش (بدون ربط به permissions/فیلدهای جدید) با خطای زیر شکست می‌خورد:

```
SQLSTATE[HY000]: General error: 1364 Field 'national_code' doesn't have a default value
(insert into `user_profiles` (`name`,`family`,`email`,`user_id`,...))
```

`user_profiles.national_code` از migration اولیه (`2023_07_29_..._create_user_profiles_table`)
`NOT NULL` و بدون default است؛ `AdminManagerController::store()` هنگام ساخت پروفایل مدیر این
فیلد را اصلاً ارسال نمی‌کند (منطقی هم هست — مدیر داخلی نیازی به کد ملی ندارد). این باگ از قبل
در بک‌اند وجود داشت، ربطی به تغییرات این راند ندارد، ولی چون «افزودن مدیر» یک فیچر اصلی پنل است
که با کردنشیال واقعی امتحان شد و crash کرد، حتماً باید قبل از هر دموی پنل رفع شود.

**فیکس اعمال‌شده (بدون تغییر schema):** در `AdminManagerController::store()`،
`UserProfile::NATIONAL_CODE => ''` به آرایه ایجاد پروفایل اضافه شد (ستون `NOT NULL` است نه
`UNIQUE`، پس رشته خالی برای چند مدیر مشکلی ایجاد نمی‌کند — از migration دوباره‌خوانده شد و
تأیید شد). با کردنشیال واقعی تست شد: `POST /admin/admins` حالا `201` می‌دهد
(`national_code: ""` در پاسخ). `php -l` تمیز.

> ### ⛔ تصحیح (2026-07-27) — بندِ بالا دیگر درست نیست و **دو بار** باگ ساخت
>
> جملهٔ «ستون `NOT NULL` است نه `UNIQUE`، پس رشته خالی برای چند مدیر مشکلی ایجاد
> نمی‌کند» **در زمان نگارش درست بود ولی دو روز بعد باطل شد**: مایگریشن
> `2026_07_21_130000_add_unique_national_code_to_user_profiles` یک ایندکس UNIQUE روی
> همان ستون گذاشت. تأیید از دیتابیس:
>
> ```
> SHOW INDEX FROM user_profiles WHERE Column_name='national_code';
> → user_profiles_national_code_unique   Non_unique = 0
> ```
>
> MySQL رشتهٔ خالی را یک مقدارِ واقعی می‌شمارد، پس **دومین** ردیفی که `''` بنویسد با
> `SQLSTATE[23000] Duplicate entry ''` می‌افتد. الگوی `NATIONAL_CODE => ''` از همین بند
> کپی شد و دو بار شکست:
>
> | کجا | نشانه | رفع |
> |---|---|---|
> | `AdminManagerController::store()` | ساخت **ادمین دوم** ۵۰۰ می‌گرفت | `e78d8b0` + مایگریشن `2026_07_26_120000` (ستون nullable شد و `''`های موجود به NULL تبدیل شدند) |
> | `PlatformWalletUserSeeder` | حساب دومِ پلتفرم (WVAT جدا از WSSG) ساخته نمی‌شد و چون این سیدر **اولین** مورد `DatabaseSeeder` است، کلِ `db:seed` می‌خوابید | `3ed6074` |
>
> **قاعدهٔ درست:** حسابی که واقعاً کد ملی ندارد باید `NULL` بگیرد، نه `''`. MySQL در ایندکس
> UNIQUE چند NULL را می‌پذیرد. هرگز `''` را برای عبور از `NOT NULL` ننویسید.
>
> ضمناً `POST /admin/admins` از `e78d8b0` به بعد `national_code` (۱۰ رقم، یکتا) و
> `birth_date` (شمسی `YYYYMMDD`) را **الزامی** می‌خواهد — بدون آن‌ها ادمینِ ساخته‌شده
> اصلاً نمی‌توانست وارد شود (`AuthController::checkPassword` حساب با این دو ستونِ خالی را
> رد می‌کند).

### ۱۲.۶ — پیشنهاد اندپوینت سبک برای «شرط خرید تأییدشده» نظرات (فرانت اصلی)

فعلاً هیچ سیگنالی سمت کلاینت نیست که «آیا این کاربر مجاز به ثبت نظر روی این محصول است». فرانت
فعلاً submit را optimistic انجام می‌دهد و پیام 403 دقیق شما (`reviews.purchase_required`) را
نشان می‌دهد — این کار می‌کند و درست است، صرفاً UX ایده‌آل نیست (فرم را از قبل مخفی نمی‌کند).

**پیشنهاد:** `GET /products/{product:cuid}/reviews/can-review` (auth) → `{ can_review: bool }`،
دقیقاً با منطق همان متد `hasDeliveredPurchase()` که الان `private` داخل `ProductReviewController`
نوشته شده — فقط قابل‌فراخوانی (استخراج به متد public یا یک Action کوچک) شود.

**چرا نه روی `ProductResource` یا `ProductDetailResource`:**
- `ProductResource` روی `GET /products` (لیست صفحه‌بندی‌شده، پرترافیک) سرو می‌شود — افزودن یک
  کوئری سفارش per-row یعنی N+1 روی یک اندپوینت عمومی.
- `ProductDetailResource` عملاً در مسیر دیتای صفحه محصول (`ProductDetailsPage.jsx`) قرار ندارد —
  آن صفحه از `GET /products` + `GET /products/{cuid}/variants` می‌خواند، نه از یک اندپوینت
  detail مجزا؛ پس فیلدی که به `ProductDetailResource` اضافه شود اصلاً به این صفحه نمی‌رسد.

اندپوینت مجزا (فقط برای محصولِ در حال نمایش، نه کل لیست) هیچ‌کدام از این دو مشکل را ندارد.

### ۱۲.۷ — ✅ رفع‌شده (2026-07-21) — پاسخ ثبت/تأیید/رد نظر، user و product را خالی می‌گذاشت

همان الگوی ۱۲.۳: `ProductReviewResource` دارد `'user' => $this->whenLoaded('user', …)` و
`'product' => $this->whenLoaded('product', …)` — درست کار می‌کند در `index`/`show` (که
`with(['user.profile', 'product'])` دارند)، ولی در سه‌جای دیگر که `$review->refresh()`
مستقیم به Resource می‌رود (`ProductReviewController::store`،
`AdminReviewController::approve/reject`)، چون `$review` از route model binding عریان
می‌آید (بدون هیچ relation لودشده)، `refresh()` چیزی برای رفرش کردن نداشت — پاسخ این سه اکشن
همیشه بدون `user`/`product` بود. **فعلاً بی‌اثر روی UX** (بک‌آفیس بعد از approve/reject فقط
toast می‌زند و لیست را رفچ می‌کند؛ فرانت اصلی هم بعد از ثبت نظر فقط پیام را می‌خواند) — ولی
برای هر مصرف آینده‌ی این پاسخ (مثلاً آپدیت optimistic ردیف بدون رفچ کامل) یک باگ خاموش بود.

**فیکس اعمال‌شده:** هر سه‌جا به `$review->refresh()->load(['user.profile', 'product'])`
تغییر کرد. ۱۰ تست `ProductReviewTest` سبز.

### ۱۲.۸ — یادداشت زیرساخت: دیتابیس `laravel_testing` ۹ مایگریشن عقب بود

حین اجرای کامل سوییت تست برای تأیید بی‌خطر بودن فیکس‌های این نشست، **۳۳ تست** با
`QueryException` («Unknown column» / «Data too long for column secret» و…) fail شدند —
هیچ‌کدام ربطی به این نشست نداشتند. علت: `laravel_testing` از `2026_07_18` به بعد (۹ مایگریشن:
agreements آنلاین، جداول محتوای ادمین، نظرات محصول، `coupons.is_active`,
`orders.coupon_id`, `tickets.escalated_at`, پهن‌کردن `users.secret`) migrate نشده بود —
مطابق راهنمای همین سند (`CLAUDE.md`)، این دیتابیس جدا از دیتابیس دولوپمنت است و باید
دستی/جدا migrate شود. با `DB_DATABASE=laravel_testing php artisan migrate --force` رفع شد؛
۳۲ از ۳۳ تست فوراً سبز شدند. تست باقی‌مانده (`TicketLifecycleTest`) یک اسرشن flaky و
وابسته‌به‌ساعت اجرا بود (مقایسه با `now()->addHours(9)` وقتی «الان» قبل از شروع ساعات کاری
باشد) — به بازه‌ی روزانه (`addDays(4)`) که به تقویم ساعات کاری/تعطیلی جمعه حساس نیست تغییر
کرد. **نتیجه نهایی: ۳۶۹ از ۳۶۹ تست سبز.**

---

## ۱۴. راند ۳ — بازطراحیِ بخشِ تسویه‌حسابِ صفحهٔ `/order` (سایت اصلی، 2026-08-02)

> **این سند در repo مسیرش `api/BACKEND_NEEDS_FINAL.md` است، نه `BackOffice/BACKEND_NEEDS_FINAL.md`.**
> درخواستِ فرانت به «`BackOffice/BACKEND_NEEDS_FINAL.md` بخش ۱۴» ارجاع می‌داد؛ چنین
> فایلی روی این ماشین وجود ندارد (کلِ `htdocs` جست‌وجو شد) و نسخهٔ داخلِ repo تا
> پیش از این فقط تا بخش ۱۲ می‌رفت. یعنی جزئیاتِ «§۱۴.۱ تا §۱۴.۴» هرگز به بک‌اند
> نرسیده بود و پیاده‌سازی از روی خلاصهٔ خودِ درخواست انجام شد. بخش ۱۴ از این پس
> همین‌جاست.

### ۱۴.۱ — ✅ پیاده‌شد — پیش‌فاکتور از سبدِ هنوز ثبت‌نشده

```
GET /api/cart/pre-invoice[?address_id=<int>]     (auth:sanctum)
→ 200 application/pdf   (Content-Disposition: attachment)
→ 400 سبد خالی است
→ 403 آدرس متعلق به کاربر نیست
→ 401 مهمان
```

- **هیچ سفارشی ساخته نمی‌شود**، **هیچ رکورد `Invoice`ای ثبت نمی‌شود**، و سبد
  دست‌نخورده می‌ماند. تا پیش از این تنها راه، ثبتِ سفارشِ واقعی و دانلود از
  `/profile/orders/{order}/invoices/initial` بود — که موجودی کم می‌کرد و یک سفارشِ
  دوساعته می‌ساخت که باید لغو می‌شد.
- `address_id` **اختیاری** است و فقط برای آوردنِ کرایهٔ حمل به سند لازم است؛ بدونِ
  آن ردیفِ حمل صفر است. محاسبه از همان `CartShippingQuoteService`ی می‌آید که
  `PlaceOrderAction` استفاده می‌کند، پس برآورد با کرایهٔ واقعی برابر است.
- مبلغِ روی PDF **دقیقاً** `totals.total`ِ پاسخِ `GET /api/cart` است (هر دو از
  `CartPricingCalculator` می‌آیند) — پین‌شده در تست.
- **شمارهٔ سند نیست.** خروجی شناسهٔ آشکارا موقتِ `PRE-<Ymd>-<cartId>` دارد. اگر از
  `DocumentSequenceService` شماره می‌گرفتیم، چون چیزی ذخیره نمی‌شود آن شماره رزرو
  نمی‌شد و بعداً روی یک سندِ واقعیِ بی‌ربط می‌نشست — یعنی دو مدرک با یک شماره.

کد: `CartController::preInvoice` → `Actions\Cart\BuildCartPreInvoiceAction` →
`InvoiceGenerator::previewPreInvoice()` (نقطهٔ ورودِ جدید که نه رکورد می‌سازد نه
شماره مصرف می‌کند). تست: `Tests\Feature\Cart\CartPreInvoiceTest`.

### ۱۴.۲ — ✅ پیاده‌شد — `price_valid_until` در پاسخِ سبد

```jsonc
// GET /api/cart  →  data
{
  "cart":   { ... },
  "totals": { ... },
  "price_valid_until": null        // یا "2026-08-02T14:30:00+03:30"
}
```

- **کلید همیشه هست**؛ `null` یعنی «مهلتی اعلام نشده»، نه «منقضی». فرانت باید
  بتواند بدونِ چکِ وجودِ کلید مستقیم بخواندش.
- مقدارِ نهایی قرار است از **API تأمین‌کننده** بیاید. تنها نقطهٔ اتصال:
  `Services\Cart\CartPriceValidityResolver::resolve()` — وقتی آن API آمد فقط همین
  متد عوض می‌شود، نه قراردادِ پاسخ.
- تا آن زمان مقدار از `config('cart.price_validity_ttl_minutes')`
  (env: `CART_PRICE_VALIDITY_TTL_MINUTES`) می‌آید که **پیش‌فرضش خالی است** — یعنی
  رفتارِ امروز `null` است و هیچ مهلتِ ساختگی‌ای اعلام نمی‌شود.
- مبنای محاسبه `carts.updated_at` است نه لحظهٔ درخواست، وگرنه هر refresh مهلت را
  جلو می‌برد و اعتبار عملاً هرگز تمام نمی‌شود.

تست: `Tests\Feature\Cart\CartPriceValidityTest`.

### ۱۴.۳ — ✅ پیاده‌شد — `paymentInfo.is_active` دیگر ثابتِ `true` نیست

`GET /api/checkout/payment/{order}` وضعیتِ هر روش را واقعاً محاسبه می‌کند:

| روش | `is_active` از کجا می‌آید |
|---|---|
| پرداخت اینترنتی (`gateway`) | `isConfigured()`ِ درایورِ `config('payment.online_gateway')` — پیش‌فرض `pasargad` |
| کیف پول (`wallet`) | همیشه `true` (رِیلِ داخلی). «پول کافی دارد؟» جداگانه در `has_credit` |
| حضور در بانک (`bank_transfer`) | `config('payment.bank_transfer_enabled')` |

- امروز درگاهِ کارت بانکی **غیرفعال** برمی‌گردد چون `PASARGAD_*` در `.env` خالی
  است. روزی که مجوز آمد و آن متغیرها پر شدند، این روش **بدون یک خط تغییر در کد**
  فعال می‌شود.
- `isConfigured()` به `PaymentGatewayInterface` اضافه شد (قاعدهٔ `CLAUDE.md`:
  هرگز متدی از درگاه صدا نزن که interface اعلامش نکرده)؛ هر سه درایور پیاده‌اش
  می‌کنند. نامِ درگاهِ ناشناخته در کانفیگ → `false` و لاگِ warning، نه ۵۰۰.

تست: `Tests\Feature\Checkout\PaymentInfoGatewayStateTest`.

### ۱۴.۴ — 🟡 پاسخ: باگ نیست، ولی یک گپِ واقعیِ «نمایش در برابر واقعیت» هست

سؤال: چرا `payment_of_owner` (POO) با `final_amount` یکی نیست؟

    POO          = 344,456,640 ریال   ← آنچه از کیف پول کم می‌شود
    final_amount = 345,840,000 ریال   ← آنچه به مشتری نشان داده می‌شود
    نسبت         = 0.996 = 1 − 0.4/100

**اختلاف دقیقاً ضریبِ `%OW` است** — «تخفیفِ خریدار»، سهمِ ۴۰٪ خریدار از کارمزدِ
تراکنش (`%TC = ۱٪`)، یعنی ۰٫۴٪. فرمولِ POO در سند طراحی «پروسه خرید» ضریبِ
`(1 − %OW)` دارد (`SettlementCalculatorService::calculatePOO`)، ولی `final_amount`
که `PlaceOrderAction` می‌نویسد یک جمعِ حسابداریِ ساده است و این ضریب را ندارد:

    final_amount = subtotal − discount + vat + shipping

پس **محاسبهٔ POO درست است و باگی در آن نیست**، و در این حالت خریدار *کمتر* از عددِ
نمایش‌داده‌شده می‌پردازد — نه بیشتر. تصمیم دربارهٔ اینکه کدام عدد به مشتری نشان داده
شود (POO، یا اعمالِ %OW در `final_amount`) **محصولی است نه فنی** و باز است.

#### ✅ رفع‌شد — واگراییِ دوم: مالیاتِ کرایه

حینِ همین بررسی یک باگِ **واقعی** پیدا شد که در تستِ فرانت پنهان مانده بود، چون
هیچ ردیفِ `shipping_rates` وارد نشده بود و کرایه صفر می‌ماند:

فرمولِ POO با `trans_vat = true` مالیات را روی `Trans + OP×W` می‌بندد، ولی
`final_amount` مالیات را فقط روی **کالا** حساب می‌کرد و کرایه را بدونِ مالیات اضافه
می‌کرد. نقطهٔ سربه‌سر پایین است — با `%OW = ۰٫۴٪` و مالیاتِ ۱۰٪، از حدودِ
**کرایه = ۴٫۶٪ ارزشِ کالا** به بعد `POO` از عددِ نمایش‌داده‌شده جلو می‌زد:

```
کالا ۳۱۴,۴۰۰,۰۰۰ + کرایه ۲۰,۰۰۰,۰۰۰
  نمایش (قبل از رفع) = 365,840,000
  POO                = 366,357,504   ← ۵۱۷,۵۰۴ ریال بیشتر از آنچه مشتری دیده
```

کرایهٔ میلگرد معمولاً از این نسبت بیشتر است، پس این حالتِ نظری نبود — و برخلافِ
اختلافِ `%OW`، این یکی **علیهِ مشتری** بود.

**رفع:** `final_amount` حالا مالیاتِ کرایه را طبقِ همان فلگِ `trans_vat`ِ قراردادِ
تأمین‌کننده حساب می‌کند که POO با آن کار می‌کند. قاعده در
`SettlementCalculatorService::vatOnTrans()` / `transVatFlagForSupplier()` متمرکز است
و هر دو مسیرِ «عددِ نمایش‌داده‌شده» از آن می‌خوانند: `PlaceOrderAction` (سفارشِ واقعی)
و `BuildCartPreInvoiceAction` (پیش‌فاکتور). مالیاتِ کرایه در `orders.vat_amount`
تجمیع می‌شود (این ستون فقط نمایشی است — هیچ محاسبهٔ تسویه‌ای آن را نمی‌خواند، پس
مایگریشن یا ستونِ جدید لازم نشد).

نتیجه: در هر چهار حالتِ فرمول `final_amount ≥ POO` برقرار است و تنها اختلافِ
باقی‌مانده همان تخفیفِ `%OW` است — یک اختلافِ توضیح‌دادنی و **همیشه به سودِ خریدار**.

`Tests\Feature\Financial\PooVersusDisplayedAmountTest` هر پنج رابطه را پین می‌کند
(ضریبِ %OW با اعدادِ دقیقِ گزارشِ فرانت، «هرگز بیشتر از مبلغِ نمایش‌داده‌شده»،
`نمایش ≥ POO` در هر ۴ حالتِ ±TC/±VAT × ۴ کرایهٔ واقع‌بینانه، رفتارِ قراردادِ `−VAT`،
و پیش‌فرضِ فلگ) تا اگر کسی یکی از دو طرف را عوض کرد، سوییت سر و صدا کند.

---

## نکات مشترک

1. **Middleware:** تمام روت‌ها باید زیر میدل‌ور `auth:sanctum` + `role.admin` باشند.
2. **صفحه‌بندی:** از `ResourceCollection` با فرمت `{ data, meta, links }` استفاده شود.
3. **خطاهای اعتبارسنجی:** فرمت استاندارد لاراول: `{ message, errors: { field: [msg] } }`
4. **Toggle:** پاسخ `POST /{id}/toggle` باید رکورد به‌روز‌شده را در `{ data: {...} }` برگرداند.
5. **آپلود فایل:** از `multipart/form-data` پشتیبانی شود و در ذخیره تصاویر، از دیسک `public` لاراول استفاده شود تا مسیر `/storage/...` قابل سرو باشد.
