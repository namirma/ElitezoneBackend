<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeType;
use App\Models\AttributeValue;
use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttribute;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * کاتالوگ نمایشی برای استیجینگ — تا تیم تولید سایتِ پر ببیند.
 *
 * ⚠️ **چرا `ProductSeeder` کافی نبود.** آن سیدر محصول و قیمت می‌سازد ولی
 * `factories.supplier_user_id` را خالی می‌گذارد. قاعده‌ی نمایش در
 * `FilterProductsAction::hideProductsWithExpiredPrices()` می‌گوید:
 *
 *   محصولی که **ردیف قیمت دارد** فقط وقتی دیده می‌شود که کارخانه‌ی آن قیمت،
 *   تأمین‌کننده‌ای با `supplier_profiles.reserve_time` **در آینده** داشته باشد.
 *
 * پس با داده‌ی فعلی، تنها محصولی که قیمت داشت از کاتالوگ **حذف** می‌شد و آنکه
 * می‌ماند نه قیمت داشت نه عکس. سایت خالی به نظر می‌رسید بی‌آنکه هیچ خطایی
 * بدهد — نه ۵۰۰، نه لاگی، فقط یک آرایه‌ی خالی.
 *
 * این سیدر آن حلقه را می‌بندد: تأمین‌کننده‌ی نمایشی می‌سازد، کارخانه‌ها را به
 * او وصل می‌کند، `reserve_time` را زنده نگه می‌دارد، و کاتالوگ را در هر چهار
 * دسته پر می‌کند.
 *
 * `updateOrCreate` در همه‌جا: اجرای دوباره چیزی را دوبرابر نمی‌کند.
 *
 * ⚠️ **`reserve_time` تاریخ انقضا دارد.** با گذشتنش کاتالوگ دوباره خالی
 * می‌شود. یک سال جلو تنظیم شده و اجرای دوباره‌ی همین سیدر تمدیدش می‌کند.
 *
 * اجرا:  php artisan db:seed --class=StagingCatalogSeeder
 */
class StagingCatalogSeeder extends Seeder
{
    /** موبایلِ تأمین‌کننده‌ی نمایشی — کلید idempotency این سیدر. */
    private const DEMO_SUPPLIER_MOBILE = '09120000001';

    /**
     * گروهِ کلانی که سایدبار زیرِ آن ساخته می‌شود.
     *
     * نامش دلبخواه نیست — داده‌ی واقعیِ کاتالوگ (سینکِ کویر) از قبل زیرِ همین
     * `technical_specs` نشسته است. هر نامِ دیگری یک گروهِ رقیب می‌ساخت و
     * سایدبار دو «گرید» نشان می‌داد.
     */
    private const ATTRIBUTE_TYPE = ['name' => 'technical_specs', 'label' => 'مشخصات فنی'];

    /**
     * واژگانِ ویژگی‌ها — گروهِ سایدبار ← گزینه‌ها.
     *
     * ‏**سه سطح داریم و نقش‌شان قابلِ جابه‌جایی نیست.** نگاشتِ درست — که هم
     * داده‌ی واقعیِ کاتالوگ و هم فرانت از آن پیروی می‌کنند — این است:
     *
     *   `attribute_types`  → گروهِ کلان («مشخصات فنی») — سایدبار نادیده می‌گیردش
     *   `attributes`       → **سرتیترِ گروه در سایدبار** («گرید»)
     *   `attribute_values` → **گزینه‌ی قابلِ تیک‌زدن** («A3») و مقدارِ چیپِ واریانت
     *
     * ‏`ProductSeeder` گزینه‌ها را یک سطح بالاتر (روی `attributes`) می‌سازد —
     * آن شکلِ متروکی است که هیچ واریانتی به آن وصل نیست. دنبال‌کردنش باعث شد
     * سایدبارِ استیجینگ به‌جای گروهِ «نوع محصول» با دو گزینه، دو گروهِ جدا به
     * نامِ «شاخه» و «کلاف» نشان بدهد که هرکدام فقط خودشان را داشتند.
     *
     * نام‌ها عمداً **عینِ داده‌ی واقعی** است تا `updateOrCreate` همان ردیف‌ها را
     * بازاستفاده کند، نه اینکه کنارشان ردیفِ تازه بسازد. به همین دلیل «سایز
     * (قطر)» است و نه «سایز» — برای آجر و شیرفلکه «قطر» دقیق نیست، ولی داشتنِ
     * دو گروهِ سایزِ موازی در سایدبار بدتر است.
     *
     * ارقام هم لاتین می‌مانند («۱۲ متر» نه): داده‌ی واقعی «12 متر» دارد و شکلِ
     * فارسی یک ردیفِ مقدارِ دومِ تقریباً یکسان می‌ساخت.
     *
     * ردیفِ `attribute_values` اختیاری نیست:
     * ‏`product_variant_attributes.attribute_value_id` ستونِ NOT NULL است، پس
     * بدون آن اصلاً نمی‌شود ویژگی را به واریانت وصل کرد.
     */
    private const VOCABULARY = [
        'نوع محصول' => ['شاخه', 'کلاف', 'IPE', 'نبشی', 'ورق', 'سفال', 'نسوز', 'کشویی', 'پروانه‌ای'],
        'نوع سطح' => ['ساده', 'آجدار'],
        'گرید' => ['A1', 'A2', 'A3', 'A4', 'ST37'],
        'سایز (قطر)' => [
            '2', '3', '8', '10', '12', '14', '16', '18', '50', '60',
            '10×20×20', '15×20×20', '22×11×5.5', '2 اینچ', '3 اینچ', '4 اینچ',
        ],
        'طول شاخه' => ['6 متر', '12 متر'],
        'بسته‌بندی' => ['نرمال', 'بندیل', 'پالت'],
    ];

    public function run(): void
    {
        $supplier = $this->demoSupplier();
        $factories = $this->attachFactoriesTo($supplier);

        if ($factories->isEmpty()) {
            $this->command?->error('StagingCatalogSeeder: no factories found — run ProductSeeder first.');

            return;
        }

        $this->ensurePlaceholderImages();
        $this->ensureAttributeVocabulary();

        $created = 0;
        foreach ($this->catalogue() as $slug => $spec) {
            $created += $this->seedProduct($slug, $spec, $factories) ? 1 : 0;
        }

        $this->command?->info(sprintf(
            'StagingCatalogSeeder: supplier #%d live until %s · %d product(s) · %d price row(s).',
            $supplier->id,
            $supplier->supplier?->{SupplierProfile::RESERVE_TIME},
            $created,
            ProductPrice::count(),
        ));
    }

    /**
     * تأمین‌کننده‌ی نمایشی با پروفایلِ تأییدشده و پنجره‌ی قیمتِ زنده.
     *
     * `reserve_time` قلبِ ماجراست — بدونش کلِ کاتالوگ نامرئی می‌ماند.
     */
    private function demoSupplier(): User
    {
        $user = User::updateOrCreate(
            [User::MOBILE => self::DEMO_SUPPLIER_MOBILE],
            [User::IS_ACTIVE => true],
        );

        SupplierProfile::updateOrCreate(
            [SupplierProfile::USER_ID => $user->id],
            [
                SupplierProfile::COMPANY => 'تأمین‌کننده‌ی نمایشی الیت‌زون',
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                // یک سال جلو: استیجینگ نباید هفته‌ی بعد بی‌صدا خالی شود.
                SupplierProfile::RESERVE_TIME => now()->addYear(),
                SupplierProfile::TRANS_COMMISSION => true,
                SupplierProfile::TRANS_VAT => true,
            ],
        );

        // نقش برای کاتالوگ لازم نیست (گاردِ نمایش فقط `reserve_time` را
        // می‌بیند) ولی بدون آن پنل تأمین‌کننده روی همین کاربر باز نمی‌شود.
        //
        // وجودِ ردیفِ نقش چک می‌شود چون `addRole` روی دیتابیسی که
        // `UserTypeSeeder` رویش نرفته، با `ModelNotFoundException` می‌میرد و
        // کلِ کاتالوگ را با پیامی که هیچ ربطی به کاتالوگ ندارد می‌خواباند.
        if (Role::where('name', 'supplier')->exists()) {
            if (! $user->hasRole('supplier')) {
                $user->addRole('supplier');
            }
        } else {
            $this->command?->warn('StagingCatalogSeeder: role "supplier" missing — run UserTypeSeeder to enable the supplier panel.');
        }

        return $user->refresh();
    }

    /** کارخانه‌های موجود را به تأمین‌کننده‌ی نمایشی گره می‌زند. */
    private function attachFactoriesTo(User $supplier)
    {
        Factory::query()->whereNull('supplier_user_id')->update(['supplier_user_id' => $supplier->id]);

        return Factory::query()->with('locations')->get();
    }

    /**
     * تصویرِ جانشین per-دسته.
     *
     * PNG و نه SVG — قاعده‌ی سراسری پروژه SVG را روی دیسکِ public ممنوع کرده
     * (XSS ذخیره‌شده). بدون فایلِ واقعی، هر کارتِ محصول یک ۴۰۴ می‌داد.
     */
    private function ensurePlaceholderImages(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->command?->warn('StagingCatalogSeeder: ext-gd missing — skipping placeholder images.');

            return;
        }

        $palette = [
            'rebar' => [0x37, 0x4151],
            'steel' => [0x1F, 0x2937],
            'brick' => [0x9A, 0x3412],
            'taps' => [0x0F, 0x766E],
        ];

        foreach ($palette as $slug => [$r, $rest]) {
            $path = "products/placeholder-{$slug}.png";

            if (Storage::disk('public')->exists($path)) {
                continue;
            }

            $img = imagecreatetruecolor(800, 600);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, ($rest >> 8) & 0xFF, $rest & 0xFF));

            ob_start();
            imagepng($img);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            Storage::disk('public')->put($path, $bytes);
        }
    }

    /**
     * کاتالوگ در هر چهار دسته.
     *
     * قیمت‌ها ریال بر کیلوگرم‌اند — مقیاسِ همان اعدادی که `ProductSeeder`
     * برای میلگرد گذاشته بود، تا ارقامِ سبد خرید واقع‌نما بمانند.
     *
     * @return array<string, array{category: string, name: string, variants: array<int, array{name: string, sku: string, stock: int, general: int}>}>
     */
    private function catalogue(): array
    {
        return [
            'rebar-plain-a1' => ['desc' => 'میلگرد ساده A1 با سطح صاف، مناسب خاموت‌زنی و کارهای بتنی سبک. تحویل از درب کارخانه یا انبارهای اصفهان، تهران و شیراز.', 'category' => 'rebar', 'name' => 'میلگرد ساده A1', 'variants' => [
                // کلاف طولِ شاخه ندارد — عمداً بدون `length`. یک ویژگیِ بی‌معنا
                // بدتر از نبودنش است: در سایدبار گزینه‌ای می‌سازد که هیچ‌چیزِ
                // درستی را فیلتر نمی‌کند.
                ['name' => 'سایز ۸ کلاف', 'sku' => 'DEMO-RBP-A1-08', 'stock' => 180_000, 'general' => 41_500, 'attrs' => ['نوع محصول' => 'کلاف', 'نوع سطح' => 'ساده', 'گرید' => 'A1', 'سایز (قطر)' => '8', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'سایز ۱۰ کلاف', 'sku' => 'DEMO-RBP-A1-10', 'stock' => 220_000, 'general' => 41_000, 'attrs' => ['نوع محصول' => 'کلاف', 'نوع سطح' => 'ساده', 'گرید' => 'A1', 'سایز (قطر)' => '10', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'سایز ۱۲ شاخه', 'sku' => 'DEMO-RBP-A1-12', 'stock' => 160_000, 'general' => 42_000, 'attrs' => ['نوع محصول' => 'شاخه', 'نوع سطح' => 'ساده', 'گرید' => 'A1', 'سایز (قطر)' => '12', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'بندیل']],
            ]],
            'beam-ipe' => ['desc' => 'تیرآهن IPE با مقطع I، پرکاربردترین پروفیل باربر در اسکلت فلزی ساختمان. مطابق استاندارد ملی ایران.', 'category' => 'steel', 'name' => 'تیرآهن IPE', 'variants' => [
                ['name' => 'IPE 14', 'sku' => 'DEMO-IPE-14', 'stock' => 90_000, 'general' => 52_000, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '14', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'IPE 16', 'sku' => 'DEMO-IPE-16', 'stock' => 85_000, 'general' => 51_500, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '16', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'IPE 18', 'sku' => 'DEMO-IPE-18', 'stock' => 70_000, 'general' => 51_000, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '18', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
            ]],
            'angle-bar' => ['desc' => 'نبشی فولادی نورد گرم برای بادبند، اتصالات و قاب‌بندی. ابعاد متنوع با ضخامت‌های استاندارد.', 'category' => 'steel', 'name' => 'نبشی فولادی', 'variants' => [
                ['name' => 'نبشی ۵×۵', 'sku' => 'DEMO-ANG-50', 'stock' => 120_000, 'general' => 48_500, 'attrs' => ['نوع محصول' => 'نبشی', 'گرید' => 'ST37', 'سایز (قطر)' => '50', 'طول شاخه' => '6 متر', 'بسته‌بندی' => 'بندیل']],
                ['name' => 'نبشی ۶×۶', 'sku' => 'DEMO-ANG-60', 'stock' => 110_000, 'general' => 48_000, 'attrs' => ['نوع محصول' => 'نبشی', 'گرید' => 'ST37', 'سایز (قطر)' => '60', 'طول شاخه' => '6 متر', 'بسته‌بندی' => 'بندیل']],
            ]],
            'steel-sheet' => ['desc' => 'ورق سیاه نورد گرم، مناسب مخزن‌سازی، سازه‌های صنعتی و ورق‌کاری ساختمانی.', 'category' => 'steel', 'name' => 'ورق سیاه', 'variants' => [
                ['name' => 'ضخامت ۲ میل', 'sku' => 'DEMO-SHT-02', 'stock' => 140_000, 'general' => 56_000, 'attrs' => ['نوع محصول' => 'ورق', 'گرید' => 'ST37', 'سایز (قطر)' => '2', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'ضخامت ۳ میل', 'sku' => 'DEMO-SHT-03', 'stock' => 130_000, 'general' => 55_500, 'attrs' => ['نوع محصول' => 'ورق', 'گرید' => 'ST37', 'سایز (قطر)' => '3', 'بسته‌بندی' => 'نرمال']],
            ]],
            'brick-sofal' => ['desc' => 'آجر سفال تیغه‌ای با وزن سبک و عایق حرارتی مناسب، برای دیوارچینی داخلی و پرکننده.', 'category' => 'brick', 'name' => 'آجر سفال', 'variants' => [
                ['name' => 'سفال ۱۰×۲۰×۲۰', 'sku' => 'DEMO-BRK-SF1', 'stock' => 400_000, 'general' => 12_500, 'attrs' => ['نوع محصول' => 'سفال', 'سایز (قطر)' => '10×20×20', 'بسته‌بندی' => 'پالت']],
                ['name' => 'سفال ۱۵×۲۰×۲۰', 'sku' => 'DEMO-BRK-SF2', 'stock' => 350_000, 'general' => 13_500, 'attrs' => ['نوع محصول' => 'سفال', 'سایز (قطر)' => '15×20×20', 'بسته‌بندی' => 'پالت']],
            ]],
            'brick-fire' => ['desc' => 'آجر نسوز نما با مقاومت حرارتی بالا، مناسب نماکاری، شومینه و کوره.', 'category' => 'brick', 'name' => 'آجر نسوز', 'variants' => [
                ['name' => 'نسوز نما قرمز', 'sku' => 'DEMO-BRK-FR1', 'stock' => 200_000, 'general' => 21_000, 'attrs' => ['نوع محصول' => 'نسوز', 'سایز (قطر)' => '22×11×5.5', 'بسته‌بندی' => 'پالت']],
            ]],
            'valve-gate' => ['desc' => 'شیر فلکه کشویی چدنی با آب‌بندی کامل، مناسب خطوط انتقال آب و تأسیسات ساختمانی.', 'category' => 'taps', 'name' => 'شیر فلکه کشویی', 'variants' => [
                ['name' => 'سایز ۲ اینچ', 'sku' => 'DEMO-VLV-GT2', 'stock' => 4_000, 'general' => 148_000, 'attrs' => ['نوع محصول' => 'کشویی', 'سایز (قطر)' => '2 اینچ', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'سایز ۳ اینچ', 'sku' => 'DEMO-VLV-GT3', 'stock' => 3_200, 'general' => 186_000, 'attrs' => ['نوع محصول' => 'کشویی', 'سایز (قطر)' => '3 اینچ', 'بسته‌بندی' => 'نرمال']],
            ]],
            'valve-butterfly' => ['desc' => 'شیر پروانه‌ای ویفری با اهرم دستی، سبک و کم‌فضا برای خطوط لوله با قطر بالا.', 'category' => 'taps', 'name' => 'شیر پروانه‌ای', 'variants' => [
                ['name' => 'سایز ۴ اینچ', 'sku' => 'DEMO-VLV-BF4', 'stock' => 2_500, 'general' => 232_000, 'attrs' => ['نوع محصول' => 'پروانه‌ای', 'سایز (قطر)' => '4 اینچ', 'بسته‌بندی' => 'نرمال']],
            ]],
        ];
    }

    /**
     * واحدهای سفارش — بدون این، صفحه‌ی محصول می‌گوید «واحد سفارشی برای این
     * محصول تعریف نشده است و فعلاً قابل خرید نیست» و دکمه‌ی خرید کار نمی‌کند.
     *
     * نسخه‌ی اولِ این سیدر همین را جا انداخته بود: محصول، قیمت، عکس و موجودی
     * همه درست بودند و صفحه کامل به نظر می‌رسید، ولی جدولِ `product_order_units`
     * خالی بود. کاتالوگ دیده می‌شد و **هیچ‌کدام قابل خرید نبودند**.
     *
     * واحدها per-دسته‌اند چون منطقِ فروش فرق دارد: فولاد با ظرفیت کامیون و
     * بندیل هم فروخته می‌شود، شیرآلات فقط تکی.
     */
    private function attachOrderUnits(Product $product, string $categorySlug): void
    {
        $codes = match ($categorySlug) {
            'rebar', 'steel' => ['capacity' => 5, 'bundle' => 30, 'retail' => 2_000_000],
            'brick' => ['bundle' => 40, 'retail' => 500_000],
            default => ['retail' => 5_000],
        };

        foreach ($codes as $code => $maxQuantity) {
            $unit = OrderUnit::where(OrderUnit::CODE, $code)->first();

            if ($unit === null) {
                $this->command?->warn("StagingCatalogSeeder: OrderUnit '{$code}' missing — run ProductSeeder first.");

                continue;
            }

            $product->orderUnits()->syncWithoutDetaching([$unit->id => ['max_quantity' => $maxQuantity]]);
        }
    }

    /**
     * کف و سقفِ مقدار سفارش per-دسته.
     *
     * فولاد بر حسب کیلوگرم است و کمتر از دو تن عملاً حمل نمی‌شود؛ شیرآلات تکی
     * است و کفِ ۲۰۰۰ روی آن بی‌معنا می‌شد.
     *
     * @return array{0: int, 1: int}
     */
    private function orderBounds(string $categorySlug): array
    {
        return match ($categorySlug) {
            'rebar', 'steel' => [2_000, 200_000],
            'brick' => [1_000, 500_000],
            default => [1, 5_000],
        };
    }

    /** یک محصول با واریانت، قیمتِ هر انبار، موجودی و تصویر. */
    /**
     * گروه‌ها، گزینه‌ها و ردیفِ مقدارِ هر گزینه را می‌سازد.
     *
     * `ProductSeeder` گزینه‌های `type`/`grade`/`size` را می‌سازد ولی **ردیفِ
     * مقدار نمی‌سازد** — یعنی همان گزینه‌ها قابلِ وصل‌شدن به هیچ واریانتی
     * نیستند. این متد آن شکاف را هم پر می‌کند، نه فقط برای گزینه‌های تازه.
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
     * `sync` و نه `syncWithoutDetaching`: نقشه‌ی این سیدر مرجع است، پس اگر
     * گزینه‌ای از نقشه حذف شد باید از دیتابیس هم برود.
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

            // مقدارِ **همین** گزینه، نه اولین مقدارِ گروه: هر گروه حالا چند
            // گزینه دارد، پس `first()` بی‌قید یعنی همه‌ی واریانت‌ها یک مقدار
            // بگیرند.
            $value = $attribute === null
                ? null
                : AttributeValue::where(AttributeValue::ATTRIBUTE_ID, $attribute->id)
                    ->where(AttributeValue::VALUE, $option)
                    ->first();

            // نبودِ گزینه یا مقدارش یعنی نقشه و `VOCABULARY` از هم پاشیده‌اند.
            // رد شدن با هشدار، چون خرابیِ یک ویژگی نباید کلِ کاتالوگ را بخواباند.
            if ($value === null) {
                $this->command?->warn(
                    "StagingCatalogSeeder: option {$group}/{$option} is not in VOCABULARY — skipped."
                );

                continue;
            }

            $pivot[$attribute->id] = [ProductVariantAttribute::ATTRIBUTE_VALUE_ID => $value->id];
        }

        $variant->variantAttributes()->sync($pivot);
    }

    private function seedProduct(string $slug, array $spec, $factories): bool
    {
        $category = ProductCategory::where(ProductCategory::SLUG, $spec['category'])->first();

        if (! $category) {
            $this->command?->warn("StagingCatalogSeeder: category {$spec['category']} missing — skipping {$slug}.");

            return false;
        }

        $product = Product::updateOrCreate(
            [Product::SLUG => $slug],
            [
                Product::CATEGORY_ID => $category->id,
                Product::NAME => $spec['name'],
                Product::DESCRIPTION => $spec['desc'] ?? null,
                Product::SKU => strtoupper($slug),
                Product::IS_ACTIVE => true,
            ],
        );

        $this->attachOrderUnits($product, $spec['category']);

        ProductImage::updateOrCreate(
            [ProductImage::PRODUCT_ID => $product->id],
            [ProductImage::IMAGE_PATH => "products/placeholder-{$spec['category']}.png"],
        );

        foreach ($spec['variants'] as $v) {
            $variant = ProductVariant::updateOrCreate(
                [ProductVariant::SKU => $v['sku']],
                [
                    ProductVariant::PRODUCT_ID => $product->id,
                    ProductVariant::NAME => $v['name'],
                    ProductVariant::IS_ACTIVE => true,
                    ProductVariant::STOCK_QUANTITY => $v['stock'],
                    // بدون کف/سقف، فرم سفارش مقدار مجاز را نمی‌داند.
                    ProductVariant::MIN_ORDER_VALUE => $this->orderBounds($spec['category'])[0],
                    ProductVariant::MAX_ORDER_VALUE => $this->orderBounds($spec['category'])[1],
                ],
            );

            $this->attachAttributes($variant, $v['attrs'] ?? []);
            $this->priceVariantEverywhere($variant, $v['general'], $factories);
        }

        return true;
    }

    /**
     * قیمت و موجودی برای هر (کارخانه، انبار).
     *
     * انبارِ دور گران‌تر است تا اثرِ مبدأ روی قیمت در فرانت دیده شود — همان
     * الگویی که `ProductSeeder` برای میلگرد داشت.
     */
    private function priceVariantEverywhere(ProductVariant $variant, int $general, $factories): void
    {
        foreach ($factories as $factory) {
            foreach ($factory->locations as $i => $location) {
                /** @var FactoryLocation $location */
                $bump = $location->{FactoryLocation::TYPE} === 'factory_door' ? -500 : ($i * 400);
                $generalPrice = $general + $bump;
                $specialPrice = (int) round($generalPrice * 0.94);

                ProductPrice::updateOrCreate(
                    [
                        ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
                        ProductPrice::FACTORY_ID => $factory->id,
                        ProductPrice::FACTORY_LOCATION_ID => $location->id,
                    ],
                    [
                        ProductPrice::GENERAL_PRICE => $generalPrice,
                        ProductPrice::SPECIAL_PRICE => $specialPrice,
                        ProductPrice::GOLDEN_FIGURE => $generalPrice - $specialPrice,
                        ProductPrice::LAST_UPDATED_AT => now(),
                    ],
                );

                FactoryStock::updateOrCreate(
                    [
                        'product_variant_id' => $variant->id,
                        'factory_id' => $factory->id,
                        'factory_location_id' => $location->id,
                    ],
                    ['quantity' => $variant->{ProductVariant::STOCK_QUANTITY}],
                );
            }
        }
    }
}
