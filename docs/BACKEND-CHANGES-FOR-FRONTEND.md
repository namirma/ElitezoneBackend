# تغییرات بک‌اند برای فرانت — گزارش جامع

> **مخاطب:** تیم فرانت (پنل کاربر، بک‌آفیس، پنل تأمین‌کننده)
> **برنچ:** `redesign/order-flow-l10` · قرینه: `redesign/order-flow-l13`
> **پوشش:** همه‌ی تغییراتی که از `03dfd54` تا امروز روی قرارداد API اثر دارند
> **وضعیت تست:** L10 ۱۰۷۰ سبز · L13 سبز · PHPStan تمیز روی هر دو
> **قرارداد ماشین‌خوان:** `api.json` در ریشه‌ی ریپو (CI بایت‌به‌بایت گیتش می‌کند)

این سند جای همه‌ی پیام‌های پراکنده را می‌گیرد. ترتیبش بر اساس **کاری که شما باید
بکنید** است، نه بر اساس تاریخ.

---

## بخش ۰ — فهرست اقدام

| # | موضوع | وضعیت | کار شما |
|---|---|---|---|
| ۱ | صفحه‌بندی همه‌ی لیست‌ها | 🟢 افزایشی | `meta` و `links` حالا هست — می‌توانید ورق بزنید |
| ۲ | `payment-options` حالا کرایه دارد | 🔴 مهم | `settlementOptions.js` را جمع کنید |
| ۳ | فیلتر برچسبی حذف شد | 🟢 انجام شده | شما قبلاً مهاجرت کرده‌اید |
| ۴ | `payer` در `OrderResource` | 🟢 افزایشی | حدس‌زدن از روی ذی‌نفع را بردارید |
| ۵ | `reject` تأمین‌کننده `reason` می‌خواهد | 🔴 شکستنی | بک‌آفیس: فیلد دلیل اضافه کنید |
| ۶ | پنل تأمین‌کننده — ۱۲ اندپوینت تازه | 🟢 افزایشی | ساخت UI |
| ۷ | گزارش‌های مالی بک‌آفیس درست شدند | 🟢 اطلاع | تب‌هایی که خالی بودند حالا داده دارند |
| ۸ | مبالغ بدون اعشار | 🟡 قبلاً اعلام شد | تأیید کردید بی‌اثر است |

---

## بخش ۱ — قرارداد پاسخ

### ۱.۱ صفحه‌بندی — `meta` و `links` اضافه شدند (شکستنی نیست)

**باگ:** ۴۹ اندپوینت لیست الگوی `success(Resource::collection($paginator))` داشتند.
وقتی یک `ResourceCollection` تودرتو سریالایز شود، لاراول فقط `toArray()` را صدا
می‌زند و متدی که `links`/`meta` را می‌سازد هرگز اجرا نمی‌شود.

یعنی کوئری `COUNT` اجرا می‌شد، `LIMIT/OFFSET` اعمال می‌شد، و نتیجه‌اش دور ریخته
می‌شد. **هیچ لیستی در بک‌آفیس و پنل تأمین‌کننده قابل ورق‌زدن نبود** و چون پاسخ
۲۰۰ و سالم به‌نظر می‌رسید، هیچ‌جا خطایی دیده نمی‌شد.

**شکل جدید — `data` دست‌نخورده، دو کلید کنارش:**

```json
{
  "status": true,
  "message": "…",
  "data": [ /* آرایه‌ی مستقیم، مثل قبل */ ],
  "meta":  { "current_page": 1, "last_page": 7, "per_page": 20, "total": 133, "from": 1, "to": 20 },
  "links": { "first_page_url": "…", "last_page_url": "…", "prev_page_url": null, "next_page_url": "…" }
}
```

> هر دو سند قبلی شکل **تودرتوی** `data: {data, links, meta}` را وعده داده بودند
> که کد هرگز نمی‌داد. تودرتو کردن یعنی شکستن ۴۹ اندپوینت برای قراردادی که
> مصرف‌کننده ندارد — پس `meta`/`links` **هم‌سطح** `data` نشستند. سنجیده شد: هر
> ۱۰۱۶ تست موجود بدون یک خط تغییر سبز ماندند.

**لیست غیرصفحه‌بندی‌شده** (مثل `GET /faq-categories`) `meta` نمی‌گیرد — شکلش
دست‌نخورده است.

### ⚠️ ۱.۲ یک ناهمسانی که عمداً باقی ماند

| اندپوینت | شکل |
|---|---|
| `GET /api/products` | `data.data` + `data.meta` (تودرتو) |
| `GET /api/products/{cuid}/variants` | `data.data` + `data.meta` (تودرتو) |
| بقیه‌ی لیست‌ها (ادمین، تأمین‌کننده، سفارش‌ها…) | `data` + `meta` هم‌سطح |

آن دو از قبل `->response()->getData(true)` داشتند. یکدست‌کردنشان فرانتی را که
تازه مهاجرت کرده می‌شکند، پس دست نخوردند. **هر وقت آماده بودید بگویید تا همگرا
شود.**

---

## بخش ۲ — مسیر پرداخت

### 🔴 ۲.۱ `payment-options` حالا کرایه را می‌شمارد

**باگ (گزارش خودتان، §۵):** `CartPricingCalculator` کرایه را `'0.0000'` هاردکد
می‌کند، پس شاخه روی عدد **بدون کرایه** تصمیم گرفته می‌شد — در حالی که
`PlaceOrderAction` می‌نویسد `subtotal − discount + totalVat + shippingCost`.

دو پیامد، و دومی بدتر:

1. `gateway_amount` به‌اندازه‌ی کرایه کم بود؛ کاربری که دقیقاً همان را شارژ
   می‌کرد سرِ پرداخت باز هم کسری داشت.
2. **خودِ `branch` می‌توانست غلط باشد** — کسی که موجودی‌اش کالا را می‌پوشاند
   ولی کالا+کرایه را نه، شاخه‌ی `wallet` می‌گرفت و دکمه‌ای می‌دید که قطعاً ۴۲۲
   می‌شد.

**حالا:**

```
GET /api/checkout/payment-options?address_id=3      ← address_id اختیاری است
```

ترتیب حل مقصد: `address_id` صریح ← `carts.address_id` ← آدرس پیش‌فرض کاربر.

```json
{
  "branch": "gateway",
  "final_amount": "1246750000",
  "wallet_balance": "…", "wallet_amount": "…",
  "gateway_amount": "493746100",
  "reserve_amount": "…", "reserve_available": false,
  "reserve_warning": { "show": false, "…": "…" },
  "breakdown": {
    "goods_with_vat":    "1196250000",
    "shipping_with_vat": "50500000",
    "coupon_discount":   "0",
    "shipping_included": true
  }
}
```

`shipping_included: false` یعنی مقصدی پیدا نشد یا نرخ حملی برای آن مسیر وارد
نشده — **پایه‌ی ناقصی که می‌گوید ناقص است**، به‌جای عددی که وانمود می‌کند کامل
است.

> ✅ **`settlementOptions.js` و کامنت DELETE-ON-CONVERGENCE می‌تواند جمع شود.**
> قاعده‌ای که خودتان گذاشتید («بک‌اند تعیین می‌کند کدام اکشن وجود دارد») حالا
> کامل برقرار است.

### ۲.۲ درخت تصمیم — یادآوری

| شرط | `branch` | اقدام |
|---|---|---|
| `Final ≤ Wallet` | `wallet` | `POST /checkout/pay` |
| `Final ≤ Wallet + Shaparak` | `gateway` | اول کیف پول را شارژ کن، بعد `POST /checkout/pay` |
| `Final > Wallet + Shaparak` | `reserve` | `POST /checkout/reserve` |

معیار **توان پرداخت همین کاربر** است، نه آستانه‌ی سراسری — یک سفارش ثابت برای
دو کاربر دو شاخه می‌گیرد، پس شاخه را بین کاربران کش نکنید.

> **رزرو روش پرداخت دلخواه نیست.** `POST /checkout/reserve` در شاخه‌ی الف/ب با
> ۴۲۲ `checkout.reserve_not_available` رد می‌شود. تأییدتان درباره‌ی کارت «با
> حضور در بانک» درست بود: آن یک **واریز به کیف پول** است.

`POST /checkout/reserve` وقتی `reserve_warning.show` باشد `confirm_short_window: true`
می‌خواهد، وگرنه ۴۲۲.

---

## بخش ۳ — کاتالوگ و فیلتر

### ⛔️ ۳.۱ فیلتر برچسبی حذف شد

سند قبلی می‌گفت `?سایز (قطر)=100` «بدون تغییر فرانت کار می‌کند». **غلط بود** و
اندازه‌گیری شما درست: آن کد با `attributes.name` تطبیق می‌داد و در کاتالوگ واقعی
`name` نام ماشینی انگلیسی است (`size`, `grade`) در حالی که سایدبار `display_name`
فارسی می‌فرستاد.

تستِ ما سبز بود چون سیدر نمایشی `name` و `display_name` را یکی می‌گذارد — روی
داده‌ای می‌گذشت که شکل داده‌ی واقعی را نداشت.

مسیر **برداشته شد** نه اینکه روی `display_name` سوار شود: مصرف‌کننده‌ای ندارد، و
مسیری که ادعا می‌کند کار می‌کند ولی نمی‌کند از نبودنش بدتر است.

### ۳.۲ قرارداد فیلتر

```
GET /api/products/{cuid}/variants?filter_values[]=57&filter_values[]=93&per_page=24
GET /api/products?category=2&filter_values[]=57
```

id از `values[].id` در پاسخ `GET /api/products/{cuid}/filters` می‌آید:

| در پاسخ | یعنی | در سایدبار |
|---|---|---|
| `data[]` | دسته‌ی کلی | معمولاً نمایش داده نمی‌شود |
| `data[].attributes[]` | گروه | **سرتیتر** — `display_name` |
| `…values[]` | گزینه | **چک‌باکس** — برچسب `display_value`، **id همان است که می‌فرستید** |

**معنای انتخاب چندتایی:** داخل یک گروه `OR`، بین گروه‌ها `AND`. گروه‌بندی سمت
سرور از روی خود داده انجام می‌شود؛ شما همان فهرست تخت را می‌فرستید.

> `filter_categories[]` برای تیک گزینه نیست — روی **گروه** فیلتر می‌کند و
> فرستادن id یک مقدار در آن ۴۲۲ می‌گیرد.

---

## بخش ۴ — سفارش

### ۴.۱ `payer` اضافه شد

**درخواست شما (§۹.۲).** تأیید تحویل payer-only است ولی `OrderResource` نه
`payer_user_id` می‌داد نه `beneficiary_user_id`، پس مجبور بودید حدس بزنید.

```json
"payer": { "id": 42, "is_self": true }
```

روی سفارش معمولی `payer_user_id` خالی است و پرداخت‌کننده همان ثبت‌کننده است؛
`is_self` این را یکجا جواب می‌دهد.

### ۴.۲ نام‌های خالی درست شدند

`users` هیچ ستون `name` ندارد — نام در `user_profiles.name` است. سه جا اصلاح شد:
`beneficiary_name` و `elite_yar_name` در لینک پرداخت، و `prepared_by.name`.

### ۴.۳ هشت وضعیت، و بس

```
reserved · processing · awaiting_weight_correction · shipped
partially_delivered · delivered · returned_to_origin · cancelled
```

وضعیت‌های حذف‌شده با مهاجرت نگاشت می‌شوند. `pending` که جا افتاده بود هم اضافه
شد — **با یک تفاوت از پیشنهاد شما:** ردیفی که `payment_status = paid` است ولی
هنوز `pending` مانده به `processing` می‌رود نه `cancelled`، چون لغوش پول
گرفته‌شده را پشت برچسب «لغو شده» پنهان می‌کرد بی‌آنکه هیچ لگ بازگشتی اجرا شود.

### ۴.۴ باسکول — اصلاح وزن حالا ممکن است

```
PATCH /api/profile/orders/{order}/shipments/{shipment}/weight
{ "actual_weight": 24500 }
```

فقط **پیش از تسویه**. بعد از آن ۴۰۹ (`shipments.weight_locked_after_settlement`)
چون پول روی همان عدد جابه‌جا شده.

ثبت بارنامه بدون تغییر است، با یک افزوده:

```
POST /api/profile/orders/{order}/shipments/{shipment}/waybill
{ "driver_id": 12, "waybill_ref": "…", "actual_weight": 24500, "is_final": true }
```

`driver_id` اختیاری است و از دفترچه‌ی راننده پر می‌کند؛ با آمدنش `driver_name` و
`vehicle_plate` دیگر الزامی نیستند. مسیر متنی نشکسته.

> `is_final: true` ماشه‌ی **تسویه‌ی مالی نهایی** است و برگشت‌پذیر نیست — در UI
> تأیید جدا بگیرید.

---

## بخش ۵ — پنل تأمین‌کننده

### ۵.۱ پروفایل — چهار فیلد که قبلاً هیچ‌جا نبودند

`GET /api/profile/supplier` حالا برمی‌گرداند:

```json
{
  "reserve_time": "…",
  "reserve_auto_extend_minutes": 120,
  "reserve_grace_hours": 5,
  "price_window_valid": true,
  "trans_commission": true,
  "trans_vat": true,
  "rejection_reason": null
}
```

`reserve_time` مهم‌ترین تنظیم تأمین‌کننده است: وقتی بگذرد **کل کالای او از
کاتالوگ حذف می‌شود**. قبلاً می‌نوشتش و هیچ پاسخی برنمی‌گرداندش.
`price_window_valid` از همان تابعی می‌آید که کاتالوگ تصمیمش را با آن می‌گیرد.

### ۵.۲ آپلود گروهی

```
POST /api/profile/supplier/prices/bulk
POST /api/profile/supplier/stocks/bulk     ← فقط روی L10
```

```json
{ "rows": [
  { "product_variant_id": 11, "factory_id": 3, "factory_location_id": 7,
    "general_price": 1000000, "special_price": 950000 }
] }
```

پاسخ: `{ "created": 12, "updated": 40, "total": 52 }`

سه قاعده: **upsert** (ارسال دوباره‌ی فهرست به‌روز می‌کند)، **همه‌یا‌هیچ** (فهرست
نیمه‌اعمال‌شده بدتر از ردشده است)، و **خطا به‌ازای شماره‌ی ردیف**:

```json
{ "status": false, "errors": { "rows": { "12": "این کارخانه متعلق به شما نیست." } } }
```

سقف ۵۰۰ ردیف، `throttle:10,1`.

> **ورودی JSON است نه فایل CSV، و این یک تصمیم است:** فایل اکسل فارسی سه تله‌ی
> شناخته‌شده دارد (BOM، جداکننده‌ی `;`، انکودینگ غیر UTF-8) که هر سه در مرورگر
> بهتر حل می‌شوند. مهم‌تر: با پارس سمت کلاینت می‌توانید خطای «سطر ۱۲» را کنار
> همان سطر جدول نشان دهید.

### ۵.۳ مدارک درخواست

```
GET    /api/profile/supplier/documents
POST   /api/profile/supplier/documents          (title + file: pdf/jpg/png/webp ≤5MB)
DELETE /api/profile/supplier/documents/{id}
GET    /api/profile/supplier/documents/{id}/file
```

عمداً **بیرون `role.supplier`** — مدرک را متقاضی می‌فرستد که هنوز نقش ندارد.
فایل روی دیسک خصوصی؛ `path` هرگز برنمی‌گردد، فقط `download_url`.

### ۵.۴ گزارش درآمد

```
GET /api/profile/supplier/earnings?from=1405-05-01&to=1405-05-31
```

تاریخ **جلالی** است (`YYYY-MM-DD`). ساختار: `settled` / `in_progress` /
`obligations` / `monthly` / `by_factory`.

> مبنای تاریخ `weighing_completed_at` است نه `created_at` — درآمد وقتی قطعی
> می‌شود که باسکول تسویه شود. سفارش تسویه‌نشده **حذف نمی‌شود، جدا شمرده می‌شود**
> (`in_progress.estimated_payout`) چون رقمش هنوز PTS است و ممکن است جابه‌جا شود.

### ۵.۵ درخواست‌ها به بک‌آفیس

```
POST   /api/profile/orders/{order}/cancellation-request   { "reason": "…" }
POST   /api/profile/supplier/requests/catalog-addition
GET    /api/profile/supplier/requests
DELETE /api/profile/supplier/requests/{id}
```

هر دو **درخواست** هستند نه اقدام: بند ۵.۳ لغو اجباری را تنها اختیار وضعیتی ادمین
کرده و بند ۳.۶ آن را به تیکت و دلیل مشروط می‌کند؛ کاتالوگ هم ادمین‌محور می‌ماند.

**پذیرش درخواست لغو هیچ سفارشی را لغو نمی‌کند** — اجرا از همان
`POST /admin/orders/{order}/force-cancel` می‌گذرد.

### ۵.۶ تسویه — دو فیلد تازه

در `OrderResource.financial.supplier_settlement`:

```json
"supplier_vat_pending_amount": "700000",
"required_top_up": "3700000"
```

`required_top_up` جمع بدهی و مالیات معلق است — همان عددی که کرون برای آزادسازی
عودت خریدار می‌سنجد. قبلاً دو جزء در دو ستون بودند و یکی اصلاً سریالایز نمی‌شد.

---

## بخش ۶ — بک‌آفیس

### 🔴 ۶.۱ `reject` تأمین‌کننده حالا `reason` می‌خواهد

```
POST /api/admin/suppliers/{user}/reject
{ "reason": "گواهی استاندارد پیوست نشده است." }     ← الزامی، حداقل ۱۰ نویسه
```

بدون آن ۴۲۲. دلیل در `GET /profile/supplier` به متقاضی نشان داده می‌شود، و
ویرایش پروفایل او را به صف برمی‌گرداند و دلیل را پاک می‌کند.

### ۶.۲ گزارش‌های مالی که خالی برمی‌گشتند

| اندپوینت | مشکل | حالا |
|---|---|---|
| `financial/company/invoices?type=initial` | ستون بی‌نویسنده (تب **پیش‌فرض**) | از جدول `invoices` (سند A یا E) |
| `financial/company/invoices?type=tax` | ستون بی‌نویسنده | `system_moadian_ref` |
| `financial/expert/documents` | **کل اندپوینت** خالی | سفارش‌های دارای `elite_yar_moadian_ref` — و حالا `OrderResource` برمی‌گرداند نه `WalletTransactionResource` |
| `financial/company/expert-shares` | هر دو حالت غلط | `meta.code = WEY` |
| `financial/supplier/documents?doc_type=tax` | مسیر فیش تصویری | `supplier_moadian_ref` |

### ۶.۳ صف درخواست‌های تأمین‌کننده

```
GET  /api/admin/supplier-requests?status=pending
POST /api/admin/supplier-requests/{id}/approve   { "note": "…" }
POST /api/admin/supplier-requests/{id}/reject    { "note": "…" }   ← note الزامی
```

`manage-orders`. پیش‌فرض لیست صف کار است نه کل تاریخچه.

### ۶.۴ باسکول ادمین

`POST /admin/shipments/{shipment}/weight` همچنان هست ولی حالا **قفل و بررسی
وضعیت** دارد — پس از تسویه ۴۰۹ می‌دهد. پیش از این نه تراکنش داشت نه قفل.

---

## بخش ۷ — چیزهایی که کار فرانت ندارند

- **بازپرداخت بدهی پول را نابود می‌کرد** — تأیید شد و رفع شد.
  `FundWalletAction` فقط یک ردیف `deposit/pending` می‌ساخت و موجودی را تکان
  نمی‌داد. حالا پارامتر `$status` دارد (قرینه‌ی `DebitWalletAction`)، با
  پیش‌فرض `PENDING` — پس رفتار فیش بانکی دست‌نخورده است. تست علاوه بر موجودی
  سازه، **مجموع کل پول** را هم می‌سنجد.
- **باگ دومی که در همان مسیر پیدا کردید** — `$extraMeta` پارامتر بود ولی بی‌صدا
  دور ریخته می‌شد (فقط `receipt_info` جان سالم به در می‌برد)، پس تگی مثل
  `kind => debt_repayment` هرگز به ردیف نمی‌رسید و آن تراکنش در گزارش مالی یک
  واریز بی‌نشان بود. `array_merge` شد، با کلیدهای پایه آخر تا فراخوان نتواند
  `payment_method` را بازنویسی کند. **این را من ندیده بودم — ممنون.**
- **`FRONT_APP_URL`** به `.env.example` اضافه شد با مقدار `5173` و توضیح اینکه
  بدون آن لینک پیامکی به پورت اشتباه می‌رود. پیش‌فرض `config/app.php` دست
  نخورد (تغییرش کلون‌های دیگر را می‌شکند)؛ جای درستش همان‌جاست که توسعه‌دهنده
  نگاه می‌کند.
- **`SPA_ROUTES`** الگوی `checkout/pay-for/\d+` گرفت و `CartPaymentLinkService`
  به منابع سوئیپ اضافه شد — داک‌بلاکش پوششی را ادعا می‌کرد که نداشت.
- **`coupon_visibility`** هیچ خواننده‌ای نداشت؛ ادمین روی `none` می‌گذاشت و
  کوپن‌ها کار می‌کردند.
- **`ticketing.sms_on_status`** هم همین؛ نتیجه اینکه `resolved` هیچ‌وقت به کاربر
  خبر نمی‌داد. حالا می‌دهد.
- **تأیید/رد تأمین‌کننده** اتمیک شد.

---

## بخش ۸ — کارهای باز

- **درگاه پرداخت لایسنس ندارد.** مسیر تأیید تراکنش fail-safe است؛ شاخه‌ی ب روی
  استیجینگ تا انتها تست نمی‌شود. مسیر کیف‌پول و رزرو کامل قابل تست‌اند.
- **`FRONT_APP_URL`** در `.env` ست نشده و به `localhost:3000` می‌افتد در حالی که
  فرانت dev روی `:5173` است. ادمین سرور باید بزند.
- **`APP_ENV=staging` / `APP_DEBUG=false` / `CORS_EXTRA_ORIGINS`** روی استیجینگ.
- **نظرسنجی محصول و ثبت نظر** پشت فیچرفلگ خاموش‌اند — پاسخ ۴۰۴ است نه ۴۰۳.
- **همگرایی شکل صفحه‌بندی** `products`/`variants` — منتظر اعلام شماست.

---

## بخش ۹ — تفاوت‌های L13

برنچ `redesign/order-flow-l13` عقب‌تر است و این‌ها آنجا **نیستند**:

| قابلیت | دلیل |
|---|---|
| `GET /profile/supplier` | فاز بعدی، هنوز پورت نشده |
| `supplier_settlement` در `OrderResource` | پیش‌نیازش (`partyRolesFor`) نیست؛ افزودنش ارقام تسویه را روی سفارشی که بیننده فقط خریدارش است نشت می‌داد |
| `/profile/supplier/stocks` و `stocks/bulk` | `factory_stocks` پورت نشده |
| `GET /faq-categories` | سایت‌کانتنت پورت نشده |

بقیه‌ی موارد این سند روی هر دو برنچ یکسان‌اند.
