# به تیم بک‌اند — وضعیت فعلی ما، و یک یافته که تصمیمِ شما را می‌خواهد

**تاریخ:** ۲۰۲۶-۰۹-۰۸ · **از:** تیم فرانت/بک‌آفیس (علی) · **ریپو:** `namirma/ElitezoneBackend`

این سند دو کار می‌کند: اول دقیقاً می‌گوید ما کجاییم و چه چیزی را اندازه گرفته‌ایم،
بعد یک یافته را مطرح می‌کند که **تصمیمش با شماست، نه ما**.

---

## ۱. ریل: لاراول ۱۳ انتخاب شد

پس از گزارشِ پاریتیِ شما، علی تصمیم گرفت **L13 ریلی باشد که مستقر می‌شود**، و
دستور صریحش این است:

> «ما رفتیم روی لاراول ۱۳ … اگر جایی به ۱۰ اشاره دارد، از خود ما که ورژن ۱۳ است
> را ملاک بدان.»

پس هیچ کاری از سمت ما به L10 برنمی‌گردد مگر شما بخواهید.

---

## ۲. شاخهٔ ما و آنچه اضافه کرده

**`port/page-blocks-l13`** (منشعب از `redesign/order-flow-l13`)

زیرسیستمِ **صفحه‌ساز** (page composer) روی **هیچ‌کدام از دو ترانک** نبود — نه L10
نه L13 — و فقط روی شاخهٔ فیچرِ مرج‌نشدهٔ `feature/page-blocks` زندگی می‌کرد. ما
آن را روی L13 نشاندیم. **مرج نزدیم؛ طبق قاعدهٔ گزارش خودتان cherry-pick شد.**

**۱۳ فایل مستقل:** کنترلر، دو FormRequest، Resource، Model، Schema، مایگریشن،
سیدر، فایل زبان، چهار تست.

**۵ نقطهٔ اتصال به کدِ موجود:**

| فایل | چه چیزی اضافه شد |
|---|---|
| `routes/api/admin.php` | گروه ۱۰ روتِ ادمین زیر `manage-content` |
| `routes/api/public.php` | `GET /pages/{page}/blocks` |
| `app/Http/Controllers/ContentController.php` | متد `pageBlocks()` + سه import |
| `database/seeders/DatabaseSeeder.php` | ثبت `PageBlocksSeeder` |
| `config/settings.php` | کلید `vpn_warning_text` در هر دو فهرست |

> ⚠️ `vpn_warning_text` عمداً **استثنای قاعدهٔ «خالی = مخفی»** است: تصمیمِ نمایشِ
> نوار را کد می‌گیرد (IP غیرایرانی)، پس خالی‌بودنش نوار را خاموش نمی‌کند بلکه
> متنِ پیش‌فرضِ سایت را نگه می‌دارد. فرانت از قبل رویش شیپ شده.

### یک اعلانِ غلط که PHPStan روی L13 گرفت

`AdminPageBlockController::normalizedShapes()` اعلام می‌کرد `options?: list<string>`
ولی واقعاً `list<array{value, label}>` برمی‌گرداند. **PHPStan 1 روی L10 نمی‌گرفتش؛
larastan 3 روی L13 گرفت.** داده از اول درست بود، پس **اعلان اصلاح شد نه داده**.

---

## ۳. سنجه‌ها — اندازه‌گیری‌شده، نه نقل‌شده

روی `port/page-blocks-l13` **بعد از** رِبیس روی نوکِ فعلیِ شما (`0fd8219`):

```
composer install     Laravel 13.21.1 · larastan 3.10
PHPStan              [OK] No errors
Pint                 passed
php artisan test     ۱۱۶۴ سبز / ۳۶۶۲ ادعا
تست‌های صفحه‌ساز       ۵۷ سبز / ۶۶۶ ادعا
migrate:status       ۲۳۳ اجرا / ۰ معلق
API زنده             GET /api/pages/home/blocks → ۲۰۰ با ۳۱٬۸۱۰ بایت JSONِ واقعی
```

> **بدنهٔ پاسخ خوانده شد، نه فقط کدِ وضعیت.** در همین نشست ثابت شد این سرور
> می‌تواند `200` بدهد و بدنه‌اش صفحهٔ fatalِ PHP باشد (وقتی ریل بدون
> `composer install` سوییچ شود). اگر جایی مشکوک شدید، بدنه را بخوانید.

### گیت پاریتی

```bash
php scripts/branch-parity.php origin/redesign/order-flow-l10 HEAD
```
```
فایل‌های PHPِ مشترک: ۱۲۲۷ · بایت‌به‌بایت یکسان: ۱۱۱۹ · فقط سبک: ۸۲
تفاوتِ معناییِ مجاز: ۲۶   ·   تفاوتِ معناییِ غیرمنتظره: ۰      → exit 0
```

پنج نقطهٔ اتصالِ بالا در `docs/branch-parity-allowlist.txt` **بخش ۴** ثبت شدند، با
ردیف‌های **باریک** — پوششِ کلیِ فایل نمی‌دهند، تا اگر فردا چیز دیگری در همان
فایل‌ها واگرا شد گیت همچنان بگیردش.

> ⚠️ **تلهٔ خودِ گیت:** اجرای
> `php scripts/branch-parity.php redesign/order-flow-l10 redesign/order-flow-l13`
> روی چک‌اوتِ ما **exit 0** داد در حالی که در همان خروجی نوشته بود «فایل‌های PHPِ
> مشترک: **۰**» — چون `redesign/order-flow-l13` شاخهٔ محلی نبود و اسکریپت آن سمت را
> خالی گرفت، بی‌هیچ خطایی. **قبل از باور کردنِ سبز، عددِ «مشترک: N» را ببینید.**

### آلیاس‌های میدل‌ور

برای اطمینان از اینکه در پورت چیزی گم نشده، دو فهرست را **diff دوطرفه** گرفتیم
(نه فقط شمارش):

```
L10 app/Http/Kernel.php      → ۲۵
L13 bootstrap/app.php:103    → ۲۵
diff <(L10|sort) <(L13|sort) → بدون خروجی
```

هر ۲۵ نام یکسان. **هیچ آلیاسی گم نشده.**

---

## ۴. 🔴 یافته — یک جملهٔ تاریخی در کامنتِ `bootstrap/app.php` غلط است

کامیتِ `0fd8219` (نوشتهٔ navid) `trustHosts()` را روی L13 فعال کرد. **خودِ رفع درست
و لازم است و ما دست‌نخورده نگهش داشتیم.** ولی کامنتِ بالای آن می‌گفت:

> «روی شاخهٔ L10 این میدل‌ور در `app/Http/Kernel.php` **فعال بود** و در پورت جا ماند»

اندازه‌گیری خلافش را می‌گوید:

```php
// origin/redesign/order-flow-l10 : app/Http/Kernel.php:55
protected $middleware = [
    // \App\Http\Middleware\TrustHosts::class,     ← کامنت‌شده
    TrustProxies::class,
    HandleCors::class,
```

```bash
git log -S "TrustHosts::class" origin/redesign/order-flow-l10 -- app/Http/Kernel.php
#   → bc91d43  "first commit"      (تنها کامیت)
# جست‌وجوی فرمِ بدونِ //  → هیچ نتیجه‌ای
```

**یعنی روی L10 از اولین کامیتِ ریپو کامنت‌شده بوده و هرگز فعال نبوده.**

### چرا این مهم است

روایتِ غلط بی‌ضرر نیست. هرکس آن کامنت را بخواند نتیجه می‌گیرد **«L10 سالم است و
فقط L13 شکاف داشت»** — که برعکسِ واقعیت است:

- **L13:** حالا حفاظت دارد (به لطف `0fd8219`). ✅
- **L10:** حفاظت **هرگز** نداشته. این یک **شکافِ ارثی** است، نه رگرسیونِ پورت. 🔴

ما فقط **متنِ کامنت** را تصحیح کردیم (کامیت پیوست). **هیچ رفتاری عوض نشد** و به
`redesign/order-flow-l10` دست نزدیم.

### و یک هشدار دربارهٔ تأیید کردنش

**این رفع را نمی‌شود محلی تأیید کرد:**

```php
// vendor/laravel/framework/.../Http/Middleware/TrustHosts.php:99
protected function shouldSpecifyTrustedHosts()
{
    return ! $this->app->environment('local') && ! $this->app->runningUnitTests();
}
```

روی `APP_ENV=local` میدل‌ور **اصلاً اجرا نمی‌شود** — پس «محلی تست کردم کار کرد»
شاهدِ هیچ چیزی نیست.

و روی staging هم یک تلهٔ دوم هست: زدنِ `Host: evil.example.com` به
`staging.elitezone.me` **۴۰۳** می‌دهد، ولی بدنه‌اش صفحهٔ خطای **Cloudflare** است
(`<hr><center>cloudflare</center>`)، نه پاسخِ لاراول. یعنی درخواست در لبه رد شده و
اصلاً به PHP نرسیده. **برای سنجشِ واقعی باید مستقیم به origin زد، نه از پشتِ CDN.**

---

## ۵. سؤال‌های ما

۱. **آیا `redesign/order-flow-l10` هنوز جایی مستقر می‌شود؟**
   اگر بله، اعتبارسنجیِ هدرِ `Host` آن‌جا وجود ندارد و لینک‌های بازیابی رمز در
   برابر host-header poisoning باز است. رفعش یک خط است (برداشتنِ `//` در
   `Kernel.php:55`) ولی **روی شاخهٔ شماست و ما دست نمی‌زنیم.**

۲. **آیا می‌خواهید ما L10 را هم اصلاح کنیم، یا L13 کاملاً جایگزینش می‌شود؟**
   دستور علی این است که L13 ملاک است؛ ولی اگر L10 هنوز زنده است، این تصمیمِ شماست.

۳. **آیا کسی می‌تواند `trustHosts` را روی staging مستقیم به origin بسنجد؟**
   ما به origin پشتِ Cloudflare دسترسی نداریم. تا آن موقع این رفع «تأییدنشده»
   می‌ماند و ما همان‌طور ثبتش کرده‌ایم — **لطفاً کسی «تست شد» علامتش نزند.**

۴. **بخش ۴ِ `docs/branch-parity-allowlist.txt` مورد تأیید شماست؟**
   پنج ردیفِ صفحه‌ساز را ما اضافه کردیم تا گیت سبز شود. اگر ترجیح می‌دهید به‌جایش
   صفحه‌ساز به L10 هم پورت شود، بگویید — کارش انجام‌شدنی است.

۵. **`ext-soap` روی ماشین ما نیست** و `composer install` بدون
   `--ignore-platform-req=ext-soap` شکست می‌خورد (به‌خاطر
   `artisaninweb/laravel-soap` که غیرفعال است). `php.ini` را عمداً دست نزدیم.
   اگر این وابستگی واقعاً لازم نیست، حذفش از `composer.json` کارِ شماست.

---

## پیوست — کامیت‌های ما روی این شاخه

```
f1cbaf8  docs(parity): the page composer stays on L13 only, and the gate now says so
bfc2803  port(content): the page composer, onto the rail that ships
0fd8219  fix(bootstrap): host-header validation was lost in the port…     ← مالِ navid
051ef3e  fix(deploy): the supervisor template still assumes the L10 layout… ← مالِ navid
```

دو کامیتِ آخر مالِ شماست؛ ما فقط رویشان rebase کردیم (merge نزدیم).
