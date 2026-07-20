# گزارش بک‌اند — نیازمندی‌های پنل ادمین (BackOffice)

> تاریخ: ۱۴۰۵/۰۴/۲۸ (2026-07-19) · وضعیت: **همه موارد پیاده‌سازی و تست شد** (۳۶۳ تست سبز)
>
> ⚠️ سند `BACKEND_NEEDS_FINAL.md` که در پیام‌تان ارجاع داده بودید در ریپو وجود نداشت؛ همه‌چیز بر اساس خلاصه اولویت‌بندی‌شده‌ی خودتان ساخته شد. اگر جزئیات فیلدی در آن سند با این پیاده‌سازی فرق دارد، سند را بفرستید تا تطبیق دهیم.

## قرارداد عمومی پاسخ‌ها

- همه پاسخ‌ها در قالب استاندارد پروژه: `{ status: true|false, message: "...", data: ... }`
- لیست‌های صفحه‌بندی‌شده داخل `data` همان ساختار paginator لاراول را دارند (`data`, `links`, `meta`). پارامتر `?per_page=` (سقف ۱۰۰) و `?page=` پشتیبانی می‌شود.
- toggle همیشه **رکورد به‌روزشده** را در `data` برمی‌گرداند. ✔
- آپلود فایل با **POST multipart** (برای جایگزینی فایل در ویرایش هم `POST /{id}` بزنید، نه PUT خام). فایل‌ها روی دیسک public و URL کامل در Resource. ✔
- خطاهای اعتبارسنجی: فرمت استاندارد لاراول (422 + `errors`). ✔
- همه روت‌های ادمین زیر `auth:sanctum` + نقش admin.

## ۱۱. فیکس فوری — انجام شد ✅

`SupplierProfileResource` حالا فیلد `status` را برمی‌گرداند: `pending | approved | rejected`. روت‌های موجود: `GET /api/admin/suppliers` (فیلتر `?status=`)، `POST /api/admin/suppliers/{user}/approve`، `POST /api/admin/suppliers/{user}/reject`.

## ماژول‌های جدید

### ۱) بنرها — `/api/admin/banners`
| متد | مسیر | توضیح |
|---|---|---|
| GET | `/` | لیست (فیلتر `?position=`, `?is_active=`) |
| GET | `/{id}` | یک بنر |
| POST | `/` | ساخت — multipart: `title`* , `image`* (png/jpg/jpeg/webp ≤4MB), `link`, `position`, `sort_order`, `is_active` |
| PUT / POST | `/{id}` | ویرایش (تعویض تصویر فقط با POST multipart) |
| POST | `/{id}/toggle` | فعال/غیرفعال |
| DELETE | `/{id}` | حذف |

فیلدهای خروجی: `id, title, image (URL کامل), link, position, sort_order, is_active, created_at, created_at_p`

### ۲) مقالات — `/api/admin/articles`
CRUD + toggle مثل بنرها. فیلدها: `title`*, `slug`* (یکتا؛ حروف فارسی/لاتین + عدد + `-` و `_`)، `excerpt` (≤500)، `body`*, `cover` (multipart، اختیاری)، `is_active`, `published_at`. فیلترها: `?is_active=`, `?search=` (روی title/slug). `author_id` خودکار از ادمین لاگین‌شده.

### ۳) FAQ — `/api/admin/faq`
CRUD + toggle. فیلدها: `question`* (≤500)، `answer`*, `category`, `sort_order`, `is_active`. لیست به ترتیب `sort_order`. فیلترها: `?is_active=`, `?category=`.

### ۴) تنظیمات — `/api/admin/settings`
- `GET /` → `data` = آبجکت key-value همه تنظیمات
- `PUT /` → بدنه: `{ "settings": { "site_title": "...", "support_phone": "..." } }` — هر کلیدی upsert می‌شود؛ پاسخ = map کامل به‌روزشده. مقادیر رشته یا null.

### ۵) اعلان‌ها — `/api/admin/notifications`
- `GET /` — لیست همه اعلان‌های ارسالی (فیلتر `?user_id=`)
- `POST /send` — بدنه: `{ subject*, body*, all: true }` **یا** `{ subject*, body*, user_ids: [1,2,...] }` → پاسخ: `{ sent_count }`. ارسال همگانی فقط به کاربران فعال.
- `GET /stats` → `{ total, read, unread, sent_today }`

### ۶) گزارش CSV — `/api/admin/reports/{orders|financial|users}`
فقط CSV (خروجی مستقیم فایل، UTF-8 با BOM — در اکسل فارسی درست باز می‌شود). پارامتر اختیاری `?from=Y-m-d&to=Y-m-d` (میلادی). `format=csv` لازم نیست بفرستید — خروجی همیشه CSV است.
- orders: ID, Status, Payment Status, Buyer Mobile, Buyer Name, Final Amount, Created At
- financial: ID, User Mobile, Type, Amount, Status, Order ID, Description, Created At
- users: ID, Mobile, Name, Email, Roles, Active, Registered At

### ۷) مدیران سیستم — `/api/admin/admins`
CRUD + toggle. ساخت: `mobile`* (نرمالایز به 09)، `password`* (≥8)، `name`, `family`, `email`. ویرایش: **password اختیاری** — فقط اگر ارسال شود تغییر می‌کند (و بقیه سشن‌های آن مدیر باطل می‌شود). نکات:
- `DELETE /{id}` کاربر را حذف **نمی‌کند** — فقط نقش admin را جدا می‌کند (کاربر با سوابق مالی قابل حذف نیست).
- toggle یا حذفِ نقشِ **خود ادمین لاگین‌شده** → 422.

### ۸) کدهای تخفیف — `/api/admin/coupons`
CRUD + toggle. فیلدها: `code`* (یکتا)، `type`* (`percent|fixed`)، `amount`*, `user_id` (اختصاص به کاربر خاص، اختیاری)، `expires_at`, `reason`, `is_active`. فیلترها: `?is_active=`, `?search=`. **حذف یا ویرایش کد استفاده‌شده (used_at ≠ null) → 422.** ✔ خروجی شامل `is_valid` محاسبه‌شده.

### ۹) نظرات — `/api/admin/reviews` (+ سمت کاربر)
**ادمین** (همان الگوی صف job-experiences):
- `GET /` (فیلتر `?status=pending|approved|rejected`, `?product_id=`) · `GET /{id}`
- `POST /{id}/approve`
- `POST /{id}/reject` — بدنه: `{ rejection_reason* ≤1000 }`

**سمت کاربر / عمومی** (جدید — چون endpoint ثبت نظر وجود نداشت):
- `POST /api/products/{cuid}/reviews` (auth) — بدنه: `{ rating*: 1..5, comment: ≤2000 }`. هر کاربر برای هر محصول **یک** نظر؛ ارسال مجدد همان رکورد را ویرایش و دوباره به صف pending می‌فرستد. Throttle: ۱۰ در دقیقه.
- `GET /api/products/{cuid}/reviews` (عمومی) — فقط approved + `{ stats: { count, average_rating }, reviews: [...] }`
- `GET /api/profile/reviews` (auth) — نظرات خود کاربر با `status` و `rejection_reason` (برای نمایش دلیل رد)

### ۱۰) صفحات استاتیک — `/api/admin/pages`
CRUD + toggle. فیلدها: `title`*, `slug`* (یکتا)، `body`*, `is_active`. فیلتر `?is_active=`.

## نکته برای دیپلوی
بعد از pull: `php artisan migrate` (چهار مایگریشن جدید: جداول banners/articles/faqs/pages/settings، جدول product_reviews + unique(user,product)، ستون coupons.is_active).
