<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use App\Models\AttributeType;
use App\Models\CategoryAttribute;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\OrderUnit;
use App\Models\ShippingMethod;
use App\Models\PriceFilterType;
use App\Models\PriceFilter;
use App\Models\PriceSetting;
use App\Models\FactoryLocation;
use App\Models\Factory;
use App\Models\CustomerType;
use App\Models\Badge;
use App\Models\PriceAdjustment;
use App\Models\Offer;
use App\Models\PriceFilterCategory;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;

class ProductSeeder extends Seeder
{
    public function run()
    {
        $categories = [
            [
                'name' => 'میلگرد',
                'slug' => 'rebar',
                'description' => 'انواع میلگرد ساختمانی',
                'image' => 'categories/rebar.jpg',
                'is_active' => true,
            ],
            [
                'name' => 'آهن آلات',
                'slug' => 'steel',
                'description' => 'انواع آهن آلات ساختمانی',
                'image' => 'categories/steel.jpg',
                'is_active' => true,
            ],
            [
                'name' => 'آجر',
                'slug' => 'brick',
                'description' => 'انواع آجر ساختمانی',
                'image' => 'categories/brick.jpg',
                'is_active' => true,
            ],
            [
                'name' => 'شیرآلات',
                'slug' => 'taps',
                'description' => 'انواع شیرآلات ساختمانی',
                'image' => 'categories/taps.jpg',
                'is_active' => true,
            ]
        ];

        foreach ($categories as $category) {
            ProductCategory::updateOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }

        $attributeTypes = [
            [
                'name' => 'type',
                'display_name' => 'نوع',
                'input_type' => 'select',
            ],
            [
                'name' => 'grade',
                'display_name' => 'گرید',
                'input_type' => 'select',
            ],
            [
                'name' => 'size',
                'display_name' => 'سایز',
                'input_type' => 'select',
            ],
        ];

        foreach ($attributeTypes as $type) {
            AttributeType::updateOrCreate(
                ['name' => $type['name']],
                $type
            );
        }

        // اختصاص ویژگی‌ها به دسته‌بندی میلگرد
        $rebarCategory = ProductCategory::where('slug', 'rebar')->first();

        if ($rebarCategory) {
            $typeAttr = AttributeType::where('name', 'type')->first();
            $gradeAttr = AttributeType::where('name', 'grade')->first();
            $sizeAttr = AttributeType::where('name', 'size')->first();

            if ($typeAttr) {
                CategoryAttribute::updateOrCreate(
                    [
                        'category_id' => $rebarCategory->id,
                        'attribute_type_id' => $typeAttr->id
                    ],
                    [
                        'is_required' => true,
                        'is_filterable' => true,
                        'sort_order' => 1
                    ]
                );
            }

            if ($gradeAttr) {
                CategoryAttribute::updateOrCreate(
                    [
                        'category_id' => $rebarCategory->id,
                        'attribute_type_id' => $gradeAttr->id
                    ],
                    [
                        'is_required' => true,
                        'is_filterable' => true,
                        'sort_order' => 2
                    ]
                );
            }

            if ($sizeAttr) {
                CategoryAttribute::updateOrCreate(
                    [
                        'category_id' => $rebarCategory->id,
                        'attribute_type_id' => $sizeAttr->id
                    ],
                    [
                        'is_required' => true,
                        'is_filterable' => true,
                        'sort_order' => 3
                    ]
                );
            }
        }

        $typeAttr = AttributeType::where('name', 'type')->first();
        if ($typeAttr) {
            $types = [
                ['name' => 'شاخه', 'display_name' => 'شاخه'],
                ['name' => 'کلاف', 'display_name' => 'کلاف'],
                ['name' => 'صنعتی', 'display_name' => 'صنعتی'],
            ];

            foreach ($types as $type) {
                Attribute::updateOrCreate(
                    [
                        'attribute_type_id' => $typeAttr->id,
                        'name' => $type['name'],
                    ],
                    [
                        'display_name' => $type['display_name'],
                    ]
                );
            }
        }

        // گریدهای میلگرد
        $gradeAttr = AttributeType::where('name', 'grade')->first();
        if ($gradeAttr) {
            $grades = [
                ['name' => 'A1', 'display_name' => 'A1'],
                ['name' => 'A2', 'display_name' => 'A2'],
                ['name' => 'A3', 'display_name' => 'A3'],
                ['name' => 'A4', 'display_name' => 'A4'],
            ];

            foreach ($grades as $grade) {
                Attribute::updateOrCreate(
                    [
                        'attribute_type_id' => $gradeAttr->id,
                        'name' => $grade['name'],
                    ],
                    [
                        'display_name' => $grade['display_name'],
                    ]
                );
            }
        }

        // سایزهای میلگرد
        $sizeAttr = AttributeType::where('name', 'size')->first();
        if ($sizeAttr) {
            $sizes = [
                ['name' => '5.5', 'display_name' => '5.5'],
                ['name' => '8', 'display_name' => '8'],
                ['name' => '10', 'display_name' => '10'],
                ['name' => '12', 'display_name' => '12'],
                ['name' => '14', 'display_name' => '14'],
                ['name' => '16', 'display_name' => '16'],
                ['name' => '18', 'display_name' => '18'],
                ['name' => '20', 'display_name' => '20'],
                ['name' => '22', 'display_name' => '22'],
                ['name' => '25', 'display_name' => '25'],
                ['name' => '28', 'display_name' => '28'],
                ['name' => '30', 'display_name' => '30'],
                ['name' => '32', 'display_name' => '32'],
                ['name' => '36', 'display_name' => '36'],
                ['name' => '40', 'display_name' => '40'],
                ['name' => '50', 'display_name' => '50'],
            ];

            foreach ($sizes as $size) {
                Attribute::updateOrCreate(
                    [
                        'attribute_type_id' => $sizeAttr->id,
                        'name' => $size['name'],
                    ],
                    [
                        'display_name' => $size['display_name'],
                    ]
                );
            }
        }

        $rebarCategory = ProductCategory::where('slug', 'rebar')->first();

        if (!$rebarCategory) {
            return;
        }

        // ایجاد محصول میلگرد آجدار
        $product = Product::updateOrCreate(
            ['slug' => 'rebar'],
            [
                'category_id' => $rebarCategory->id,
                'name' => 'میلگرد آجدار',
                'description' => 'میلگرد آجدار با کیفیت عالی مناسب برای ساخت و ساز. انواع میلگرد در سایزها و گریدهای مختلف.',
                'sku' => 'RBR-001',
                'is_active' => true,
                'is_featured' => true,
                'view_count' => 0,
                'items_per_page' => 12,
            ]
        );

        // افزودن تصاویر محصول
        $productImages = [
            [
                'image_path' => 'products/rebar/main.jpg',
                'is_primary' => true,
                'sort_order' => 0,
            ],
            [
                'image_path' => 'products/rebar/side.jpg',
                'is_primary' => false,
                'sort_order' => 1,
            ],
            [
                'image_path' => 'products/rebar/detail.jpg',
                'is_primary' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($productImages as $image) {
            ProductImage::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'image_path' => $image['image_path'],
                ],
                [
                    'is_primary' => $image['is_primary'],
                    'sort_order' => $image['sort_order'],
                ]
            );
        }

        $orderUnits = [
            [
                'name' => 'ظرفیت',
                'code' => 'capacity',
                'unit' => 'kg',
                'amount' => 25000,
            ],
            [
                'name' => 'بندیل',
                'code' => 'bundle',
                'unit' => 'kg',
                'amount' => 5000,
            ],
            [
                'name' => 'خرده فروشی ',
                'code' => 'bundle',
                'unit' => 'kg',
                'amount' => 1000,
            ]
        ];

        foreach ($orderUnits as $unit) {
            OrderUnit::updateOrCreate(
                ['code' => $unit['code']],
                $unit
            );
        }

        // اختصاص واحدهای سفارش به محصول میلگرد
        $rebarProduct = Product::where('slug', 'rebar')->first();
        $capacityUnit = OrderUnit::where('code', 'capacity')->first();
        $bundleUnit = OrderUnit::where('code', 'bundle')->first();

        if ($rebarProduct && $capacityUnit) {
            $rebarProduct->orderUnits()->syncWithoutDetaching([
                $capacityUnit->id => ['max_quantity' => 5]
            ]);
        }

        if ($rebarProduct && $bundleUnit) {
            $rebarProduct->orderUnits()->syncWithoutDetaching([
                $bundleUnit->id => ['max_quantity' => 30]
            ]);
        }

        $shippingMethods = [
            [
                'name' => 'ارسال استاندارد',
                'code' => 'standard',
            ],
            [
                'name' => 'تحویل در محل',
                'code' => 'pickup',
            ],
        ];

        foreach ($shippingMethods as $method) {
            ShippingMethod::updateOrCreate(
                ['code' => $method['code']],
                $method
            );
        }

        $filterTypes = [
            [
                'name' => 'سود',
                'code' => 'profit',
                'apply_on' => 'both', // اعمال روی هر دو قیمت عمومی و ویژه
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'سیاست قیمت‌گذاری',
                'code' => 'policy',
                'apply_on' => 'general', // فقط روی قیمت عمومی اعمال شود
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'نوع مشتری',
                'code' => 'customer_type',
                'apply_on' => 'special', // فقط روی قیمت ویژه اعمال شود
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'وضعیت مشاور',
                'code' => 'consultant_status',
                'apply_on' => 'special', // فقط روی قیمت ویژه اعمال شود
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($filterTypes as $type) {
            PriceFilterType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }

        $profitType = PriceFilterType::where('code', 'profit')->first();
        if ($profitType) {
            $profitFilters = [
                [
                    'name' => 'سود پایه',
                    'code' => 'base_profit',
                    'amount' => 1000, // 1000 تومان سود پایه
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
                [
                    'name' => 'سود ویژه',
                    'code' => 'special_profit',
                    'amount' => 2000, // 2000 تومان سود ویژه
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 2,
                ],
            ];

            foreach ($profitFilters as $filter) {
                PriceFilter::updateOrCreate(
                    [
                        'price_filter_type_id' => $profitType->id,
                        'code' => $filter['code'],
                    ],
                    array_merge(['price_filter_type_id' => $profitType->id], $filter)
                );
            }
        }

        // فیلترهای مربوط به سیاست قیمت‌گذاری
        $policyType = PriceFilterType::where('code', 'policy')->first();
        if ($policyType) {
            $policyFilters = [
                [
                    'name' => 'سیاست قیمت‌گذاری عادی',
                    'code' => 'regular_policy',
                    'amount' => 500, // 500 تومان افزایش
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
                [
                    'name' => 'سیاست قیمت‌گذاری رقابتی',
                    'code' => 'competitive_policy',
                    'amount' => -200, // 200 تومان کاهش
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 2,
                ],
            ];

            foreach ($policyFilters as $filter) {
                PriceFilter::updateOrCreate(
                    [
                        'price_filter_type_id' => $policyType->id,
                        'code' => $filter['code'],
                    ],
                    array_merge(['price_filter_type_id' => $policyType->id], $filter)
                );
            }
        }

        // فیلترهای مربوط به نوع مشتری
        $customerType = PriceFilterType::where('code', 'customer_type')->first();
        if ($customerType) {
            $customerTypeFilters = [
                [
                    'name' => 'کارفرما',
                    'code' => 'employer',
                    'amount' => 0, // بدون تخفیف (قیمت پایه)
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
                [
                    'name' => 'مهندس',
                    'code' => 'engineer',
                    'amount' => -1000, // 1000 تومان کاهش
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 2,
                ],
                [
                    'name' => 'پیمانکار',
                    'code' => 'contractor',
                    'amount' => -2000, // 2000 تومان کاهش
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 3,
                ],
            ];

            foreach ($customerTypeFilters as $filter) {
                PriceFilter::updateOrCreate(
                    [
                        'price_filter_type_id' => $customerType->id,
                        'code' => $filter['code'],
                    ],
                    array_merge(['price_filter_type_id' => $customerType->id], $filter)
                );
            }
        }

        // فیلترهای مربوط به وضعیت مشاور
        $consultantType = PriceFilterType::where('code', 'consultant_status')->first();
        if ($consultantType) {
            $consultantFilters = [
                [
                    'name' => 'بدون مشاور',
                    'code' => 'without_consultant',
                    'amount' => 0, // بدون تخفیف (قیمت پایه)
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
                [
                    'name' => 'دارای مشاور',
                    'code' => 'with_consultant',
                    'amount' => -800, // 800 تومان کاهش
                    'is_percentage' => false,
                    'is_active' => true,
                    'sort_order' => 2,
                ],
            ];

            foreach ($consultantFilters as $filter) {
                PriceFilter::updateOrCreate(
                    [
                        'price_filter_type_id' => $consultantType->id,
                        'code' => $filter['code'],
                    ],
                    array_merge(['price_filter_type_id' => $consultantType->id], $filter)
                );
            }
        }


        $categories = [
            [
                'name' => 'کارفرمای بدون مشاور',
                'code' => 'employer_without_consultant',
                'description' => 'فیلترهای قیمت برای کارفرمای بدون مشاور',
                'is_active' => true,
                'sort_order' => 1,
                'filters' => [
                    'base_profit',
                    'regular_policy',
                    'employer',
                    'without_consultant'
                ],
            ],
            [
                'name' => 'کارفرمای دارای مشاور',
                'code' => 'employer_with_consultant',
                'description' => 'فیلترهای قیمت برای کارفرمای دارای مشاور',
                'is_active' => true,
                'sort_order' => 2,
                'filters' => [
                    'base_profit',
                    'regular_policy',
                    'employer',
                    'with_consultant'
                ],
            ],
            [
                'name' => 'مهندس دارای مشاور',
                'code' => 'engineer_with_consultant',
                'description' => 'فیلترهای قیمت برای مهندس دارای مشاور',
                'is_active' => true,
                'sort_order' => 3,
                'filters' => [
                    'base_profit',
                    'competitive_policy',
                    'engineer',
                    'with_consultant'
                ],
            ],
            [
                'name' => 'پیمانکار دارای مشاور',
                'code' => 'contractor_with_consultant',
                'description' => 'فیلترهای قیمت برای پیمانکار دارای مشاور',
                'is_active' => true,
                'sort_order' => 4,
                'filters' => [
                    'special_profit',
                    'competitive_policy',
                    'contractor',
                    'with_consultant'
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            $filterCodes = $categoryData['filters'];
            unset($categoryData['filters']);

            $category = PriceFilterCategory::updateOrCreate(
                ['code' => $categoryData['code']],
                $categoryData
            );

            $filters = PriceFilter::whereIn('code', $filterCodes)->get();
            $category->filters()->sync($filters->pluck('id'));
        }
        $settings = [
            [
                'name' => 'نرخ مالیات بر ارزش افزوده',
                'code' => 'vat_rate',
                'value' => '10', // 10%
                'description' => 'نرخ مالیات بر ارزش افزوده به درصد',
            ],
            [
                'name' => 'نمایش پیش‌فرض قیمت با ارزش افزوده',
                'code' => 'show_prices_with_vat',
                'value' => 'false',
                'description' => 'آیا قیمت‌ها به صورت پیش‌فرض با احتساب ارزش افزوده نمایش داده شوند؟',
            ],
        ];

        foreach ($settings as $setting) {
            PriceSetting::updateOrCreate(
                ['code' => $setting['code']],
                $setting
            );
        }

        $kavirFactory = Factory::updateOrCreate(
            ['slug' => 'kavir-steel'],
            [
                'name' => 'فولاد کویر',
                'description' => 'تولید کننده انواع میلگرد با کیفیت',
                'logo' => 'factories/kavir-steel.png',
                'api_key' => 'kavir-api-key',
                'api_url' => 'https://api.kavirsteel.com',
                'is_active' => true,
            ]
        );

        // ایجاد کارخانه ذوب آهن
        $zobAhanFactory = Factory::updateOrCreate(
            ['slug' => 'zob-ahan'],
            [
                'name' => 'ذوب آهن',
                'description' => 'تولید کننده انواع محصولات فولادی',
                'logo' => 'factories/zob-ahan.png',
                'api_key' => 'zob-ahan-api-key',
                'api_url' => 'https://api.zobahan.com',
                'is_active' => true,
            ]
        );

        // محل‌های تحویل فولاد کویر
        $kavirLocations = [
            [
                'name' => 'درب کارخانه',
                'type' => 'factory_door',
                'city' => 'اصفهان',
                'address' => 'اصفهان، کیلومتر 35 جاده نائین',
            ],
            [
                'name' => 'انبار اصفهان',
                'type' => 'warehouse',
                'city' => 'اصفهان',
                'address' => 'اصفهان، شهرک صنعتی مبارکه',
            ],
            [
                'name' => 'انبار تهران',
                'type' => 'warehouse',
                'city' => 'تهران',
                'address' => 'تهران، شادآباد، خیابان صنعت',
            ],
            [
                'name' => 'انبار شیراز',
                'type' => 'warehouse',
                'city' => 'شیراز',
                'address' => 'شیراز، شهرک صنعتی',
            ],
        ];

        foreach ($kavirLocations as $location) {
            FactoryLocation::updateOrCreate(
                [
                    'factory_id' => $kavirFactory->id,
                    'name' => $location['name'],
                    'type' => $location['type'],
                ],
                [
                    'city' => $location['city'],
                    'address' => $location['address'],
                    'is_active' => true,
                ]
            );
        }

        // محل‌های تحویل ذوب آهن
        $zobAhanLocations = [
            [
                'name' => 'درب کارخانه',
                'type' => 'factory_door',
                'city' => 'اصفهان',
                'address' => 'اصفهان، ذوب آهن',
            ],
            [
                'name' => 'انبار مرکزی',
                'type' => 'warehouse',
                'city' => 'اصفهان',
                'address' => 'اصفهان، شهرک صنعتی',
            ],
        ];

        foreach ($zobAhanLocations as $location) {
            FactoryLocation::updateOrCreate(
                [
                    'factory_id' => $zobAhanFactory->id,
                    'name' => $location['name'],
                    'type' => $location['type'],
                ],
                [
                    'city' => $location['city'],
                    'address' => $location['address'],
                    'is_active' => true,
                ]
            );
        }

        $customerTypes = [
            [
                'name' => 'کارفرمای بدون مشاور',
                'code' => 'employer_without_consultant',
                'has_consultant' => false,
            ],
            [
                'name' => 'کارفرمای دارای مشاور',
                'code' => 'employer_with_consultant',
                'has_consultant' => true,
            ],
            [
                'name' => 'مهندس دارای مشاور',
                'code' => 'engineer_with_consultant',
                'has_consultant' => true,
            ],
            [
                'name' => 'پیمانکار دارای مشاور',
                'code' => 'contractor_with_consultant',
                'has_consultant' => true,
            ],
        ];

        foreach ($customerTypes as $type) {
            CustomerType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }

        $badges = [
            [
                'name' => 'برنزی',
                'code' => 'bronze',
                'icon' => 'badges/bronze.svg',
            ],
            [
                'name' => 'نقره‌ای',
                'code' => 'silver',
                'icon' => 'badges/silver.svg',
            ],
            [
                'name' => 'طلایی',
                'code' => 'gold',
                'icon' => 'badges/gold.svg',
            ],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(
                ['code' => $badge['code']],
                $badge
            );
        }

        $employerWithoutConsultant = CustomerType::where('code', 'employer_without_consultant')->first();
        if ($employerWithoutConsultant) {
            PriceAdjustment::updateOrCreate(
                [
                    'customer_type_id' => $employerWithoutConsultant->id,
                    'badge_id' => null,
                ],
                [
                    'adjustment_amount' => 0, // بدون تخفیف
                    'is_active' => true,
                ]
            );
        }

        // کارفرمای دارای مشاور (1000 تومان تخفیف)
        $employerWithConsultant = CustomerType::where('code', 'employer_with_consultant')->first();
        if ($employerWithConsultant) {
            PriceAdjustment::updateOrCreate(
                [
                    'customer_type_id' => $employerWithConsultant->id,
                    'badge_id' => null,
                ],
                [
                    'adjustment_amount' => 1000,
                    'is_active' => true,
                ]
            );
        }

        // مهندس دارای مشاور (2000 تومان تخفیف)
        $engineerWithConsultant = CustomerType::where('code', 'engineer_with_consultant')->first();
        if ($engineerWithConsultant) {
            PriceAdjustment::updateOrCreate(
                [
                    'customer_type_id' => $engineerWithConsultant->id,
                    'badge_id' => null,
                ],
                [
                    'adjustment_amount' => 2000,
                    'is_active' => true,
                ]
            );
        }

        // پیمانکار دارای مشاور (3000 تومان تخفیف)
        $contractorWithConsultant = CustomerType::where('code', 'contractor_with_consultant')->first();
        if ($contractorWithConsultant) {
            PriceAdjustment::updateOrCreate(
                [
                    'customer_type_id' => $contractorWithConsultant->id,
                    'badge_id' => null,
                ],
                [
                    'adjustment_amount' => 3000,
                    'is_active' => true,
                ]
            );
        }

        // تنظیم قیمت برای نشان‌ها (برای همه انواع مشتری)
        $bronzeBadge = Badge::where('code', 'bronze')->first();
        $silverBadge = Badge::where('code', 'silver')->first();
        $goldBadge = Badge::where('code', 'gold')->first();

        $customerTypes = CustomerType::all();

        foreach ($customerTypes as $customerType) {
            $baseAdjustment = PriceAdjustment::where('customer_type_id', $customerType->id)
                ->whereNull('badge_id')
                ->value('adjustment_amount') ?? 0;

            // نشان برنزی (500 تومان تخفیف بیشتر)
            if ($bronzeBadge) {
                PriceAdjustment::updateOrCreate(
                    [
                        'customer_type_id' => $customerType->id,
                        'badge_id' => $bronzeBadge->id,
                    ],
                    [
                        'adjustment_amount' => $baseAdjustment + 500,
                        'is_active' => true,
                    ]
                );
            }

            // نشان نقره‌ای (1000 تومان تخفیف بیشتر)
            if ($silverBadge) {
                PriceAdjustment::updateOrCreate(
                    [
                        'customer_type_id' => $customerType->id,
                        'badge_id' => $silverBadge->id,
                    ],
                    [
                        'adjustment_amount' => $baseAdjustment + 1000,
                        'is_active' => true,
                    ]
                );
            }

            // نشان طلایی (1500 تومان تخفیف بیشتر)
            if ($goldBadge) {
                PriceAdjustment::updateOrCreate(
                    [
                        'customer_type_id' => $customerType->id,
                        'badge_id' => $goldBadge->id,
                    ],
                    [
                        'adjustment_amount' => $baseAdjustment + 1500,
                        'is_active' => true,
                    ]
                );
            }
        }

        $offers = [
            [
                'title' => 'تخفیف ویژه میلگرد',
                'description' => '10% تخفیف برای خرید بیش از 1 محصول میلگرد',
                'code' => 'REBAR10',
                'type' => 'percentage',
                'value' => 10,
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'minimum_quantity' => 1,
                'is_active' => true,
            ],
            [
                'title' => 'ارسال رایگان میلگرد',
                'description' => 'ارسال رایگان برای خرید بیش از 3 ظرفیت میلگرد',
                'code' => 'FREESHIP',
                'type' => 'free_shipping',
                'value' => null,
                'start_date' => now(),
                'end_date' => now()->addDays(15),
                'minimum_quantity' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($offers as $offerData) {
            $offer = Offer::updateOrCreate(
                ['code' => $offerData['code']],
                $offerData
            );

            // اختصاص پیشنهاد به محصول میلگرد
            $rebarProduct = Product::where('slug', 'rebar')->first();

            if ($rebarProduct) {
                $rebarProduct->offers()->syncWithoutDetaching([
                    $offer->id => ['associated_at' => now()]
                ]);
            }
        }

        $rebarProduct = Product::where('slug', 'rebar')->first();

        if (!$rebarProduct) {
            $this->command->error('محصول میلگرد یافت نشد!');
            return;
        }

        // دریافت ویژگی‌های مورد نیاز
        $shakheh = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'type');
        })->where('name', 'شاخه')->first();

        $kalaf = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'type');
        })->where('name', 'کلاف')->first();

        $gradeA1 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'grade');
        })->where('name', 'A1')->first();

        $gradeA2 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'grade');
        })->where('name', 'A2')->first();

        $gradeA3 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'grade');
        })->where('name', 'A3')->first();

        $size8 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'size');
        })->where('name', '8')->first();

        $size12 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'size');
        })->where('name', '12')->first();

        $size16 = Attribute::whereHas('attributeType', function ($q) {
            $q->where('name', 'size');
        })->where('name', '16')->first();

        // ایجاد 3 واریانت میلگرد
        $variantsData = [
            [
                'sku' => 'RBR-A2-12-SH',
                'name' => 'میلگرد شاخه A2 سایز 12',
                'type' => $shakheh,
                'grade' => $gradeA2,
                'size' => $size12,
                'stock' => 2000
            ],
            [
                'sku' => 'RBR-A3-16-SH',
                'name' => 'میلگرد شاخه A3 سایز 16',
                'type' => $shakheh,
                'grade' => $gradeA3,
                'size' => $size16,
                'stock' => 1500
            ],
            [
                'sku' => 'RBR-A1-8-KL',
                'name' => 'میلگرد کلاف A1 سایز 8',
                'type' => $kalaf,
                'grade' => $gradeA1,
                'size' => $size8,
                'stock' => 3000
            ]
        ];

        foreach ($variantsData as $data) {
            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $rebarProduct->id,
                    'sku' => $data['sku']
                ],
                [
                    'name' => $data['name'],
                    'is_active' => true,
                    'stock_quantity' => $data['stock']
                ]
            );

            if ($data['type']) {
                $variant
                    ->variantAttributes()
                    ->syncWithoutDetaching([$data['type']->id]);
            }

            if ($data['grade']) {
                $variant
                    ->variantAttributes()
                    ->syncWithoutDetaching([$data['grade']->id]);
            }

            if ($data['size']) {
                $variant
                    ->variantAttributes()
                    ->syncWithoutDetaching([$data['size']->id]);
            }
        }

        $kavirFactory = Factory::where('slug', 'kavir-steel')->first();

        if (!$kavirFactory) {
            $this->command->error('کارخانه فولاد کویر یافت نشد!');
            return;
        }

        // دریافت محل‌های تحویل فولاد کویر
        $factoryDoor = FactoryLocation::where('factory_id', $kavirFactory->id)
            ->where('type', 'factory_door')
            ->first();

        $isfahanWarehouse = FactoryLocation::where('factory_id', $kavirFactory->id)
            ->where('type', 'warehouse')
            ->where('city', 'اصفهان')
            ->first();

        $tehranWarehouse = FactoryLocation::where('factory_id', $kavirFactory->id)
            ->where('type', 'warehouse')
            ->where('city', 'تهران')
            ->first();

        $shirazWarehouse = FactoryLocation::where('factory_id', $kavirFactory->id)
            ->where('type', 'warehouse')
            ->where('city', 'شیراز')
            ->first();

        if (!$factoryDoor || !$isfahanWarehouse || !$tehranWarehouse || !$shirazWarehouse) {
            $this->command->error('یکی از محل‌های تحویل فولاد کویر یافت نشد!');
            return;
        }

        // دریافت 3 واریانت میلگرد ایجاد شده
        $variantA2_12 = ProductVariant::where('sku', 'RBR-A2-12-SH')->first();
        $variantA3_16 = ProductVariant::where('sku', 'RBR-A3-16-SH')->first();
        $variantA1_8 = ProductVariant::where('sku', 'RBR-A1-8-KL')->first();

        if (!$variantA2_12 || !$variantA3_16 || !$variantA1_8) {
            $this->command->error('یکی از واریانت‌های میلگرد یافت نشد!');
            return;
        }

        // قیمت‌های پایه واریانت‌ها
        $variantPrices = [
            $variantA2_12->id => [
                'base_general' => 46500, // قیمت پایه عمومی
                'base_special' => 42500, // قیمت پایه ویژه
            ],
            $variantA3_16->id => [
                'base_general' => 54500,
                'base_special' => 50500,
            ],
            $variantA1_8->id => [
                'base_general' => 40500,
                'base_special' => 36500,
            ]
        ];

        // تنظیمات قیمت برای محل‌های مختلف تحویل
        $locationAdjustments = [
            $factoryDoor->id => [
                'general' => -500, // درب کارخانه 500 تومان ارزان‌تر
                'special' => -500,
            ],
            $isfahanWarehouse->id => [
                'general' => 0, // انبار اصفهان قیمت پایه
                'special' => 0,
            ],
            $tehranWarehouse->id => [
                'general' => 1000, // انبار تهران 1000 تومان گران‌تر
                'special' => 800,
            ],
            $shirazWarehouse->id => [
                'general' => 1500, // انبار شیراز 1500 تومان گران‌تر
                'special' => 1200,
            ]
        ];

        // ایجاد قیمت‌ها برای هر واریانت در هر محل تحویل
        foreach ($variantPrices as $variantId => $prices) {
            foreach ($locationAdjustments as $locationId => $adjustments) {
                $generalPrice = $prices['base_general'] + $adjustments['general'];
                $specialPrice = $prices['base_special'] + $adjustments['special'];
                $goldenFigure = $generalPrice - $specialPrice;

                ProductPrice::updateOrCreate(
                    [
                        'product_variant_id' => $variantId,
                        'factory_id' => $kavirFactory->id,
                        'factory_location_id' => $locationId,
                    ],
                    [
                        'general_price' => $generalPrice,
                        'special_price' => $specialPrice,
                        'golden_figure' => $goldenFigure,
                        'last_updated_at' => now(),
                    ]
                );

                $variant = ProductVariant::find($variantId);
                $location = FactoryLocation::find($locationId);

            }
        }
    }
}
