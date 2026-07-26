# EliteZone — راهنمای عملیات (Operations Runbook)

این سند کارهای عملیاتیِ سرور را پوشش می‌دهد؛ برای معماری و قواعد کد به
[`api/CLAUDE.md`](../CLAUDE.md) مراجعه کنید.

---

## ۱. Queue worker — بحرانی‌ترین سرویسِ پس‌زمینه

**همهٔ** listenerهای پروژه `ShouldQueue` هستند و `QUEUE_CONNECTION=database` است.
اگر worker بالا نباشد، **هیچ خطایی رخ نمی‌دهد**: پیامک OTP، اعلان سفارش و ایمیل‌ها
صرفاً در جدول `jobs` تلنبار می‌شوند. چون ورودِ کاربر به پیامکِ OTP وابسته است،
خوابیدنِ worker عملاً یعنی **قطعیِ ورود، بدون هیچ ردی در لاگ**.

> این فرضی نیست: در ممیزیِ ۱۴۰۵/۰۵/۰۴ روی ماشین توسعه ۷ عدد `SendSmsJob` با
> `attempts=0` و سنِ ۲۹ تا ۴۷ روز پیدا شد — worker هرگز اجرا نشده بود.

### راه‌اندازی

```bash
sudo cp deploy/supervisor/elitezone-worker.conf /etc/supervisor/conf.d/
# مسیرها و user را مطابق سرور ویرایش کنید
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status elitezone-worker:*
```

پس از **هر** deploy:

```bash
php artisan queue:restart
```

بدون این، workerها کدِ قدیمی را در حافظه نگه می‌دارند.

### پایش

`queue:health` هر ۱۵ دقیقه از طریق scheduler اجرا می‌شود و در صورت مشکل
`Log::error` می‌زند (و اگر `SENTRY_LARAVEL_DSN` ست باشد، رویداد Sentry می‌سازد).

```bash
php artisan queue:health          # انسان‌خوان
php artisan queue:health --json   # برای مانیتورینگ بیرونی
```

exit code صفر = سالم، ۱ = هشدار. آستانه‌ها در `config/ops.php` (قابل تنظیم با env).

### شکست‌ها

```bash
php artisan queue:failed          # فهرست
php artisan queue:retry all       # تلاش مجدد
php artisan queue:flush           # پاک‌سازی (پس از بررسی)
```

`failed_jobs` هرگز خودبه‌خود پاک نمی‌شود؛ انباشتِ آن یعنی کسی به شکست‌ها رسیدگی نکرده.

---

## ۲. نسخهٔ پشتیبان — و مشقِ بازگردانی

```bash
php artisan backup:run --only-db   # روزانه ۰۱:۰۰ (خودکار)
php artisan backup:clean           # روزانه ۰۱:۴۵ (خودکار)
php artisan backup:monitor         # روزانه ۰۲:۰۰ (خودکار)
php artisan backup:list            # وضعیت فعلی
```

مقصد: دیسکِ خصوصیِ `financial` (خارج از `storage:link`). دامپ شامل **کدملی، شبا و
شمارهٔ کارت** است — هرگز روی دیسک `public` یا هر مقصدِ URL-پذیر ننویسید.

### ⚠️ مشقِ بازگردانی — الزامی، حداقل سه‌ماه یک‌بار

**نسخه‌ای که هرگز بازگردانی نشده، نسخهٔ پشتیبان نیست.** روی یک دیتابیسِ *موقت*
تمرین کنید، هرگز روی دیتابیسِ زنده:

```bash
# ۱. آخرین آرشیو را پیدا و استخراج کنید
php artisan backup:list
unzip storage/app/financial/elitezone/<TIMESTAMP>.zip -d /tmp/restore-drill

# ۲. یک دیتابیسِ موقت بسازید — نامِ دیتابیسِ زنده را تایپ نکنید
mysql -u root -p -e "CREATE DATABASE restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ۳. دامپ را وارد کنید (فایل gzip است)
gunzip -c /tmp/restore-drill/db-dumps/mysql-*.sql.gz | mysql -u root -p restore_drill

# ۴. صحت را بسنجید — این سه عدد باید با پروداکشن هم‌خوان باشند
mysql -u root -p restore_drill -e "
  SELECT (SELECT COUNT(*) FROM users)               AS users,
         (SELECT COUNT(*) FROM orders)              AS orders,
         (SELECT COUNT(*) FROM wallet_transactions) AS wallet_tx;"

# ۵. تمیزکاری
mysql -u root -p -e "DROP DATABASE restore_drill;"
rm -rf /tmp/restore-drill
```

نتیجهٔ هر مشق (تاریخ، اندازهٔ آرشیو، تعداد ردیف‌ها) را ثبت کنید.

### نسخهٔ خارج از سرور

پیش‌فرض `BACKUP_DISKS=financial` یعنی نسخه روی **همان سروری** است که ممکن است از
دست برود. برای محافظتِ واقعی:

```env
BACKUP_DISKS=financial,s3
BACKUP_ARCHIVE_PASSWORD=<یک رمز قوی>
```

---

## ۳. ردیابی خطا (Sentry)

کد وصل است ولی **بدون DSN کاملاً بی‌اثر** — یعنی تا وقتی این را ست نکنید هیچ
ردیابی متمرکزی از خطاهای پروداکشن ندارید:

```env
SENTRY_LARAVEL_DSN=https://...@....ingest.sentry.io/...
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false     # نگه دارید — داده‌های کاربر نباید ارسال شود
```

پس از ست‌کردن: `php artisan config:clear` و بررسی با
`php artisan sentry:test`.

---

## ۴. چک‌لیست deploy

```bash
php artisan down                 # اختیاری، برای مهاجرت‌های سنگین
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart        # حیاتی — وگرنه worker کد قدیمی را اجرا می‌کند
php artisan up
```

**نکاتی که در گذشته باعث مشکل شده‌اند:**

- `config:cache` باعث می‌شود `env()` خارج از فایل‌های config مقدارِ `null` برگرداند.
  همیشه `config()` استفاده کنید (قاعده در `CLAUDE.md`).
- روت‌های closure دار `route:cache` را می‌شکنند — به همین دلیل روت‌های سفارش از
  `defaults()` استفاده می‌کنند نه closure.
- `IDEMPOTENCY_CACHE_STORE` پیش‌فرض `database` است و جدول‌های `cache`/`cache_locks`
  می‌خواهد؛ مهاجرت را قبل از سرویس‌دهی اجرا کنید.
- روی استیجینگ `APP_ENV=staging` بگذارید، وگرنه مبدأ `http://staging…` در CORS
  مجاز نیست و preflightِ ورود رد می‌شود.

---

## ۵. متغیرهای محیطی که فراموش می‌شوند

| متغیر | چرا مهم است |
|---|---|
| `SENTRY_LARAVEL_DSN` | بدون آن هیچ ردیابی خطایی وجود ندارد |
| `IDEMPOTENCY_CACHE_STORE` | با `file` گاردِ مالیِ چندسروری بی‌اثر است |
| `BACKUP_DISKS` | فقط `financial` یعنی نسخه روی همان سرورِ در خطر |
| `ADMIN_IP_WHITELIST` | خالی = محدودیت IP ادمین غیرفعال |
| `FINANCIAL_VAT_USER_ID` | بدون آن، کیف VAT با کیف سیستم یکی می‌شود |
| `ROLE_SELECTION_GATE_ENABLED` | فقط وقتی روشن کنید که کلاینت قرارداد پاسخ را هندل کند |
