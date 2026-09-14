# استقرار روی استیجینگ — شاخه‌ی لاراول ۱۳

برنچ: `redesign/order-flow-l13` (روی سرور: `redesign-l13`) · سرور: `188.212.22.118`

> ⚠️ **سه چیز نسبت به نسخه‌ی لاراول ۱۰ عوض شده که اگر رعایت نشوند، سایت بی‌صدا
> خراب می‌شود:**
> ۱. **PHP باید ۸.۴ یا بالاتر باشد** (`composer.json`: `"php": "^8.4"`). روی PHP
> قدیمی‌تر `composer install` همان اول می‌ایستد.
> ۲. **برنامه در ریشه‌ی مخزن است، نه زیرشاخه‌ی `api/`.** هر مسیری که به
> `…/api` ختم می‌شود (supervisor، cron، nginx) بعد از سوئیچ غلط است.
> ۳. **زمان‌بند (scheduler) و کلیدهای پوش** لازم‌اند — گام‌های ۵ و ۸.
>
> شاخه‌ی لاراول ۱۰ (`redesign/order-flow-l10`) کنار گذاشته شده؛ دیگر استقرار نمی‌شود.

---

## گام ۰ — پیش‌نیازِ سرور

روی سرور:

```bash
php -v                                           # باید 8.4.x یا بالاتر
ls /usr/bin/php*                                 # اگر پیش‌فرض قدیمی است، مسیرِ 8.4 را پیدا کنید
php -m | grep -Ei '^(curl|mbstring|openssl|json|gd)$'
```

`curl`، `mbstring`، `openssl` و `json` را کتابخانه‌ی پوش لازم دارد و `gd` را
سیدرِ کاتالوگ. هر کدام نبود، پیش از ادامه نصب شود.

---

## گام ۱ — مشخص کنید چه کاری از دست خودتان برمی‌آید

ریموتِ استیجینگ `api-staging@188.212.22.118:/home/git/repositories/api-staging.git`
است. مسیرِ `/home/git/repositories/` یعنی حسابِ git-shell، که معمولاً **فقط
دستورات git** را می‌پذیرد و `php artisan` روی آن اجرا نمی‌شود. ورود با **رمز** است.

```bash
ssh api-staging@188.212.22.118 "whoami"
```

- **جواب داد** → شل دارید، همه‌ی گام‌ها را خودتان می‌زنید.
- **`command not allowed` یا مشابهش** → فقط گام ۳ (push) با شماست؛ بقیه کارِ سوپروایزر است.

> **phpMyAdmin (`db.elitezone.me`) جایگزین شل نیست.** SQL اجرا می‌کند نه PHP، و
> پورت ۳۳۰۶ از بیرون بسته است. سیدرِ کاتالوگ کد PHP است (با GD تصویر می‌سازد و
> روی دیسک می‌نویسد) — از phpMyAdmin اجرا نمی‌شود.

---

## گام ۲ — بکاپ (پیش از هر تغییر)

روی سرور:

```bash
mysqldump -u <user> -p <db_name> > ~/backup-before-l13-$(date +%F).sql
tar czf ~/storage-backup-$(date +%F).tgz storage/app
```

بکاپِ `storage/app` اختیاری نیست: مهاجرتِ
`2026_07_25_140000_move_ticket_files_to_private_disk` فایل جابه‌جا می‌کند (اگر
دیتابیس هنوز آن را اجرا نکرده باشد).

---

## گام ۳ — فرستادن کد

روی برنچِ **جدید**:

```bash
git push staging redesign/order-flow-l13:redesign-l13
```

**چرا نه `master` یا `redesign`:** شاخه‌ی لاراول ۱۳ ادامه‌ی هیچ‌کدام از
شاخه‌های سرور نیست (۱۹۹ کامیت فاصله). پوش روی آن‌ها رد می‌شود، و **`--force`
تاریخچه‌ی سرور را پاک می‌کند — هرگز نزنید.**

> تصمیمِ قدیمیِ «چهار کامیتِ ۱۴ ژوئیه روی `staging/master`» دیگر باز نیست:
> `staging/master` از ۳۰ اوت همان بازطراحیِ لاراول ۱۰ است و آن چهار کامیت در
> آن نیستند.

اگر سرور push-to-deploy است، همین‌جا چک‌اوت می‌شود. اگر نه، روی سرور:
`git fetch && git checkout redesign-l13`.

---

## گام ۴ — وابستگی‌ها

```bash
cd <app-root>      # پوشه‌ای که artisan در آن است — در L13 ریشه‌ی مخزن، نه api/
composer install --no-dev --optimize-autoloader
```

**فونتِ فاکتور — بدونش هیچ خرید و رزروی ثبت نمی‌شود.** اسنادِ مالیِ خرید و رزرو
**داخلِ همان تراکنشِ پرداخت** ساخته می‌شوند؛ اگر فونت نباشد ساختِ PDF با ۵۰۳ می‌افتد و کلِ
پرداخت برمی‌گردد (پولی جابه‌جا نمی‌شود، ولی سفارشی هم ثبت نمی‌شود). `YekanBakh-Regular.ttf`
و `YekanBakh-Bold.ttf` لایسنس‌دارند و عمداً در گیت نیستند، پس کلونِ تازه‌ی سرور آن‌ها را
ندارد. یک‌بار از منبعِ تیم کپی شوند:

```bash
mkdir -p storage/fonts
# دو فایلِ YekanBakh-Regular.ttf و YekanBakh-Bold.ttf را این‌جا بگذارید
ls storage/fonts/YekanBakh-*.ttf
```

---

## گام ۵ — کلیدهای `.env`

**فقط اگر سرور هنوز کلید VAPID ندارد** (`grep PUSH_VAPID_PUBLIC_KEY .env` خالی است):

```bash
php artisan push:vapid-keys
```

سه خطِ خروجی را در `.env` بگذارید. **اگر کلید از قبل هست، دوباره نسازید** —
عوض‌کردنش همه‌ی اشتراک‌های پوشِ فعلی را باطل می‌کند. کلیدِ خصوصی در گیت نمی‌رود.

روی استیجینگ (`APP_ENV` غیرِ `production`) پیامک و پوش **فقط** به شناسه‌های
`TSMS_DEV_ALLOWLIST` و `PUSH_DEV_ALLOWLIST` می‌روند؛ خالی یعنی به هیچ‌کس. این
عمدی است — دیتابیسِ استیجینگ شماره و دستگاهِ مشتریِ واقعی دارد.

---

## گام ۶ — مهاجرت‌ها ⚠️

اول ببینید چه چیزی اجرا می‌شود:

```bash
php artisan migrate:status | grep Pending
```

- **اگر دیتابیس روی `staging/master` مهاجرت شده بود** → ۷ مهاجرت. فقط یکی داده
  را جابه‌جا می‌کند: `remap_legacy_pending_order_status` (سفارش‌های وضعیتِ قدیمیِ
  `pending` → `cancelled`، مگر پرداخت‌شده‌ها که به `processing` می‌روند تا پیگیری
  شوند).
- **اگر نه** → بیشتر، شامل مهاجرت‌های داده‌ایِ بازطراحی (نگاشت وضعیت سفارش،
  انتقال فایل تیکت، backfill نقش الیت‌یار، NOT NULL کردن `users.type_id`).

```bash
php artisan migrate --force
```

اگر وسطش خطا داد **ادامه ندهید** — از بکاپ گام ۲ برگردانید و لاگ را بفرستید.

> **خطای `Money columns hold fractional values …` باگ نیست، ترمز است.** مهاجرتِ
> `standardise_money_columns_to_integer_rials` ستون‌های مبلغ را به ریالِ صحیح
> تبدیل می‌کند و MySQL در این تبدیل مقدارِ اعشاری را **بی‌صدا گرد** می‌کرد. پیش
> از اولین تغییر همه را می‌شمارد و اگر اعشار ببیند **هیچ ستونی را عوض نمی‌کند** و
> نامِ ستون‌ها را می‌گوید. آن فهرست را بفرستید؛ دستی گرد نکنید.

---

## گام ۷ — محصولات (همان چیزی که تیم تولید می‌خواهد)

```bash
php artisan db:seed --class=StagingCatalogSeeder --force
php artisan db:seed --class=SiteContentSeeder --force
php artisan storage:link
```

- **`StagingCatalogSeeder`** — ۸ محصول در هر چهار دسته، ۲۱ واریانت، ۱۰۸ ردیف
  قیمت، موجودی per-انبار، توضیحات فارسی، و تصاویر PNG جانشین.
  همچنین یک تأمین‌کننده‌ی نمایشی می‌سازد و `reserve_time` را یک سال جلو
  می‌گذارد — **بدون این، کاتالوگ نامرئی می‌ماند** (قاعده‌ی
  `FilterProductsAction::hideProductsWithExpiredPrices`).
- **دو کالای ناموجود، عمداً** — برای آزمودنِ «موجود شد خبرم کن»: «آجر نسوز»
  کلاً ناموجود است و در «شیر فلکه کشویی» فقط سایز ۳ اینچ. روال آزمون:
  ۱) با یک حسابِ خریدار روی کالای ناموجود زنگوله را بزنید؛
  ۲) با تأمین‌کننده‌ی نمایشی (`09120000001`) در پنلِ تأمین‌کننده موجودیِ همان
  کالا را بالا ببرید؛ ۳) حداکثر ۱۰ دقیقه صبر کنید (یا
  `php artisan stock:notify-subscribers`)؛ اعلان در **زنگوله‌ی سایت** می‌آید —
  پیامک و پوش برای این اعلان عمداً نمی‌رود. اجرای دوباره‌ی سیدر هر دو را
  دوباره صفر می‌کند تا آزمون تکرارشدنی بماند.
- **`SiteContentSeeder`** — منو، FAQ، فوتر.
- **`storage:link`** بدونش تصاویر ۴۰۴ می‌دهند.

هر دو idempotent‌اند: اجرای دوباره چیزی را دوبرابر نمی‌کند و `reserve_time` را
تمدید می‌کند.

---

## گام ۸ — کش، کارگر صف، زمان‌بند

```bash
php artisan optimize:clear
php artisan config:cache
```

در `/etc/supervisor/conf.d/elitezone-worker.conf` (قالبش: `deploy/supervisor/elitezone-worker.conf`)
**دو چیز را برای L13 عوض کنید:** مسیرِ `…/api` → ریشه‌ی مخزن، و `php` → مسیرِ
کاملِ PHP 8.4. بعد:

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl restart 'elitezone-worker:*' elitezone-scheduler
sudo supervisorctl status
```

- بدون ری‌استارتِ **کارگر**، صف کدِ قدیمی را اجرا می‌کند — و نوتیفِ پوش هم از صف می‌رود.
- بدون **زمان‌بند** این‌ها هرگز اجرا نمی‌شوند: انقضای اضافه‌بار، بکاپِ دیتابیس،
  `queue:health`، و پاک‌سازیِ اشتراک‌های پوشِ کهنه (`push:prune-stale`، هر شب ۳:۳۰).
  اگر supervisor ندارید، یک خط crontab جایگزینش است:
  `* * * * * cd <app-root> && php artisan schedule:run >> /dev/null 2>&1`

---

## گام ۹ — راستی‌آزمایی

```bash
curl -s -H "Origin: https://staging.elitezone.me" https://api-staging.elitezone.me/api/products | head -c 400
curl -s https://api-staging.elitezone.me/api/settings/public | grep -o '"vapid_public_key":"[^"]\{10\}'
php artisan schedule:list | grep push:prune-stale
```

- اولی: `"status":true` و محصول. آرایه‌ی خالی یعنی سیدرِ کاتالوگ اجرا نشده یا
  `reserve_time` گذشته — گام ۷ را دوباره بزنید.
- دومی: باید چیزی چاپ کند. خالی یعنی کلید VAPID در `.env` نیست یا `config:cache`
  بعد از گذاشتنش زده نشده — بدون آن دکمه‌ی «فعال‌سازی اعلان‌ها» در سایت کار نمی‌کند.
- سومی: زمان‌بند کار را می‌شناسد.

در مرورگر `https://staging.elitezone.me/rebar` را باز کنید و کنسول را نگاه کنید.
`/api/settings/public`، `/api/menu`، `/api/faq-categories` و `/api/promo-overlays`
همه در این کد روت دارند و نباید ۴۰۴ بدهند.

یک مورد سمتِ فرانت است: `GET /api/products/` با اسلشِ انتهایی ریدایرکت ۳۰۱ می‌سازد
و مرورگر در CORS بلاکش می‌کند — فرانت باید بدون اسلش صدا بزند.

---

## برگشت

```bash
git checkout <previous-sha>
composer install --no-dev --optimize-autoloader
mysql -u <user> -p <db_name> < ~/backup-before-l13-<date>.sql
php artisan optimize:clear && sudo supervisorctl restart 'elitezone-worker:*' elitezone-scheduler
```

اگر مسیرِ supervisor را برای L13 عوض کرده بودید، آن را هم برگردانید.

مهاجرت‌ها `down()` دارند ولی **به آن‌ها تکیه نکنید**: نگاشتِ وضعیت‌های حذف‌شده
یک‌طرفه است (`weighbridge_done` و `waybill_issued` هر دو به `shipped` می‌روند و
از روی نتیجه قابل تفکیک نیستند). برگشتِ درست از دامپِ گام ۲ است.
