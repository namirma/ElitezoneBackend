<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\AttributeType;
use App\Models\Badge;
use App\Models\CategoryAttribute;
use App\Models\CustomerType;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\Offer;
use App\Models\OrderUnit;
use App\Models\PriceAdjustment;
use App\Models\PriceFilter;
use App\Models\PriceFilterCategory;
use App\Models\PriceFilterType;
use App\Models\PriceSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesData = [
            [ProductCategory::NAME => 'میلگرد', ProductCategory::SLUG => 'rebar', ProductCategory::DESCRIPTION => 'انواع میلگرد ساختمانی', ProductCategory::IMAGE => 'categories/rebar.jpg', ProductCategory::IS_ACTIVE => true],
            [ProductCategory::NAME => 'آهن آلات', ProductCategory::SLUG => 'steel', ProductCategory::DESCRIPTION => 'انواع آهن آلات ساختمانی', ProductCategory::IMAGE => 'categories/steel.jpg', ProductCategory::IS_ACTIVE => true],
            [ProductCategory::NAME => 'آجر', ProductCategory::SLUG => 'brick', ProductCategory::DESCRIPTION => 'انواع آجر ساختمانی', ProductCategory::IMAGE => 'categories/brick.jpg', ProductCategory::IS_ACTIVE => true],
            [ProductCategory::NAME => 'شیرآلات', ProductCategory::SLUG => 'taps', ProductCategory::DESCRIPTION => 'انواع شیرآلات ساختمانی', ProductCategory::IMAGE => 'categories/taps.jpg', ProductCategory::IS_ACTIVE => true],
        ];
        foreach ($categoriesData as $categoryDetails) {
            ProductCategory::updateOrCreate([ProductCategory::SLUG => $categoryDetails[ProductCategory::SLUG]], $categoryDetails);
        }

        $attributeTypesData = [
            [AttributeType::NAME => 'type', AttributeType::DISPLAY_NAME => 'نوع', AttributeType::INPUT_TYPE => 'select'],
            [AttributeType::NAME => 'grade', AttributeType::DISPLAY_NAME => 'گرید', AttributeType::INPUT_TYPE => 'select'],
            [AttributeType::NAME => 'size', AttributeType::DISPLAY_NAME => 'سایز', AttributeType::INPUT_TYPE => 'select'],
        ];
        foreach ($attributeTypesData as $typeDetails) {
            AttributeType::updateOrCreate([AttributeType::NAME => $typeDetails[AttributeType::NAME]], $typeDetails);
        }

        $rebarCategory = ProductCategory::where(ProductCategory::SLUG, 'rebar')->first();
        $typeAttributeType = AttributeType::where(AttributeType::NAME, 'type')->first();
        $gradeAttributeType = AttributeType::where(AttributeType::NAME, 'grade')->first();
        $sizeAttributeType = AttributeType::where(AttributeType::NAME, 'size')->first();

        if ($rebarCategory && $typeAttributeType) {
            CategoryAttribute::updateOrCreate(
                [CategoryAttribute::CATEGORY_ID => $rebarCategory->id, CategoryAttribute::ATTRIBUTE_TYPE_ID => $typeAttributeType->id],
                [CategoryAttribute::IS_REQUIRED => true, CategoryAttribute::IS_FILTERABLE => true, CategoryAttribute::SORT_ORDER => 1]
            );
        }
        if ($rebarCategory && $gradeAttributeType) {
            CategoryAttribute::updateOrCreate(
                [CategoryAttribute::CATEGORY_ID => $rebarCategory->id, CategoryAttribute::ATTRIBUTE_TYPE_ID => $gradeAttributeType->id],
                [CategoryAttribute::IS_REQUIRED => true, CategoryAttribute::IS_FILTERABLE => true, CategoryAttribute::SORT_ORDER => 2]
            );
        }
        if ($rebarCategory && $sizeAttributeType) {
            CategoryAttribute::updateOrCreate(
                [CategoryAttribute::CATEGORY_ID => $rebarCategory->id, CategoryAttribute::ATTRIBUTE_TYPE_ID => $sizeAttributeType->id],
                [CategoryAttribute::IS_REQUIRED => true, CategoryAttribute::IS_FILTERABLE => true, CategoryAttribute::SORT_ORDER => 3]
            );
        }

        if ($typeAttributeType) {
            $typeAttributesData = [
                ['name' => 'شاخه', 'display_name' => 'شاخه'],
                ['name' => 'کلاف', 'display_name' => 'کلاف'],
                ['name' => 'صنعتی', 'display_name' => 'صنعتی'],
            ];
            foreach ($typeAttributesData as $attrData) {
                Attribute::updateOrCreate([Attribute::ATTRIBUTE_TYPE_ID => $typeAttributeType->id, Attribute::NAME => $attrData['name']], $attrData);
            }
        }
        if ($gradeAttributeType) {
            $gradeAttributesData = [
                ['name' => 'A1', 'display_name' => 'A1'],
                ['name' => 'A2', 'display_name' => 'A2'],
                ['name' => 'A3', 'display_name' => 'A3'],
                ['name' => 'A4', 'display_name' => 'A4'],
            ];
            foreach ($gradeAttributesData as $attrData) {
                Attribute::updateOrCreate([Attribute::ATTRIBUTE_TYPE_ID => $gradeAttributeType->id, Attribute::NAME => $attrData['name']], $attrData);
            }
        }
        if ($sizeAttributeType) {
            $sizeAttributesData = [
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
            foreach ($sizeAttributesData as $attrData) {
                Attribute::updateOrCreate([Attribute::ATTRIBUTE_TYPE_ID => $sizeAttributeType->id, Attribute::NAME => $attrData['name']], $attrData);
            }
        }

        $orderUnitsData = [
            [OrderUnit::NAME => 'ظرفیت', OrderUnit::CODE => 'capacity', OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 25000],
            [OrderUnit::NAME => 'بندیل', OrderUnit::CODE => 'bundle', OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 5000],
            [OrderUnit::NAME => 'خرده فروشی', OrderUnit::CODE => 'retail', OrderUnit::UNIT => 'kg', OrderUnit::AMOUNT => 1],
        ];
        foreach ($orderUnitsData as $unitDetails) {
            OrderUnit::updateOrCreate([OrderUnit::CODE => $unitDetails[OrderUnit::CODE]], $unitDetails);
        }

        $kgUnit = OrderUnit::where(OrderUnit::CODE, 'retail')->first();

        if (! $rebarCategory || ! $kgUnit) {
            $this->command?->warn('ProductSeeder: rebar category or retail OrderUnit missing — stopping before creating any product/price data.');

            return;
        }

        $rebarProduct = Product::updateOrCreate(
            [Product::SLUG => 'rebar'],
            [
                Product::CATEGORY_ID => $rebarCategory->id,
                Product::NAME => 'میلگرد آجدار',
                Product::DESCRIPTION => 'میلگرد آجدار با کیفیت عالی مناسب برای ساخت و ساز. انواع میلگرد در سایزها و گریدهای مختلف.',
                Product::SKU => 'RBR-001',
                Product::IS_ACTIVE => true,
                Product::IS_FEATURED => true,
                Product::VIEW_COUNT => 0,
                Product::ITEMS_PER_PAGE => 12,
                Product::AGGREGATE_MIN_ORDER_VALUE => null,
                Product::AGGREGATE_MAX_ORDER_VALUE => 5000000,
                Product::AGGREGATE_LIMIT_ORDER_UNIT_ID => $kgUnit->id,
            ]
        );

        $productImagesData = [
            [ProductImage::IMAGE_PATH => 'products/rebar/main.jpg', ProductImage::IS_PRIMARY => true, ProductImage::SORT_ORDER => 0],
            [ProductImage::IMAGE_PATH => 'products/rebar/side.jpg', ProductImage::IS_PRIMARY => false, ProductImage::SORT_ORDER => 1],
            [ProductImage::IMAGE_PATH => 'products/rebar/detail.jpg', ProductImage::IS_PRIMARY => false, ProductImage::SORT_ORDER => 2],
        ];
        if ($rebarProduct) {
            foreach ($productImagesData as $imageData) {
                ProductImage::updateOrCreate([ProductImage::PRODUCT_ID => $rebarProduct->id, ProductImage::IMAGE_PATH => $imageData[ProductImage::IMAGE_PATH]], $imageData);
            }
            $capacityUnit = OrderUnit::where(OrderUnit::CODE, 'capacity')->first();
            $bundleUnit = OrderUnit::where(OrderUnit::CODE, 'bundle')->first();
            if ($capacityUnit) {
                $rebarProduct->orderUnits()->syncWithoutDetaching([$capacityUnit->id => ['max_quantity' => 5]]);
            }
            if ($bundleUnit) {
                $rebarProduct->orderUnits()->syncWithoutDetaching([$bundleUnit->id => ['max_quantity' => 30]]);
            }
            if ($kgUnit) {
                $rebarProduct->orderUnits()->syncWithoutDetaching([$kgUnit->id => ['max_quantity' => 2000000]]);
            }

            $shakhehAttr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $typeAttributeType?->id)->where(Attribute::NAME, 'شاخه')->first();
            $kalafAttr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $typeAttributeType?->id)->where(Attribute::NAME, 'کلاف')->first();
            $gradeA1Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $gradeAttributeType?->id)->where(Attribute::NAME, 'A1')->first();
            $gradeA2Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $gradeAttributeType?->id)->where(Attribute::NAME, 'A2')->first();
            $gradeA3Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $gradeAttributeType?->id)->where(Attribute::NAME, 'A3')->first();
            $size8Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $sizeAttributeType?->id)->where(Attribute::NAME, '8')->first();
            $size12Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $sizeAttributeType?->id)->where(Attribute::NAME, '12')->first();
            $size16Attr = Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $sizeAttributeType?->id)->where(Attribute::NAME, '16')->first();

            $variantsSeedData = [
                [ProductVariant::SKU => 'RBR-A2-12-SH', ProductVariant::NAME => 'میلگرد شاخه A2 سایز 12', 'attrs_ids' => [$shakhehAttr?->id, $gradeA2Attr?->id, $size12Attr?->id], ProductVariant::STOCK_QUANTITY => 200000, ProductVariant::MIN_ORDER_VALUE => 2000, ProductVariant::MAX_ORDER_VALUE => 100000, ProductVariant::DISCOUNT_VALUE => 10.00, ProductVariant::DISCOUNT_TYPE => 'percentage', ProductVariant::DISCOUNT_START_DATE => Carbon::now(), ProductVariant::DISCOUNT_END_DATE => Carbon::now()->addMonth()],
                [ProductVariant::SKU => 'RBR-A3-16-SH', ProductVariant::NAME => 'میلگرد شاخه A3 سایز 16', 'attrs_ids' => [$shakhehAttr?->id, $gradeA3Attr?->id, $size16Attr?->id], ProductVariant::STOCK_QUANTITY => 150000, ProductVariant::MIN_ORDER_VALUE => 2000, ProductVariant::MAX_ORDER_VALUE => 100000, ProductVariant::DISCOUNT_VALUE => 10.00, ProductVariant::DISCOUNT_TYPE => 'percentage', ProductVariant::DISCOUNT_START_DATE => Carbon::now(), ProductVariant::DISCOUNT_END_DATE => Carbon::now()->addDays(15)],
                [ProductVariant::SKU => 'RBR-A1-8-KL', ProductVariant::NAME => 'میلگرد کلاف A1 سایز 8', 'attrs_ids' => [$kalafAttr?->id, $gradeA1Attr?->id, $size8Attr?->id], ProductVariant::STOCK_QUANTITY => 300000, ProductVariant::MIN_ORDER_VALUE => 2000, ProductVariant::MAX_ORDER_VALUE => 2000000, ProductVariant::DISCOUNT_VALUE => null, ProductVariant::DISCOUNT_TYPE => null, ProductVariant::DISCOUNT_START_DATE => null, ProductVariant::DISCOUNT_END_DATE => null],
            ];
            foreach ($variantsSeedData as $data) {
                // Lookup key must be `sku` ALONE — product_variants.sku has a global unique
                // index (not composite with product_id). Including product_id here made
                // updateOrCreate attempt an INSERT (not an UPDATE) whenever a row with this
                // sku already existed under a different product_id, crashing with a
                // duplicate-key SQL error instead of re-linking/updating the existing row.
                $variant = ProductVariant::updateOrCreate(
                    [ProductVariant::SKU => $data[ProductVariant::SKU]],
                    [
                        ProductVariant::PRODUCT_ID => $rebarProduct->id,
                        ProductVariant::NAME => $data[ProductVariant::NAME],
                        ProductVariant::IS_ACTIVE => true,
                        ProductVariant::STOCK_QUANTITY => $data[ProductVariant::STOCK_QUANTITY],
                        ProductVariant::MIN_ORDER_VALUE => $data[ProductVariant::MIN_ORDER_VALUE],
                        ProductVariant::MAX_ORDER_VALUE => $data[ProductVariant::MAX_ORDER_VALUE],
                        ProductVariant::LIMIT_ORDER_UNIT_ID => $kgUnit->id,
                        ProductVariant::DISCOUNT_VALUE => $data[ProductVariant::DISCOUNT_VALUE],
                        ProductVariant::DISCOUNT_TYPE => $data[ProductVariant::DISCOUNT_TYPE],
                        ProductVariant::DISCOUNT_START_DATE => $data[ProductVariant::DISCOUNT_START_DATE],
                        ProductVariant::DISCOUNT_END_DATE => $data[ProductVariant::DISCOUNT_END_DATE],
                    ]
                );
                // Build pivot data with attribute_value_id (required by new schema)
                $syncData = [];
                foreach (array_filter($data['attrs_ids']) as $attrId) {
                    $attr = Attribute::find($attrId);
                    if ($attr) {
                        $av = AttributeValue::firstOrCreate(
                            ['attribute_id' => $attrId, 'value' => $attr->name],
                            ['display_value' => $attr->display_name ?? $attr->name]
                        );
                        $syncData[$attrId] = ['attribute_value_id' => $av->id];
                    }
                }
                $variant->variantAttributes()->sync($syncData);
            }
        }

        $shippingMethodsData = [
            [ShippingMethod::NAME => 'ارسال استاندارد', ShippingMethod::CODE => 'standard'],
            [ShippingMethod::NAME => 'تحویل در محل', ShippingMethod::CODE => 'pickup'],
        ];
        foreach ($shippingMethodsData as $methodDetails) {
            ShippingMethod::updateOrCreate([ShippingMethod::CODE => $methodDetails[ShippingMethod::CODE]], $methodDetails);
        }

        $kavirFactory = Factory::updateOrCreate(
            [Factory::SLUG => 'kavir-steel'],
            [Factory::NAME => 'فولاد کویر', Factory::DESCRIPTION => 'تولید کننده انواع میلگرد با کیفیت', Factory::LOGO => 'factories/kavir-steel.png', Factory::API_KEY => 'kavir-api-key', Factory::API_URL => 'https://api.kavirsteel.com', Factory::IS_ACTIVE => true]
        );
        $zobAhanFactory = Factory::updateOrCreate(
            [Factory::SLUG => 'zob-ahan'],
            [Factory::NAME => 'ذوب آهن', Factory::DESCRIPTION => 'تولید کننده انواع محصولات فولادی', Factory::LOGO => 'factories/zob-ahan.png', Factory::API_KEY => 'zob-ahan-api-key', Factory::API_URL => 'https://api.zobahan.com', Factory::IS_ACTIVE => true]
        );

        $kavirLocationsData = [
            ['name' => 'درب کارخانه', 'type' => 'factory_door', 'city' => 'اصفهان', 'address' => 'اصفهان، کیلومتر 35 جاده نائین'],
            ['name' => 'انبار اصفهان', 'type' => 'warehouse', 'city' => 'اصفهان', 'address' => 'اصفهان، شهرک صنعتی مبارکه'],
            ['name' => 'انبار تهران', 'type' => 'warehouse', 'city' => 'تهران', 'address' => 'تهران، شادآباد، خیابان صنعت'],
            ['name' => 'انبار شیراز', 'type' => 'warehouse', 'city' => 'شیراز', 'address' => 'شیراز، شهرک صنعتی'],
        ];
        if ($kavirFactory) {
            foreach ($kavirLocationsData as $locDetails) {
                FactoryLocation::updateOrCreate(
                    [FactoryLocation::FACTORY_ID => $kavirFactory->id, FactoryLocation::NAME => $locDetails['name'], FactoryLocation::TYPE => $locDetails['type']],
                    $locDetails
                );
            }
        }

        $zobAhanLocationsData = [
            ['name' => 'درب کارخانه', 'type' => 'factory_door', 'city' => 'اصفهان', 'address' => 'اصفهان، ذوب آهن'],
            ['name' => 'انبار مرکزی', 'type' => 'warehouse', 'city' => 'اصفهان', 'address' => 'اصفهان، شهرک صنعتی'],
        ];
        if ($zobAhanFactory) {
            foreach ($zobAhanLocationsData as $locDetails) {
                FactoryLocation::updateOrCreate(
                    [FactoryLocation::FACTORY_ID => $zobAhanFactory->id, FactoryLocation::NAME => $locDetails['name'], FactoryLocation::TYPE => $locDetails['type']],
                    $locDetails
                );
            }
        }

        $customerTypesData = [
            [CustomerType::NAME => 'کارفرمای بدون مشاور', CustomerType::CODE => 'employer_without_consultant', CustomerType::HAS_CONSULTANT => false],
            [CustomerType::NAME => 'مهندس دارای مشاور', CustomerType::CODE => 'engineer_with_consultant', CustomerType::HAS_CONSULTANT => true],
        ];
        foreach ($customerTypesData as $typeDetails) {
            CustomerType::updateOrCreate([CustomerType::CODE => $typeDetails[CustomerType::CODE]], $typeDetails);
        }

        // Badges (incl. their artwork) are seeded by BadgeSeeder, which runs first
        // in DatabaseSeeder. Do not recreate them here — single source of truth.

        $customerTypeInstances = CustomerType::all()->keyBy(CustomerType::CODE);
        $badgeInstances = Badge::all()->keyBy(Badge::CODE);

        $priceAdjustmentsData = [
            ['c_code' => 'employer_without_consultant', 'b_code' => null, 'amount' => 0],
            ['c_code' => 'engineer_with_consultant', 'b_code' => null, 'amount' => 2000],
        ];
        foreach ($priceAdjustmentsData as $adj) {
            if (isset($customerTypeInstances[$adj['c_code']])) {
                PriceAdjustment::updateOrCreate(
                    [PriceAdjustment::CUSTOMER_TYPE_ID => $customerTypeInstances[$adj['c_code']]->id, PriceAdjustment::BADGE_ID => null],
                    [PriceAdjustment::ADJUSTMENT_AMOUNT => $adj['amount'], PriceAdjustment::IS_ACTIVE => true]
                );
            }
        }

        $badgeAdjustments = [
            ['b_code' => 'bronze', 'add' => 500],
            ['b_code' => 'silver', 'add' => 1000],
            ['b_code' => 'gold',  'add' => 1500],
        ];
        foreach ($customerTypeInstances as $ctInstance) {
            $baseAdj = PriceAdjustment::where(PriceAdjustment::CUSTOMER_TYPE_ID, $ctInstance->id)->whereNull(PriceAdjustment::BADGE_ID)->value(PriceAdjustment::ADJUSTMENT_AMOUNT) ?? 0;
            foreach ($badgeAdjustments as $bAdj) {
                if (isset($badgeInstances[$bAdj['b_code']])) {
                    PriceAdjustment::updateOrCreate(
                        [PriceAdjustment::CUSTOMER_TYPE_ID => $ctInstance->id, PriceAdjustment::BADGE_ID => $badgeInstances[$bAdj['b_code']]->id],
                        [PriceAdjustment::ADJUSTMENT_AMOUNT => $baseAdj + $bAdj['add'], PriceAdjustment::IS_ACTIVE => true]
                    );
                }
            }
        }

        $filterTypesData = [
            [PriceFilterType::NAME => 'سود', PriceFilterType::CODE => 'profit',             PriceFilterType::APPLY_ON => 'both',    PriceFilterType::IS_ACTIVE => true, PriceFilterType::SORT_ORDER => 1],
            [PriceFilterType::NAME => 'سیاست قیمت‌گذاری', PriceFilterType::CODE => 'policy',            PriceFilterType::APPLY_ON => 'general', PriceFilterType::IS_ACTIVE => true, PriceFilterType::SORT_ORDER => 2],
            [PriceFilterType::NAME => 'نوع مشتری',       PriceFilterType::CODE => 'customer_type',      PriceFilterType::APPLY_ON => 'special', PriceFilterType::IS_ACTIVE => true, PriceFilterType::SORT_ORDER => 3],
            [PriceFilterType::NAME => 'وضعیت مشاور',     PriceFilterType::CODE => 'consultant_status', PriceFilterType::APPLY_ON => 'special', PriceFilterType::IS_ACTIVE => true, PriceFilterType::SORT_ORDER => 4],
        ];
        foreach ($filterTypesData as $typeDetails) {
            PriceFilterType::updateOrCreate([PriceFilterType::CODE => $typeDetails[PriceFilterType::CODE]], $typeDetails);
        }

        $priceFilterTypeInstances = PriceFilterType::all()->keyBy(PriceFilterType::CODE);

        $priceFiltersData = [
            'profit' => [
                [PriceFilter::NAME => 'سود پایه',      PriceFilter::CODE => 'base_profit',      PriceFilter::AMOUNT => 1000,  PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 1],
                [PriceFilter::NAME => 'سود ویژه',      PriceFilter::CODE => 'special_profit',    PriceFilter::AMOUNT => 2000,  PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 2],
            ],
            'policy' => [
                [PriceFilter::NAME => 'سیاست عادی',    PriceFilter::CODE => 'regular_policy',    PriceFilter::AMOUNT => 500,   PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 1],
                [PriceFilter::NAME => 'سیاست رقابتی',  PriceFilter::CODE => 'competitive_policy', PriceFilter::AMOUNT => -200,  PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 2],
            ],
            'customer_type' => [
                [PriceFilter::NAME => 'کارفرما',       PriceFilter::CODE => 'employer',          PriceFilter::AMOUNT => 0,     PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 1],
                [PriceFilter::NAME => 'مهندس',         PriceFilter::CODE => 'engineer',          PriceFilter::AMOUNT => -1000, PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 2],
            ],
            'consultant_status' => [
                [PriceFilter::NAME => 'بدون مشاور',    PriceFilter::CODE => 'without_consultant', PriceFilter::AMOUNT => 0,     PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 1],
                [PriceFilter::NAME => 'دارای مشاور',   PriceFilter::CODE => 'with_consultant',   PriceFilter::AMOUNT => -800,  PriceFilter::IS_PERCENTAGE => false, PriceFilter::IS_ACTIVE => true, PriceFilter::SORT_ORDER => 2],
            ],
        ];

        foreach ($priceFiltersData as $typeCode => $filters) {
            if (isset($priceFilterTypeInstances[$typeCode])) {
                foreach ($filters as $filterDetails) {
                    PriceFilter::updateOrCreate(
                        [PriceFilter::PRICE_FILTER_TYPE_ID => $priceFilterTypeInstances[$typeCode]->id, PriceFilter::CODE => $filterDetails[PriceFilter::CODE]],
                        array_merge([PriceFilter::PRICE_FILTER_TYPE_ID => $priceFilterTypeInstances[$typeCode]->id], $filterDetails)
                    );
                }
            }
        }

        $priceFilterCategoriesData = [
            [
                PriceFilterCategory::NAME => 'کارفرمای بدون مشاور',
                PriceFilterCategory::CODE => 'employer_without_consultant',
                PriceFilterCategory::DESCRIPTION => 'فیلترهای قیمت برای کارفرمای بدون مشاور',
                PriceFilterCategory::IS_ACTIVE => true,
                PriceFilterCategory::SORT_ORDER => 1,
                'filters' => ['base_profit', 'regular_policy', 'employer', 'without_consultant'],
            ],
            [
                PriceFilterCategory::NAME => 'مهندس دارای مشاور',
                PriceFilterCategory::CODE => 'engineer_with_consultant',
                PriceFilterCategory::DESCRIPTION => 'فیلترهای قیمت برای مهندس دارای مشاور',
                PriceFilterCategory::IS_ACTIVE => true,
                PriceFilterCategory::SORT_ORDER => 2,
                'filters' => ['base_profit', 'competitive_policy', 'engineer', 'with_consultant'],
            ],
        ];

        foreach ($priceFilterCategoriesData as $categoryDetails) {
            $filterCodes = $categoryDetails['filters'];
            unset($categoryDetails['filters']);

            $category = PriceFilterCategory::updateOrCreate(
                [PriceFilterCategory::CODE => $categoryDetails[PriceFilterCategory::CODE]],
                $categoryDetails
            );

            $filtersToSync = PriceFilter::whereIn(PriceFilter::CODE, $filterCodes)->pluck(PriceFilter::ID);
            $category->filters()->sync($filtersToSync);
        }

        PriceFilterCategory::whereNotIn(PriceFilterCategory::CODE, ['engineer_with_consultant', 'employer_without_consultant'])
            ->update([PriceFilterCategory::IS_ACTIVE => false]);

        $settingsData = [
            [PriceSetting::NAME => 'نرخ مالیات بر ارزش افزوده', PriceSetting::CODE => PriceSetting::CODE_VAT_RATE, PriceSetting::VALUE => '10',   PriceSetting::DESCRIPTION => 'نرخ مالیات بر ارزش افزوده به درصد'],
            [PriceSetting::NAME => 'نمایش پیش‌فرض قیمت با ارزش افزوده', PriceSetting::CODE => PriceSetting::CODE_SHOW_PRICES_WITH_VAT, PriceSetting::VALUE => 'false', PriceSetting::DESCRIPTION => 'آیا قیمت‌ها به صورت پیش‌فرض با احتساب ارزش افزوده نمایش داده شوند؟'],
        ];
        foreach ($settingsData as $settingDetails) {
            PriceSetting::updateOrCreate([PriceSetting::CODE => $settingDetails[PriceSetting::CODE]], $settingDetails);
        }

        $offerData = [
            Offer::TITLE => 'ارسال رایگان میلگرد',
            Offer::DESCRIPTION => 'ارسال رایگان برای خرید بیش از 3 ظرفیت میلگرد',
            Offer::CODE => 'FREESHIP',
            Offer::TYPE => 'free_shipping',
            Offer::VALUE => null,
            Offer::START_DATE => Carbon::now(),
            Offer::END_DATE => Carbon::now()->addDays(15),
            Offer::MINIMUM_QUANTITY => 3,
            Offer::IS_ACTIVE => true,
        ];
        $offer = Offer::updateOrCreate([Offer::CODE => $offerData[Offer::CODE]], $offerData);
        if ($rebarProduct && $offer) {
            $rebarProduct->offers()->syncWithoutDetaching([$offer->id => ['associated_at' => now()]]);
        }

        if (! $kavirFactory) {
            $this->command?->warn('ProductSeeder: kavir-steel Factory missing — stopping before creating ProductPrice rows.');

            return;
        }

        $factoryDoorKavir = FactoryLocation::where(FactoryLocation::FACTORY_ID, $kavirFactory->id)->where(FactoryLocation::TYPE, 'factory_door')->first();
        $isfahanWarehouseKavir = FactoryLocation::where(FactoryLocation::FACTORY_ID, $kavirFactory->id)->where(FactoryLocation::TYPE, 'warehouse')->where(FactoryLocation::CITY, 'اصفهان')->first();
        $tehranWarehouseKavir = FactoryLocation::where(FactoryLocation::FACTORY_ID, $kavirFactory->id)->where(FactoryLocation::TYPE, 'warehouse')->where(FactoryLocation::CITY, 'تهران')->first();
        $shirazWarehouseKavir = FactoryLocation::where(FactoryLocation::FACTORY_ID, $kavirFactory->id)->where(FactoryLocation::TYPE, 'warehouse')->where(FactoryLocation::CITY, 'شیراز')->first();

        if (! $factoryDoorKavir || ! $isfahanWarehouseKavir || ! $tehranWarehouseKavir || ! $shirazWarehouseKavir) {
            $this->command?->warn('ProductSeeder: one or more kavir-steel FactoryLocation rows missing — stopping before creating ProductPrice rows.');

            return;
        }

        $variantA2_12 = ProductVariant::where(ProductVariant::SKU, 'RBR-A2-12-SH')->first();
        $variantA3_16 = ProductVariant::where(ProductVariant::SKU, 'RBR-A3-16-SH')->first();
        $variantA1_8 = ProductVariant::where(ProductVariant::SKU, 'RBR-A1-8-KL')->first();

        if (! $variantA2_12 || ! $variantA3_16 || ! $variantA1_8) {
            $this->command?->warn('ProductSeeder: one or more rebar ProductVariant rows missing — stopping before creating ProductPrice rows.');

            return;
        }

        $variantBasePrices = [
            $variantA2_12->id => ['base_general' => 46500, 'base_special' => 42500],
            $variantA3_16->id => ['base_general' => 54500, 'base_special' => 50500],
            $variantA1_8->id => ['base_general' => 40500, 'base_special' => 36500],
        ];
        $locationPriceAdjustments = [
            $factoryDoorKavir->id => ['general' => -500, 'special' => -500],
            $isfahanWarehouseKavir->id => ['general' => 0,    'special' => 0],
            $tehranWarehouseKavir->id => ['general' => 1000, 'special' => 800],
            $shirazWarehouseKavir->id => ['general' => 1500, 'special' => 1200],
        ];

        foreach ($variantBasePrices as $variantId => $basePrices) {
            foreach ($locationPriceAdjustments as $locationId => $adjustments) {
                $generalPrice = $basePrices['base_general'] + $adjustments['general'];
                $specialPrice = $basePrices['base_special'] + $adjustments['special'];
                ProductPrice::updateOrCreate(
                    [ProductPrice::PRODUCT_VARIANT_ID => $variantId, ProductPrice::FACTORY_ID => $kavirFactory->id, ProductPrice::FACTORY_LOCATION_ID => $locationId],
                    [ProductPrice::GENERAL_PRICE => $generalPrice, ProductPrice::SPECIAL_PRICE => $specialPrice, ProductPrice::GOLDEN_FIGURE => ($generalPrice - $specialPrice), ProductPrice::LAST_UPDATED_AT => now()]
                );
            }
        }

        $this->command?->info(sprintf(
            'ProductSeeder: done — %d rebar variant(s), %d ProductPrice row(s) for kavir-steel.',
            count($variantBasePrices),
            count($variantBasePrices) * count($locationPriceAdjustments)
        ));
    }
}
