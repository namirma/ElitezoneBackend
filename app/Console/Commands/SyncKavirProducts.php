<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Gateways\GatewayFactory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\AttributeValue;
use Illuminate\Support\Facades\DB;
use function count;
use function strlen;

class SyncKavirProducts extends Command
{
    protected $signature = 'products:sync-kavir';
    protected $description = 'Sync products from Kavir Steel with Attribute Values';

    public function handle()
    {
        $this->info('Connecting to Kavir Gateway...');

        try {
            $gateway = GatewayFactory::make('tesna');
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
            // 1. ایجاد دسته‌بندی و محصول والد
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

            // 2. ایجاد "نوع ویژگی" (AttributeType)
            // مثلا: مشخصات فنی
            $techType = AttributeType::firstOrCreate(
                ['name' => 'technical_specs'],
                ['display_name' => 'مشخصات فنی', 'input_type' => 'select']
            );

            // 3. ایجاد "ویژگی‌ها" (Attributes)
            // مدل Attribute ستون‌های name و display_name دارد
            $attrs = [
                'type'    => $this->getOrCreateAttribute($techType->id, 'type', 'نوع محصول'),
                'surface' => $this->getOrCreateAttribute($techType->id, 'surface', 'نوع سطح'),
                'grade'   => $this->getOrCreateAttribute($techType->id, 'grade', 'گرید'),
                'size'    => $this->getOrCreateAttribute($techType->id, 'size', 'سایز (قطر)'),
                'length'  => $this->getOrCreateAttribute($techType->id, 'length', 'طول شاخه'),
                'packing' => $this->getOrCreateAttribute($techType->id, 'packing', 'بسته‌بندی'),
            ];

            $bar = $this->output->createProgressBar(count($items));

            foreach ($items as $item) {
                $code = (string) $item['Store_Code'];
                if (strlen($code) < 14) continue;

                // --- استخراج مقادیر ---

                // نوع محصول
                $typeVal = ($code[0] == '1') ? 'کلاف' : (($code[0] == '2') ? 'شاخه' : 'نامشخص');

                // سطح
                $surfaceVal = ($code[1] == '1') ? 'ساده' : 'آجدار';

                // گرید
                $gradeVal = trim($item['Product_Kind'] ?? substr($code, 2, 3));

                // سایز
                $sizeVal = (string) (int) substr($code, 5, 3);

                // طول
                $lenCm = (int) substr($code, 8, 4);
                $lenVal = ($lenCm == 0) ? '-' : ($lenCm / 100) . ' متر';

                // بسته‌بندی
                $packCode = substr($code, 13, 1);
                $packVal = match ($packCode) {
                    '1', '0' => 'نرمال',
                    '2' => 'استاندارد',
                    default => 'نامشخص'
                };

                // 4. ساخت واریانت
                $variant = ProductVariant::updateOrCreate(
                    ['sku' => $code],
                    [
                        'product_id' => $parentProduct->id,
                        'name' => $item['Sales_Store_Code_Name'],
                        'is_active' => true,
                        'stock_quantity' => 0,
                    ]
                );

                // 5. آماده‌سازی آرایه Sync
                $syncData = [];

                $syncData[$attrs['type']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['type']->id, $typeVal)];
                $syncData[$attrs['surface']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['surface']->id, $surfaceVal)];

                if ($gradeVal) {
                    $syncData[$attrs['grade']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['grade']->id, $gradeVal)];
                }

                $syncData[$attrs['size']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['size']->id, $sizeVal)];

                if ($lenCm > 0) {
                    $syncData[$attrs['length']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['length']->id, $lenVal)];
                }

                $syncData[$attrs['packing']->id] = ['attribute_value_id' => $this->getOrCreateValue($attrs['packing']->id, $packVal)];

                // 6. سینک نهایی
                $variant->variantAttributes()->sync($syncData);

                $bar->advance();
            }
            $bar->finish();
        });

        $this->newLine();
        $this->info('Done! Products synced correctly with attributes and values.');
    }

    /**
     * ایجاد ویژگی (مثل "سایز")
     * مدل Attribute: دارای name, display_name
     */
    private function getOrCreateAttribute($typeId, $name, $label)
    {
        return Attribute::firstOrCreate(
            ['attribute_type_id' => $typeId, 'name' => $name],
            ['display_name' => $label]
        );
    }

    /**
     * ایجاد مقدار ویژگی (مثل "14")
     * مدل AttributeValue: دارای value, display_value (اصلاح شده)
     */
    private function getOrCreateValue($attrId, $value)
    {
        return AttributeValue::firstOrCreate(
            ['attribute_id' => $attrId, 'value' => $value],
            ['display_value' => $value]
        )->id;
    }
}
