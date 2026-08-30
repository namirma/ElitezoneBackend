<?php

namespace Database\Seeders\Concerns;

use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\AttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;

/**
 * واژگانِ مشترکِ ویژگی‌های کاتالوگ و راهِ وصل‌کردنشان به واریانت.
 *
 * ‏**سه سطح داریم و نقش‌شان قابلِ جابه‌جایی نیست:**
 *
 *   `attribute_types`  → گروهِ کلان («مشخصات فنی») — سایدبار نادیده می‌گیردش
 *   `attributes`       → **سرتیترِ گروه در سایدبار** («گرید»)
 *   `attribute_values` → **گزینه‌ی قابلِ تیک‌زدن** («A3») و مقدارِ چیپِ واریانت
 *
 * این trait وجود دارد چون دو سیدر همین نگاشت را لازم دارند و وقتی هرکدام
 * نسخه‌ی خودش را داشت، از هم پاشیدند: `ProductSeeder` گزینه‌ها را یک سطح
 * بالاتر (روی `attributes`) می‌ساخت، و سایدبارِ استیجینگ به‌جای گروهِ «نوع
 * محصول» با دو گزینه، دو گروهِ جدا به نامِ «شاخه» و «کلاف» نشان می‌داد که
 * هرکدام فقط خودشان را داشتند.
 */
trait SeedsProductAttributes
{
    /**
     * گروهِ کلانی که سایدبار زیرِ آن ساخته می‌شود.
     *
     * نامش دلبخواه نیست — داده‌ی واقعیِ کاتالوگ از قبل زیرِ همین
     * `technical_specs` نشسته است. هر نامِ دیگری یک گروهِ رقیب می‌ساخت و
     * سایدبار دو «گرید» نشان می‌داد.
     */
    private const ATTRIBUTE_TYPE = ['name' => 'technical_specs', 'label' => 'مشخصات فنی'];

    /**
     * سرتیترِ گروه ← گزینه‌ها.
     *
     * نام‌ها عمداً **عینِ داده‌ی واقعی** است تا `updateOrCreate` همان ردیف‌ها را
     * بازاستفاده کند، نه اینکه کنارشان ردیفِ تازه بسازد. به همین دلیل «سایز
     * (قطر)» است و نه «سایز» — برای آجر و شیرفلکه «قطر» دقیق نیست، ولی داشتنِ
     * دو گروهِ سایزِ موازی در سایدبار بدتر است.
     *
     * ارقام هم لاتین می‌مانند («۱۲ متر» نه): داده‌ی واقعی «12 متر» دارد و شکلِ
     * فارسی یک ردیفِ مقدارِ دومِ تقریباً یکسان می‌ساخت.
     */
    private const VOCABULARY = [
        'نوع محصول' => ['شاخه', 'کلاف', 'IPE', 'نبشی', 'ورق', 'سفال', 'نسوز', 'کشویی', 'پروانه‌ای'],
        'نوع سطح' => ['ساده', 'آجدار'],
        'گرید' => ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'ST37'],
        'سایز (قطر)' => [
            // میلگرد — عینِ مقادیرِ کاتالوگِ واقعی، تا سایدبارِ استیجینگ همان
            // چیزی را نشان بدهد که سایتِ اصلی نشان می‌دهد.
            '65', '80', '100', '120', '140', '160', '180', '200',
            '220', '250', '260', '280', '300', '320', '360', '400',
            // بقیه‌ی دسته‌ها.
            '2', '3', '8', '10', '12', '14', '16', '18', '50', '60',
            '10×20×20', '15×20×20', '22×11×5.5', '2 اینچ', '3 اینچ', '4 اینچ',
        ],
        'طول شاخه' => [
            '6 متر', '7 متر', '7.2 متر', '7.8 متر', '7.9 متر', '8 متر', '8.7 متر',
            '8.9 متر', '8.95 متر', '9 متر', '9.1 متر', '10 متر', '10.5 متر',
            '11.7 متر', '12 متر', '15 متر', '15.5 متر', '18 متر', '20 متر', '25 متر',
        ],
        'بسته‌بندی' => ['نرمال', 'بندیل', 'پالت'],
    ];

    /**
     * گروه‌ها، گزینه‌ها و ردیفِ مقدارِ هر گزینه را می‌سازد.
     *
     * ردیفِ `attribute_values` اختیاری نیست:
     * ‏`product_variant_attributes.attribute_value_id` ستونِ NOT NULL است، پس
     * بدون آن اصلاً نمی‌شود ویژگی را به واریانت وصل کرد.
     */
    private function ensureAttributeVocabulary(): void
    {
        $type = AttributeType::updateOrCreate(
            [AttributeType::NAME => self::ATTRIBUTE_TYPE['name']],
            [
                AttributeType::DISPLAY_NAME => self::ATTRIBUTE_TYPE['label'],
                AttributeType::INPUT_TYPE => 'select',
            ],
        );

        foreach (self::VOCABULARY as $group => $options) {
            $attribute = Attribute::updateOrCreate(
                [Attribute::ATTRIBUTE_TYPE_ID => $type->id, Attribute::NAME => $group],
                [Attribute::DISPLAY_NAME => $group],
            );

            foreach ($options as $option) {
                AttributeValue::updateOrCreate(
                    [
                        AttributeValue::ATTRIBUTE_ID => $attribute->id,
                        AttributeValue::VALUE => $option,
                    ],
                    [AttributeValue::DISPLAY_VALUE => $option],
                );
            }
        }
    }

    /**
     * ویژگی‌های یک واریانت را می‌نشاند.
     *
     * `sync` و نه `syncWithoutDetaching`: نقشه‌ی سیدر مرجع است، پس اگر گزینه‌ای
     * از نقشه حذف شد باید از دیتابیس هم برود.
     *
     * @param  array<string,string>  $map  سرتیترِ گروه ← گزینه
     */
    private function attachAttributes(ProductVariant $variant, array $map): void
    {
        $pivot = [];

        foreach ($map as $group => $option) {
            $attribute = Attribute::query()
                ->whereHas('attributeType', fn ($q) => $q->where(AttributeType::NAME, self::ATTRIBUTE_TYPE['name']))
                ->where(Attribute::NAME, $group)
                ->first();

            // مقدارِ **همین** گزینه، نه اولین مقدارِ گروه: هر گروه چند گزینه
            // دارد، پس `first()` بی‌قید یعنی همه‌ی واریانت‌ها یک مقدار بگیرند.
            $value = $attribute === null
                ? null
                : AttributeValue::where(AttributeValue::ATTRIBUTE_ID, $attribute->id)
                    ->where(AttributeValue::VALUE, $option)
                    ->first();

            // نبودِ گزینه یا مقدارش یعنی نقشه و `VOCABULARY` از هم پاشیده‌اند.
            // رد شدن با هشدار، چون خرابیِ یک ویژگی نباید کلِ کاتالوگ را بخواباند.
            if ($value === null) {
                $this->command?->warn(
                    "Seeder: option {$group}/{$option} is not in VOCABULARY — skipped."
                );

                continue;
            }

            $pivot[$attribute->id] = [ProductVariantAttribute::ATTRIBUTE_VALUE_ID => $value->id];
        }

        $variant->variantAttributes()->sync($pivot);
    }
}
