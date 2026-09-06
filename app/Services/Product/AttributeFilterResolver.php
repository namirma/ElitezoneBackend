<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\AttributeValue;
use Illuminate\Http\Request;

/**
 * فیلترِ ویژگی: `filter_values[]` → idِ مقدار (`attribute_values.id`).
 *
 * تنها سطحی که واقعاً تفکیک می‌کند همین است. `filter_categories[]` روی **گروه**
 * فیلتر می‌کند («هر واریانتی که اصلاً گرید دارد»)، پس به‌تنهایی تقریباً هیچ‌چیز
 * را محدود نمی‌کند.
 *
 * ## لایه‌ی سازگاریِ برچسبی حذف شد — چون هرگز کار نمی‌کرد
 *
 * قبلاً اینجا شکلِ دومی هم پذیرفته می‌شد: برچسبِ گروه به‌عنوان نامِ پارامتر
 * (`?سایز (قطر)=100`)، چون سایدبارِ آن‌روزِ فرانت همین را می‌فرستاد. آن کد
 * نامِ پارامتر را با `attributes.name` تطبیق می‌داد.
 *
 * تیمِ فرانت روی کاتالوگِ واقعی اندازه‌گیری کرد و نشان داد **هیچ‌وقت محدود
 * نمی‌کرده**: در کاتالوگِ واقعی `attributes.name` نامِ ماشینیِ انگلیسی است
 * (`size`, `grade`) و `display_name` فارسی — و آنچه سایدبار می‌فرستاد
 * `display_name` بود. تطبیق همیشه شکست می‌خورد و پاسخ، کلِ فهرست بود.
 *
 * تستِ آن مسیر سبز بود چون داده‌ی سیدرِ نمایشی `name` و `display_name` را یکی
 * می‌گذارد — یعنی تست روی داده‌ای می‌گذشت که شکلِ داده‌ی واقعی را نداشت.
 *
 * مسیر **حذف** شد نه اینکه روی `display_name` هم سوار شود: فرانت به
 * `filter_values[]` مهاجرت کرده، و مسیری که ادعا می‌کند کار می‌کند ولی نمی‌کند
 * از نبودنش بدتر است. ضمناً برچسبِ فارسی به‌عنوان نامِ پارامتر یعنی تغییرِ یک
 * عنوان در پنل ادمین فیلترِ فرانت را می‌شکند.
 */
class AttributeFilterResolver
{
    /**
     * @param  list<int|string>  $explicit  مقدارهای `filter_values[]`
     * @return list<int>
     */
    public function resolve(Request $request, array $explicit = []): array
    {
        $ids = array_map('intval', array_filter($explicit, 'is_numeric'));

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
}
