<?php

namespace App\Http\Resources\Product;

use App\Http\Resources\BaseJsonResource;
use App\Models\OrderUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * یک واحد سفارشِ مجازِ محصول — «کدام واحد، با چه ضریب تبدیلی، تا چه سقفی».
 *
 * @mixin OrderUnit
 *
 * باکس خریدِ سایت اصلی برای رندر یک اسلات **هر دو** عدد را لازم دارد:
 *  • `amount`       ضریب تبدیل به واحد پایه — مبنای محاسبهٔ وزن (و در نتیجه قیمت)
 *  • `max_quantity` سقف تعداد از پیوت `product_order_units` — دامنهٔ ورودی
 *
 * تا نشست ۲۱ هیچ endpointی هر دو را با هم نمی‌داد (`ProductResource` فقط دومی،
 * `ProductDetailResource` فقط اولی)، پس فرانت مجبور بود ۲۵۰۰۰/۵۰۰۰ را هاردکد کند و
 * سقفِ ثبت‌شدهٔ ادمین هرگز به مشتری نمی‌رسید. این Resource همان شکلِ واحد را به هر سه
 * مصرف‌کننده (لیست محصول، جزئیات محصول، آیتم سبد) می‌دهد.
 *
 * ⚠️ روی این Resource باید pivot لود شده باشد (`$product->orderUnits`).
 */
class ProductOrderUnitResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_unit_id' => $this->id,
            'unit_name' => $this->name ?? 'نامشخص',
            'code' => $this->code,
            'unit' => $this->unit,
            // (float) عمدی است: ستون decimal لاراول رشته برمی‌گرداند («5000.00») و
            // فرانت با این عدد ضرب می‌کند. رشته‌فرستادن یعنی هر مصرف‌کننده خودش parse
            // کند — همان‌جایی که باگ ۱۰۰ برابرِ نشست ۲۰ متولد شد.
            'amount' => (float) $this->amount,
            'max_quantity' => (float) ($this->pivot->max_quantity ?? 0),
        ];
    }

    /**
     * مجموعه‌ای مرتب‌شده: بستهٔ بزرگ‌تر اول، واحد پایه آخر — همان ترتیبی که سایت
     * اسلات‌ها را رندر می‌کند، تا ترتیب بصری به ترتیب insert در دیتابیس وابسته نباشد.
     */
    public static function sortedCollection(?Collection $orderUnits): AnonymousResourceCollection
    {
        return self::collection(
            ($orderUnits ?? collect())
                ->sortByDesc(fn (OrderUnit $unit) => (float) $unit->amount)
                ->values()
        );
    }
}
