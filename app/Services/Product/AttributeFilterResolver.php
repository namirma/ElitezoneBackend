<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\Request;

/**
 * فیلترِ ویژگی را از درخواست بیرون می‌کشد و همیشه به **idِ مقدار**
 * (`attribute_values.id`) می‌رساند — تنها سطحی که واقعاً تفکیک می‌کند.
 *
 * دو شکل پذیرفته می‌شود:
 *
 *   ۱. `filter_values[]=57`            ← **قرارداد رسمی**
 *   ۲. `سایز (قطر)=100&گرید=A3`        ← سازگاری با فرانتِ فعلی
 *
 * شکلِ دوم اختراعِ ما نیست: سایدبارِ فعلی همین را می‌فرستد (در URL دیده می‌شود:
 * `?category=2&page=1&سایز+%28قطر%29=3`). بک‌اند پارامترِ ناشناس را نادیده
 * می‌گرفت، پس تیک زدنِ «۳» هیچ‌چیز را محدود نمی‌کرد و کاربر همه‌ی واریانت‌ها را
 * می‌دید — بدون هیچ خطایی که سرنخ بدهد.
 *
 * ⚠️ **این لایه‌ی سازگاری است، نه قراردادِ بلندمدت.** برچسبِ فارسی به‌عنوان
 * نامِ پارامتر یعنی تغییرِ یک عنوان در پنل ادمین، فیلترِ فرانت را می‌شکند.
 * وقتی فرانت به `filter_values[]` مهاجرت کرد، این مسیر می‌تواند برود.
 *
 * محدود و ارزان است: تا وقتی هیچ پارامتری با نامِ یک ویژگیِ واقعی نیامده
 * باشد، **هیچ کوئریِ اضافه‌ای زده نمی‌شود**.
 */
class AttributeFilterResolver
{
    /**
     * پارامترهایی که هرگز نامِ ویژگی نیستند — تا نامِ تصادفیِ یکسان،
     * صفحه‌بندی یا مرتب‌سازی را به فیلتر تبدیل نکند.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'category', 'search', 'sort', 'page', 'per_page', 'availability',
        'with_vat', 'debug_prices', 'filter_categories', 'filter_values',
    ];

    /**
     * @param  list<int|string>  $explicit  مقدارهای `filter_values[]` که از قبل آمده‌اند
     * @return list<int>
     */
    public function resolve(Request $request, array $explicit = []): array
    {
        $ids = array_map('intval', array_filter($explicit, 'is_numeric'));

        $pairs = $this->labelPairs($request);

        if ($pairs === []) {
            return array_values(array_unique($ids));
        }

        // ⚠️ PHP در ساختِ `$_GET` نامِ پارامتر را **مثله می‌کند**: فاصله و نقطه
        // به `_` تبدیل می‌شوند. پس `سایز (قطر)` به‌صورت `سایز_(قطر)` می‌رسد و
        // جست‌وجوی مستقیم در `attributes.name` هرگز مطابقت پیدا نمی‌کند.
        // به‌جای تلاش برای برگرداندنِ نام (که یک‌طرفه است)، نامِ ویژگی‌ها را با
        // همان قاعده مثله می‌کنیم و از آن سمت نگاشت می‌سازیم.
        $byMangledName = Attribute::query()
            ->get([Attribute::ID, Attribute::NAME])
            ->keyBy(fn (Attribute $a) => $this->mangle((string) $a->{Attribute::NAME}));

        foreach ($pairs as $key => $option) {
            $attribute = $byMangledName->get($key);

            if ($attribute === null) {
                continue;
            }

            $id = AttributeValue::query()
                ->where(AttributeValue::ATTRIBUTE_ID, $attribute->id)
                ->where(AttributeValue::VALUE, $option)
                ->value(AttributeValue::ID);

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * idهای انتخاب‌شده را بر اساس **گروهی که به آن تعلق دارند** دسته می‌کند.
     *
     * سایدبار وقتی دو تیک در دو گروه می‌خورد، «هم A3 **و** هم سایز ۱۰۰» را
     * می‌خواهد؛ ولی یک `whereIn` روی همه‌ی idها یعنی «A3 **یا** سایز ۱۰۰» —
     * انتخابِ دوم به‌جای باریک‌ترکردنِ نتیجه، پهن‌ترش می‌کرد. با یک `whereHas`
     * به‌ازای هر گروه، داخلِ گروه OR می‌ماند (طبیعی: «A3 یا A4») و بینِ
     * گروه‌ها AND می‌شود — همان چیزی که هر فیلترِ facetدار می‌کند.
     *
     * @param  list<int>  $valueIds
     * @return list<list<int>> یک دسته به‌ازای هر گروه
     */
    public function groupByAttribute(array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        return AttributeValue::query()
            ->whereIn(AttributeValue::ID, $valueIds)
            ->get([AttributeValue::ID, AttributeValue::ATTRIBUTE_ID])
            ->groupBy(AttributeValue::ATTRIBUTE_ID)
            ->map(fn ($rows) => $rows->pluck(AttributeValue::ID)->map('intval')->all())
            ->values()
            ->all();
    }

    /** همان تبدیلی که PHP سرِ ساختِ `$_GET` روی نامِ پارامتر انجام می‌دهد. */
    private function mangle(string $name): string
    {
        return str_replace([' ', '.'], '_', $name);
    }

    /**
     * جفت‌های «نامِ گروه ← گزینه» از query string.
     *
     * فقط رشته‌ها؛ آرایه‌ها و پارامترهای رزروشده رد می‌شوند.
     *
     * @return array<string, string>
     */
    private function labelPairs(Request $request): array
    {
        $pairs = [];

        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }

            if (in_array($key, self::RESERVED, true)) {
                continue;
            }

            $pairs[$key] = $value;
        }

        return $pairs;
    }
}
