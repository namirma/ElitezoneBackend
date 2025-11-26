<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Gateways\GatewayFactory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\Attribute;
use App\Models\AttributeType;
use Illuminate\Support\Facades\DB;
use function count;
use function strlen;

class SyncKavirProducts extends Command
{
    protected $signature = 'products:sync-kavir';
    protected $description = 'Sync products from Kavir Steel (Tesna API) with detailed decoding';

    public function handle()
    {
        $this->info('Connecting to Kavir Gateway...');

        try {
            $gateway = GatewayFactory::make('tesna');
            // فراخوانی سرویس
            $response = $gateway->getRequest('/api/v1/wh/AllStoreCodes');
            $items = $response['items'] ?? [];
        } catch (\Exception $e) {
            $this->error('API Error: ' . $e->getMessage());
            return;
        }

        if (empty($items)) {
            $this->warn('No items found.');
            return;
        }

        $count = count($items);
        $this->info("Found {$count} items. Starting sync...");

        DB::transaction(function () use ($items) {
            // 1. ایجاد محصول والد
            $category = ProductCategory::firstOrCreate(
                ['slug' => 'rebar'],
                ['name' => 'میلگرد', 'is_active' => true]
            );

            $parentProduct = Product::firstOrCreate(
                ['slug' => 'kavir-rebar'],
                [
                    'category_id' => $category->id,
                    'name' => 'میلگرد فولاد کویر',
                    'description' => 'محصولات دریافتی از فولاد کویر کاشان',
                    'sku' => 'KAVIR-MAIN',
                    'is_active' => true
                ]
            );

            // 2. آماده‌سازی انواع ویژگی‌ها (Attribute Types)
            $attrTypes = [
                'type'    => $this->getAttrType('type', 'نوع محصول'),       // کلاف / شاخه
                'surface' => $this->getAttrType('surface', 'نوع سطح'),     // ساده / آجدار
                'grade'   => $this->getAttrType('grade', 'گرید'),          // A3, A4, ...
                'size'    => $this->getAttrType('size', 'سایز (قطر)'),     // 14, 16
                'length'  => $this->getAttrType('length', 'طول شاخه'),     // 12 متر
                'packing' => $this->getAttrType('packing', 'نوع بسته‌بندی'), // نرمال / استاندارد
            ];

            $bar = $this->output->createProgressBar(count($items));

            foreach ($items as $item) {
                $code = (string) $item['Store_Code'];

                if (strlen($code) < 14) continue;


                $typeCode = substr($code, 0, 1);
                $typeName = ($typeCode == '1') ? 'کلاف' : (($typeCode == '2') ? 'شاخه' : 'نامشخص');

                $surfaceCode = substr($code, 1, 1);
                $surfaceName = ($surfaceCode == '1') ? 'ساده' : 'آجدار';

                $gradeName = $item['Product_Kind'] ?? substr($code, 2, 3);

                $sizeVal = (int) substr($code, 5, 3);
                $sizeName = (string) $sizeVal;

                $lengthCm = (int) substr($code, 8, 4);
                $lengthName = ($lengthCm == 0) ? '-' : ($lengthCm / 100) . ' متر';

                $packCode = substr($code, 13, 1);
                $packName = match ($packCode) {
                    '1', '0' => 'نرمال (طول متغیر)',
                    '2'      => 'استاندارد (طول ثابت)',
                    default  => 'نامشخص'
                };

                // 3. ساخت واریانت
                $variant = ProductVariant::updateOrCreate(
                    ['sku' => $code],
                    [
                        'product_id' => $parentProduct->id,
                        'name' => $item['Sales_Store_Code_Name'],
                        'is_active' => true,
                        'stock_quantity' => 0,
                    ]
                );

                $attrIds = [];
                $attrIds[] = $this->createAttr($attrTypes['type'], $typeName);
                $attrIds[] = $this->createAttr($attrTypes['surface'], $surfaceName);

                if ($gradeName) $attrIds[] = $this->createAttr($attrTypes['grade'], $gradeName);

                $attrIds[] = $this->createAttr($attrTypes['size'], $sizeName);

                if ($lengthCm > 0) $attrIds[] = $this->createAttr($attrTypes['length'], $lengthName);

                $attrIds[] = $this->createAttr($attrTypes['packing'], $packName);

                $variant->variantAttributes()->sync($attrIds);

                $bar->advance();
            }
        });

        $this->newLine();
        $this->info('Done! All products synced with detailed attributes.');
    }

    /**
     * متد کمکی برای گرفتن یا ساختن نوع ویژگی
     */
    private function getAttrType($name, $displayName)
    {
        return AttributeType::firstOrCreate(
            ['name' => $name],
            ['display_name' => $displayName, 'input_type' => 'select']
        )->id;
    }

    /**
     * متد کمکی برای گرفتن یا ساختن مقدار ویژگی
     */
    private function createAttr($typeId, $value)
    {
        return Attribute::firstOrCreate(
            ['attribute_type_id' => $typeId, 'name' => $value],
            ['display_name' => $value]
        )->id;
    }
}
