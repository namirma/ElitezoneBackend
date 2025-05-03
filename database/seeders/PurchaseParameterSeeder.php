<?php

namespace Database\Seeders;

use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use Illuminate\Database\Seeder;

class PurchaseParameterSeeder extends Seeder
{
    public function run()
    {
        // گروه نوع مشتری
        $customerTypeGroup = PurchaseParameterGroup::updateOrCreate(
            ['code' => 'customer_type'],
            [
                'name' => 'نوع مشتری',
                'description' => 'نوع مشتری شما چیست؟',
                'order' => 1,
                'is_active' => true,
            ]
        );

        // گروه وضعیت مشاور
        $consultantGroup = PurchaseParameterGroup::updateOrCreate(
            ['code' => 'consultant_status'],
            [
                'name' => 'وضعیت مشاور',
                'description' => 'آیا مشاور دارید؟',
                'order' => 2,
                'is_active' => true,
            ]
        );

        // گروه هدف خرید
        $purposeGroup = PurchaseParameterGroup::updateOrCreate(
            ['code' => 'purchase_purpose'],
            [
                'name' => 'هدف خرید',
                'description' => 'هدف شما از خرید چیست؟',
                'order' => 3,
                'is_active' => true,
            ]
        );

        // دریافت دسته‌بندی‌های فیلتر قیمت با بررسی وجودشان
        $customerWithoutConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'customer_without_consultant'],
            ['name' => 'Customer Without Consultant'] // Add a default name
        );
        $customerWithConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'customer_with_consultant'],
            ['name' => 'Customer With Consultant'] // Add a default name
        );
        $engineerWithoutConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'engineer_without_consultant'],
            ['name' => 'Engineer Without Consultant'] // Add a default name
        );
        $engineerWithConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'engineer_with_consultant'],
            ['name' => 'Engineer With Consultant'] // Add a default name
        );
        $contractorWithoutConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'contractor_without_consultant'],
            ['name' => 'Contractor Without Consultant'] // Add a default name
        );
        $contractorWithConsultant = PriceFilterCategory::firstOrCreate(
            ['code' => 'contractor_with_consultant'],
            ['name' => 'Contractor With Consultant'] // Add a default name
        );

        // مقادیر پارامتر نوع مشتری
        $customerTypeValues = [
            [
                'name' => 'کارفرما',
                'slug' => 'Employer',
                'code' => 'employer',
                'description' => 'شما کارفرما هستید',
                'order' => 1,
                'price_filter_category_id' => $customerWithoutConsultant->id, // Example logic for assigning price filter category
            ],
            [
                'name' => 'مهندس',
                'slug' => 'Engineer',
                'code' => 'engineer',
                'description' => 'شما مهندس هستید',
                'order' => 2,
                'price_filter_category_id' => $engineerWithConsultant->id, // Example logic
            ],
            [
                'name' => 'پیمانکار',
                'slug' => 'Contractor',
                'code' => 'contractor',
                'description' => 'شما پیمانکار هستید',
                'order' => 3,
                'price_filter_category_id' => $contractorWithConsultant->id, // Example logic
            ],
        ];

        foreach ($customerTypeValues as $value) {
            PurchaseParameterValue::updateOrCreate(
                [
                    'group_id' => $customerTypeGroup->id,
                    'code' => $value['code'],
                ],
                array_merge(['group_id' => $customerTypeGroup->id], $value)
            );
        }

        // مقادیر پارامتر وضعیت مشاور
        $consultantValues = [
            [
                'name' => 'دارای مشاور',
                'slug' => 'Consultant_Yes',
                'code' => 'with_consultant',
                'description' => 'شما دارای مشاور هستید',
                'order' => 1,
                'price_filter_category_id' => $customerWithConsultant->id, // Example logic
            ],
            [
                'name' => 'بدون مشاور',
                'slug' => 'Consultant_No',
                'code' => 'without_consultant',
                'description' => 'شما بدون مشاور هستید',
                'order' => 2,
                'price_filter_category_id' => $customerWithoutConsultant->id, // Example logic
            ],
        ];

        foreach ($consultantValues as $value) {
            PurchaseParameterValue::updateOrCreate(
                [
                    'group_id' => $consultantGroup->id,
                    'code' => $value['code'],
                ],
                array_merge(['group_id' => $consultantGroup->id], $value)
            );
        }

        // مقادیر پارامتر هدف خرید
        $purposeValues = [
            [
                'name' => 'برای خودم',
                'slug' => 'Buy_For_Myself',
                'code' => 'for_myself',
                'description' => 'خرید برای استفاده شخصی',
                'order' => 1,
                'price_filter_category_id' => null,
            ],
            [
                'name' => 'برای پروژه',
                'slug' => 'Buy_For_Project',
                'code' => 'for_project',
                'description' => 'خرید برای استفاده در پروژه',
                'order' => 2,
                'price_filter_category_id' => null,
            ],
            [
                'name' => 'برای فروش مجدد',
                'slug' => 'Buy_For_Resale',
                'code' => 'for_resale',
                'description' => 'خرید برای فروش مجدد',
                'order' => 3,
                'price_filter_category_id' => null,
            ],
        ];

        foreach ($purposeValues as $value) {
            PurchaseParameterValue::updateOrCreate(
                [
                    'group_id' => $purposeGroup->id,
                    'code' => $value['code'],
                ],
                array_merge(['group_id' => $purposeGroup->id], $value)
            );
        }
    }
}
