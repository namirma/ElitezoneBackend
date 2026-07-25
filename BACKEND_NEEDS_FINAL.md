# 📋 Backend Needs — Final Handoff to Backend Developer

> ✅ **وضعیت (2026-07-19): تمام ماژول‌های این سند در بک‌اند پیاده شد** (روت‌ها در `routes/api/admin.php` بخش «Content & BackOffice modules»).
> قرارداد نهایی پیاده‌شده در چند مورد با پیشنهاد اولیه این سند فرق دارد — **مرجع قرارداد نهایی: جدول فاز A در `BACKOFFICE_POLISH_PLAN.md`** (admins: name/family بدون role؛ coupons: type=percent/fixed + amount + user_id؛ notifications: subject/body + all|user_ids؛ reviews: فیلد comment؛ settings: PUT با wrap `{settings}`؛ articles: فیلد cover + excerpt؛ banners: sort_order؛ reports: فقط CSV با فیلتر from/to).
> فرانت بک‌آفیس با قرارداد نهایی هم‌تراز و با لاگین واقعی ادمین وریفای شده است. بخش ۱۱ (status سوپلایر) نیز رفع شد.
> **2026-07-21:** تمام گپ‌های بخش ۱۲ (۱۲.۱ تا ۱۲.۴ و ۱۲.۷) رفع شدند؛ ۱۲.۶ صرفاً پیشنهاد UX است (اقدام فوری لازم ندارد).
> دیتابیس `laravel_testing` migrate شد (۱۲.۸) — سوییت کامل بک‌اند: **۳۶۹ از ۳۶۹ تست سبز**.

> این سند شامل تمام نیازمندی‌هایی است که فرانت‌اند بک‌آفیس برای اتصال کامل به بک‌اند نیاز دارد.
> هر بخش شامل مسیر API، متد HTTP، پارامترهای ورودی و فرمت پاسخ مورد انتظار است.

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

## نکات مشترک

1. **Middleware:** تمام روت‌ها باید زیر میدل‌ور `auth:sanctum` + `role.admin` باشند.
2. **صفحه‌بندی:** از `ResourceCollection` با فرمت `{ data, meta, links }` استفاده شود.
3. **خطاهای اعتبارسنجی:** فرمت استاندارد لاراول: `{ message, errors: { field: [msg] } }`
4. **Toggle:** پاسخ `POST /{id}/toggle` باید رکورد به‌روز‌شده را در `{ data: {...} }` برگرداند.
5. **آپلود فایل:** از `multipart/form-data` پشتیبانی شود و در ذخیره تصاویر، از دیسک `public` لاراول استفاده شود تا مسیر `/storage/...` قابل سرو باشد.
