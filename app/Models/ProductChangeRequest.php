<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * درخواستِ تغییرِ کاتالوگ از سوی تأمین‌کننده.
 *
 * چهار نوع: ساختِ محصول، ویرایشِ محصول، ساختِ واریانت، ویرایشِ واریانت. هر چهار
 * **درخواست**اند نه اقدام — محصول و واریانت بینِ همه‌ی تأمین‌کننده‌ها مشترک‌اند،
 * پس تا ادمین تأیید نکند هیچ‌چیز به کاتالوگ نمی‌رسد. پذیرش، `payload` را از
 * همان مسیرِ نوشتنِ ادمین اعمال می‌کند (`ProductCatalogWriter`).
 *
 * @property int $id
 * @property int $supplier_user_id
 * @property string $type
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $diff
 * @property string|null $note
 * @property string $status
 * @property int|null $admin_id
 * @property string|null $admin_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $supplier
 * @property-read User|null $admin
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class ProductChangeRequest extends Model
{
    public const ID = 'id';

    public const SUPPLIER_USER_ID = 'supplier_user_id';

    public const TYPE = 'type';

    public const PRODUCT_ID = 'product_id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const PAYLOAD = 'payload';

    public const DIFF = 'diff';

    /** توضیحِ اختیاریِ تأمین‌کننده برای ادمین. */
    public const NOTE = 'note';

    public const STATUS = 'status';

    public const ADMIN_ID = 'admin_id';

    public const ADMIN_NOTE = 'admin_note';

    public const DECIDED_AT = 'decided_at';

    public const TYPE_CREATE_PRODUCT = 'create_product';

    public const TYPE_UPDATE_PRODUCT = 'update_product';

    public const TYPE_CREATE_VARIANT = 'create_variant';

    public const TYPE_UPDATE_VARIANT = 'update_variant';

    public const TYPES = [
        self::TYPE_CREATE_PRODUCT,
        self::TYPE_UPDATE_PRODUCT,
        self::TYPE_CREATE_VARIANT,
        self::TYPE_UPDATE_VARIANT,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * مسیرِ ذخیره‌ی فایل‌های پیوستِ درخواست روی دیسکِ `public`، تا وقتی تأیید
     * نشده. با تأیید به `products/` و `product-videos/` (همان‌جا که ادمین
     * می‌نویسد) منتقل می‌شوند.
     */
    public const UPLOAD_DIR = 'product-change-requests';

    /**
     * `status`, `admin_id`, `admin_note`, `decided_at` عمداً fillable **نیستند** —
     * همان قاعده‌ی `SupplierRequest`: تصمیم فقط از مسیرِ ادمین (`forceFill`)
     * نوشته می‌شود، نه با بدنه‌ی درخواست.
     */
    protected $fillable = [
        self::SUPPLIER_USER_ID,
        self::TYPE,
        self::PRODUCT_ID,
        self::PRODUCT_VARIANT_ID,
        self::PAYLOAD,
        self::DIFF,
        self::NOTE,
    ];

    /** همان پیش‌فرضِ دیتابیس — تا ردیفِ تازه‌ساخته پیش از `refresh()` هم وضعیت داشته باشد. */
    protected $attributes = [
        self::STATUS => self::STATUS_PENDING,
    ];

    protected $casts = [
        self::PAYLOAD => 'array',
        self::DIFF => 'array',
        self::DECIDED_AT => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, self::SUPPLIER_USER_ID);
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ADMIN_ID);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, self::PRODUCT_ID);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    public function isPending(): bool
    {
        return $this->{self::STATUS} === self::STATUS_PENDING;
    }

    public function isProductType(): bool
    {
        return in_array($this->{self::TYPE}, [self::TYPE_CREATE_PRODUCT, self::TYPE_UPDATE_PRODUCT], true);
    }

    /** @param  Builder<ProductChangeRequest>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where(self::STATUS, self::STATUS_PENDING);
    }
}
