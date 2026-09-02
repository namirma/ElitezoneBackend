# استقرار بازطراحی روی استیجینگ

برنچ: `redesign/order-flow-l10` · سرور: `89.42.210.55` · هدف: تیم تولید سایتِ پرِ محصول ببیند.

> ⚠️ **۶۴ مهاجرت اجرا می‌شود و ۸ تای آن داده را جابه‌جا می‌کنند** (نگاشت وضعیت
> سفارش، انتقال فایل تیکت به دیسک خصوصی، backfill نقش الیت‌یار، NOT NULL کردن
> `users.type_id`). این یک دیپلوی معمولی نیست — بدون بکاپ شروع نکنید.

---

## گام ۰ — یک تصمیم که باید قبل از هر چیز گرفته شود

`staging/master` چهار کامیت از ۱۴ ژوئیه دارد که در `redesign/order-flow-l10`
**نیست** (نه با هش، نه با محتوا — با `git log --cherry-mark` بررسی شد):

```
41e9540  Fix critical architectural and concurrency bugs
b04df95  fix: resolve race conditions in core financial actions
752af30  refactor: eliminate magic strings from validation and logic
76e8d32  chore: add IDE helper docblocks to models
```

نقطه‌ی انشعاب ۵ ژوئیه است، پس این‌ها یک خطِ موازی‌اند. فایل‌هایی که دست
زده‌اند (`PlaceOrderAction`, `CancelOrderAction`, `PaymentController`,
`HasOrderActions`) همگی در بازطراحی بازنویسی شده‌اند و `HasOrderActions` عمداً
حذف شده — پس **به‌احتمال زیاد منسوخ‌اند**. ولی «به‌احتمال زیاد» برای دور ریختنِ
کامیتی به نامِ «Fix critical architectural and concurrency bugs» کافی نیست.

**تصمیم لازم:** از نویسنده‌شان بپرسید آیا آن رفع‌ها در بازطراحی پوشش داده شده‌اند.
تا آن زمان، طبق گام ۲ روی برنچِ **جدا** بفرستید تا `master` دست‌نخورده بماند.

مرورشان:
```bash
git log -p staging/master ^redesign/order-flow-l10 -- app/
```

---

## گام ۱ — مشخص کنید چه کاری از دست خودتان برمی‌آید

ریموتِ استیجینگ `api-staging@89.42.210.55:/home/git/repositories/api-staging.git`
است. مسیرِ `/home/git/repositories/` یعنی حسابِ git-shell، که معمولاً **فقط
دستورات git** را می‌پذیرد و `php artisan` روی آن اجرا نمی‌شود.

```bash
ssh api-staging@89.42.210.55 "whoami"
```

- **جواب داد** → شل دارید، همه‌ی گام‌ها را خودتان می‌زنید.
- **`command not allowed` یا مشابهش** → از گام ۳ به بعد کارِ سوپروایزر است.
  گام ۲ (push) را همچنان خودتان می‌زنید.

> **phpMyAdmin (`db.elitezone.me`) جایگزین شل نیست.** SQL اجرا می‌کند نه PHP، و
> پورت ۳۳۰۶ از بیرون بسته است. سیدرِ کاتالوگ کد PHP است (با GD تصویر می‌سازد و
> روی دیسک می‌نویسد) — از phpMyAdmin اجرا نمی‌شود.

---

## گام ۲ — بکاپ (پیش از هر تغییر)

روی سرور:

```bash
mysqldump -u <user> -p <db_name> > ~/backup-before-redesign-$(date +%F).sql
tar czf ~/storage-backup-$(date +%F).tgz storage/app
```

بکاپِ `storage/app` اختیاری نیست: مهاجرتِ
`2026_07_25_140000_move_ticket_files_to_private_disk` فایل جابه‌جا می‌کند.

---

## گام ۳ — فرستادن کد

روی برنچِ **جدید**، نه `master` (دلیلش گام ۰):

```bash
git push staging redesign/order-flow-l10:redesign
```

اگر سرور push-to-deploy است، همین‌جا چک‌اوت می‌شود. اگر نه، سوپروایزر روی سرور
`git fetch && git checkout redesign` می‌زند.

---

## گام ۴ — وابستگی‌ها

```bash
cd <app-path>/api          # مسیر را با `dirname $(find / -name artisan 2>/dev/null | head -1)` پیدا کنید
composer install --no-dev --optimize-autoloader
```

---

## گام ۵ — مهاجرت‌ها ⚠️

```bash
php artisan migrate --force
```

۶۴ مهاجرت. اگر وسطش خطا داد **ادامه ندهید** — از بکاپ گام ۲ برگردانید و لاگ را
بفرستید.

---

## گام ۶ — محصولات (همان چیزی که تیم تولید می‌خواهد)

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
- **`SiteContentSeeder`** — منو، FAQ، فوتر.
- **`storage:link`** بدونش تصاویر ۴۰۴ می‌دهند.

هر دو idempotent‌اند: اجرای دوباره چیزی را دوبرابر نمی‌کند و `reserve_time` را
تمدید می‌کند.

---

## گام ۷ — کش و کارگر صف

```bash
php artisan optimize:clear
php artisan config:cache
sudo supervisorctl restart elitezone-worker    # deploy/supervisor/elitezone-worker.conf
```

بدون ری‌استارتِ کارگر، صف همچنان کدِ قدیمی را اجرا می‌کند.

---

## گام ۸ — راستی‌آزمایی

```bash
curl -s -H "Origin: https://staging.elitezone.me" https://api-staging.elitezone.me/api/products | head -c 400
```

انتظار: `"status":true` و **۱۰ محصول**. اگر آرایه خالی بود، سیدرِ کاتالوگ
اجرا نشده یا `reserve_time` گذشته است — گام ۶ را دوباره بزنید.

بعد در مرورگر `https://staging.elitezone.me/rebar` را باز کنید و کنسول را
نگاه کنید. این‌ها باید **رفع شده باشند** (روت‌هایشان در همین کد هست و استیجینگ
فقط عقب بود):

```
GET /api/settings/public        ← ۴۰۴ بود
GET /api/menu?location=header   ← ۴۰۴ بود
GET /api/menu?location=footer   ← ۴۰۴ بود
```

این دو **رفع نمی‌شوند** و کارِ جداگانه دارند:

| خطا | واقعیت |
|---|---|
| `GET /api/faq-categories` ۴۰۴ | فقط نسخه‌ی ادمین (`/api/admin/faq-categories`) وجود دارد؛ نسخه‌ی عمومی ساخته نشده |
| `GET /api/promo-overlays` ۴۰۴ | **هرگز در بک‌اند ساخته نشده** |
| `GET /api/products/` CORS | اسلشِ انتهایی ریدایرکت ۳۰۱ می‌سازد و مرورگر در CORS بلاکش می‌کند — فرانت باید بدون اسلش صدا بزند |

---

## برگشت

```bash
git checkout <previous-sha>
composer install --no-dev --optimize-autoloader
mysql -u <user> -p <db_name> < ~/backup-before-redesign-<date>.sql
php artisan optimize:clear && sudo supervisorctl restart elitezone-worker
```

مهاجرت‌ها `down()` دارند ولی **به آن‌ها تکیه نکنید**: نگاشتِ وضعیت‌های حذف‌شده
یک‌طرفه است (`weighbridge_done` و `waybill_issued` هر دو به `shipped` می‌روند و
از روی نتیجه قابل تفکیک نیستند). برگشتِ درست از دامپِ گام ۲ است.
