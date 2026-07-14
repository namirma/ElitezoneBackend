<?php

namespace Database\Seeders;

use App\Models\CustomerType;
use App\Models\PriceFilterCategory;
use App\Models\PurchaseParameterGroup;
use App\Models\PurchaseParameterValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurchaseParameterSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $categories = [
                ['code' => 'employer_without_consultant', 'name' => 'کارفرما بدون الیت یار', 'sort_order' => 1],
                ['code' => 'engineer_with_consultant',    'name' => 'مهندس با الیت یار',     'sort_order' => 2],
            ];

            foreach ($categories as $cat) {
                PriceFilterCategory::updateOrCreate(
                    ['code' => $cat['code']],
                    [
                        'name' => $cat['name'],
                        'description' => "فیلترهای قیمت برای {$cat['name']}",
                        'sort_order' => $cat['sort_order'],
                        'is_active' => true,
                    ]
                );
            }

            $allowedCodes = collect($categories)->pluck('code')->all();
            PriceFilterCategory::whereNotIn('code', $allowedCodes)->get()->each(function ($c) {
                $c->filters()->detach();
                $c->delete();
            });

            $purchasePurpose = PurchaseParameterGroup::updateOrCreate(
                ['code' => 'purchase_purpose'],
                ['name' => 'هدف خرید', 'description' => 'هدف خرید شما چیست؟', 'order' => 1, 'is_active' => true]
            );

            PurchaseParameterGroup::whereIn('code', ['customer_type', 'consultant_status'])->update(['is_active' => false]);

            $purposeValues = [
                ['code' => 'for_myself', 'name' => 'برای خودم',  'order' => 1],
                ['code' => 'for_other',  'name' => 'برای دیگری', 'order' => 2],
            ];
            foreach ($purposeValues as $v) {
                PurchaseParameterValue::updateOrCreate(
                    ['group_id' => $purchasePurpose->id, 'code' => $v['code']],
                    [
                        'group_id' => $purchasePurpose->id,
                        'name' => $v['name'],
                        'slug' => $v['code'],
                        'code' => $v['code'],
                        'order' => $v['order'],
                        'is_active' => true,
                        'price_filter_category_id' => null,
                    ]
                );
            }

            $customerTypes = [
                ['code' => 'employer_without_consultant', 'name' => 'کارفرما بدون الیت یار', 'has_elite_yar' => false],
                ['code' => 'engineer_with_consultant',    'name' => 'مهندس با الیت یار',     'has_elite_yar' => true],
            ];
            foreach ($customerTypes as $ct) {
                CustomerType::updateOrCreate(
                    ['code' => $ct['code']],
                    ['name' => $ct['name'], 'has_elite_yar' => $ct['has_elite_yar']]
                );
            }
            CustomerType::whereNotIn('code', collect($customerTypes)->pluck('code'))->delete();
        });
    }
}
