<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * موجودی یک واریانت در یک مبدأ مشخص (کارخانه + انبار).
 *
 * منبعِ حقیقت برای «آیا این سفارش قابل تأمین است» — کلید همان سه‌تاییِ
 * `product_prices` است. `product_variants.stock_quantity` دیگر مرجعِ سفارش
 * نیست و فقط جمعِ نمایشی است؛ به `syncVariantRollup()` نگاه کنید.
 */
class FactoryStock extends Model
{
    use HasFactory;

    public const ID = 'id';

    public const PRODUCT_VARIANT_ID = 'product_variant_id';

    public const FACTORY_ID = 'factory_id';

    public const FACTORY_LOCATION_ID = 'factory_location_id';

    public const QUANTITY = 'quantity';

    protected $fillable = [
        self::PRODUCT_VARIANT_ID,
        self::FACTORY_ID,
        self::FACTORY_LOCATION_ID,
        self::QUANTITY,
    ];

    protected $casts = [
        self::QUANTITY => 'decimal:3',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, self::PRODUCT_VARIANT_ID);
    }

    /** نامِ `factory` ممنوع است — با `HasFactory::factory()` تصادم می‌کند. */
    public function originFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, self::FACTORY_ID);
    }

    public function originFactoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, self::FACTORY_LOCATION_ID);
    }

    /**
     * ردیفِ این مبدأ را زیر قفل برمی‌گرداند — یا null اگر وجود نداشته باشد.
     *
     * فراخوان باید حالتِ null را به‌عنوان «این مبدأ هنوز موجودیِ اختصاصی ندارد»
     * بفهمد و به عددِ سراسری برگردد، نه اینکه صفر فرض کند: صفر یعنی «تمام شده» و
     * فروش را می‌خواباند، در حالی که سه‌تاییِ ثبت‌نشده صرفاً یعنی «هنوز پر نشده».
     */
    public static function lockRow(int $variantId, ?int $factoryId, ?int $locationId): ?self
    {
        if (! $factoryId || ! $locationId) {
            return null;
        }

        return static::query()
            ->where(self::PRODUCT_VARIANT_ID, $variantId)
            ->where(self::FACTORY_ID, $factoryId)
            ->where(self::FACTORY_LOCATION_ID, $locationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * `product_variants.stock_quantity` را برابرِ جمعِ مبادی می‌گذارد.
     *
     * آن ستون هنوز خوانده می‌شود — فیلترِ کاتالوگ (`stock_quantity > 0`)،
     * تحلیل‌ها، هشدارهای داشبورد و Resourceها. اگر به‌جای جمع، صرفاً با هر فروش
     * کم می‌شد، پس از فروشِ کلِ استخرِ قدیمی به صفر می‌رسید و **محصولی را که
     * واقعاً موجود است از کاتالوگ حذف می‌کرد**. جمع، این حالت را غیرممکن می‌کند.
     */
    public static function syncVariantRollup(int $variantId): void
    {
        $rows = static::query()->where(self::PRODUCT_VARIANT_ID, $variantId);

        // واریانتی که هیچ مبدأیی ندارد (هنوز قیمت‌گذاری نشده) نباید صفر شود —
        // جمعِ صفرِ «هیچ ردیفی نیست» با «همه‌جا تمام شده» یکی نیست، و اولی
        // موجودیِ لگاسیِ سالم را پاک می‌کرد.
        if (! $rows->exists()) {
            return;
        }

        DB::table('product_variants')
            ->where('id', $variantId)
            ->update(['stock_quantity' => (float) $rows->sum(self::QUANTITY)]);
    }
}
