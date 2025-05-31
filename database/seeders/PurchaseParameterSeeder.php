<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use App\Models\CustomerType;

class PurchaseParameterSeeder extends Seeder
{
    public function run(): void
    {
        // 1) دسته‌های فیلتر قیمت
        $filterCats = [
            ['code' => 'employer_without_consultant', 'name' => 'کارفرما بدون مشاور',       'sort_order' => 1],
            ['code' => 'employer_with_consultant',    'name' => 'کارفرما با مشاور',       'sort_order' => 2],
            ['code' => 'engineer_with_consultant',    'name' => 'مهندس با مشاور',        'sort_order' => 3],
            ['code' => 'contractor_with_consultant',  'name' => 'پیمانکار با مشاور',     'sort_order' => 4],
        ];
        foreach ($filterCats as $cat) {
            PriceFilterCategory::updateOrCreate(
                ['code' => $cat['code']],
                [
                    'name'        => $cat['name'],
                    'description' => "فیلترهای قیمت برای {$cat['name']}",
                    'sort_order'  => $cat['sort_order'],
                    'is_active'   => true,
                ]
            );
        }

        // 2) گروه‌های پارامتر خرید
        $groups = [
            ['code' => 'customer_type',      'name' => 'نوع مشتری',     'order' => 1],
            ['code' => 'consultant_status',  'name' => 'وضعیت مشاور',  'order' => 2],
            ['code' => 'purchase_purpose',   'name' => 'هدف خرید',      'order' => 3],
        ];
        foreach ($groups as $g) {
            PurchaseParameterGroup::updateOrCreate(
                ['code' => $g['code']],
                ['name' => $g['name'], 'description' => "{$g['name']} شما چیست؟", 'order' => $g['order'], 'is_active' => true]
            );
        }

        // 3) مقادیر گروه "نوع مشتری"
        $customerTypeGroup = PurchaseParameterGroup::where('code','customer_type')->first();
        $customerValues = [
            ['code' => 'employer',   'name' => 'کارفرما',   'order' => 1, 'filter_cat' => 'employer_without_consultant'],
            ['code' => 'engineer',   'name' => 'مهندس',     'order' => 2, 'filter_cat' => 'engineer_with_consultant'],
            ['code' => 'contractor', 'name' => 'پیمانکار',  'order' => 3, 'filter_cat' => 'contractor_with_consultant'],
        ];
        foreach ($customerValues as $v) {
            $pfId = PriceFilterCategory::where('code', $v['filter_cat'])->value('id');
            PurchaseParameterValue::updateOrCreate(
                ['group_id' => $customerTypeGroup->id, 'code' => $v['code']],
                [
                    'group_id'                 => $customerTypeGroup->id,
                    'name'                     => $v['name'],
                    'slug'                     => $v['code'],
                    'code'                     => $v['code'],
                    'order'                    => $v['order'],
                    'is_active'                => true,
                    'price_filter_category_id' => $pfId,
                ]
            );
        }

        // 4) مقادیر گروه "وضعیت مشاور"
        $consultantGroup = PurchaseParameterGroup::where('code','consultant_status')->first();
        $consultantValues = [
            ['code' => 'with_consultant',    'name' => 'دارای مشاور',     'order' => 1],
            ['code' => 'without_consultant', 'name' => 'بدون مشاور',      'order' => 2],
        ];
        foreach ($consultantValues as $v) {
            PurchaseParameterValue::updateOrCreate(
                ['group_id' => $consultantGroup->id, 'code' => $v['code']],
                [
                    'group_id'                 => $consultantGroup->id,
                    'name'                     => $v['name'],
                    'slug'                     => $v['code'],
                    'code'                     => $v['code'],
                    'order'                    => $v['order'],
                    'is_active'                => true,
                    // برای مشاور، نگاشت به همان دسته فیلتر price_filter_category نداریم:
                    'price_filter_category_id' => null,
                ]
            );
        }

        // 5) مقادیر گروه "هدف خرید"
        $purposeGroup = PurchaseParameterGroup::where('code','purchase_purpose')->first();
        $purposeValues = [
            ['code' => 'for_myself',  'name' => 'برای خودم',        'order' => 1],
            ['code' => 'for_other', 'name' => 'برای دیگری',       'order' => 2],
        ];
        foreach ($purposeValues as $v) {
            PurchaseParameterValue::updateOrCreate(
                ['group_id' => $purposeGroup->id, 'code' => $v['code']],
                [
                    'group_id'                 => $purposeGroup->id,
                    'name'                     => $v['name'],
                    'slug'                     => $v['code'],
                    'code'                     => $v['code'],
                    'order'                    => $v['order'],
                    'is_active'                => true,
                    'price_filter_category_id' => null,
                ]
            );
        }

        // 6) جدول customer_types (برای گزارش‌گیری و نگاشت در Cart)
        $cts = [
            ['code' => 'employer_without_consultant', 'name' => 'کارفرما بدون مشاور', 'has_consultant' => false],
            ['code' => 'employer_with_consultant',    'name' => 'کارفرما با مشاور',   'has_consultant' => true],
            ['code' => 'engineer_with_consultant',    'name' => 'مهندس با مشاور',    'has_consultant' => true],
            ['code' => 'contractor_with_consultant',  'name' => 'پیمانکار با مشاور', 'has_consultant' => true],
        ];
        foreach ($cts as $c) {
            CustomerType::updateOrCreate(
                ['code' => $c['code']],
                ['name' => $c['name'], 'has_consultant' => $c['has_consultant']]
            );
        }
    }
}
