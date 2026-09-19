<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Province;
use App\Models\ProvinceCity;
use App\Models\SupplierActivityCategory;

/**
 * بدنه‌ی کاملِ «درخواستِ احرازِ صلاحیت» برای تست‌ها.
 *
 * از ۱۴۰۵/۰۶/۲۸ `StoreSupplierProfileRequest` فیلدهای حقوقی را اجباری می‌کند
 * (اسپکِ یکپارچه‌سازی §۴-۱)، پس هر تستی که به `POST /api/profile/supplier`
 * می‌زند از همین‌جا بدنه می‌گیرد — نه اینکه هر کدام یک کپیِ ناقص بسازند.
 * پیش‌فرض شخصِ حقیقی است تا «آگهی تأسیس» لازم نباشد؛ تستِ شخصِ حقوقی
 * صراحتاً `entity_type => company` می‌دهد.
 */
trait BuildsSupplierQualification
{
    /** کدِ ملیِ معتبر (رقمِ کنترلِ درست) برای نماینده‌ی قانونی. */
    protected const VALID_NATIONAL_CODE = '0499370899';

    /** @return array<string, mixed> */
    protected function qualificationPayload(array $overrides = []): array
    {
        $province = Province::query()->first() ?? Province::create(['Name' => 'اصفهان']);
        $city = ProvinceCity::query()->where('ProvinceID', $province->id)->first()
            ?? ProvinceCity::create(['ProvinceID' => $province->id, 'Name' => 'اصفهان']);
        $category = SupplierActivityCategory::create(['name' => 'میلگرد '.uniqid()]);

        return array_merge([
            'entity_type' => 'individual',
            'company' => 'کارگاه فولاد آزمایشی',
            'representative_name' => 'علی کاظمی',
            'representative_national_code' => self::VALID_NATIONAL_CODE,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'factory_address' => 'اصفهان، شهرک صنعتی، خیابان دوم، پلاک ۱۲',
            'postal_code' => '8158913811',
            'activity_category_id' => [$category->id],
        ], $overrides);
    }
}
